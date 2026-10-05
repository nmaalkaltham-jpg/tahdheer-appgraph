<?php

namespace App\Domain\Orders;

use App\Domain\Entitlements\EntitlementService;
use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Inventory\InventoryService;
use App\Domain\Orders\Models\Refund;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\ProviderRefundRejected;
use App\Domain\Payments\ProviderRegistry;
use App\Domain\Payments\ProviderUnavailable;
use App\Domain\Payments\RefundOutcomeUnknown;
use App\Domain\Payments\RefundResult;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * الاسترداد: حجزٌ محلّيّ ← تنفيذٌ عند المزوّد ← حسم، ولكلّ انقطاعٍ حالة.
 *
 * ─── الثابت الذي يحرسه (§٧٠) ───
 *
 *   مجموع المستردّ (المحسوم **والمحجوز**) ≤ المحصَّل — دائمًا، ومن أيّ مستدعٍ.
 *
 * ─── العيب الذي يزيله ───
 *
 * كان الصفّ يُكتب محلّيًّا ويُعلَّم الطلب «مستردًّا» ولا يُطلب من البوّابة
 * شيء: الدفتر يقول «رُدّ المال» والزبون لم يستلم هللة. والآن لا يقع أثرٌ —
 * لا سحبُ استحقاقٍ ولا حدثٌ ولا حالةُ طلب — قبل أن يؤكّد المزوّد خروج المال.
 *
 * ─── الترتيب الملزم ───
 *
 *   ١) اقفل الطلب · تحقّق من السقف · أدرج الصفّ `PENDING` · احجز المبلغ · التزم.
 *   ٢) نادِ المزوّد بمفتاح خمولٍ هو هويّة الصفّ (`refund:{id}`).
 *   ٣) احسم: `SUCCEEDED` فالآثار، أو `FAILED` فالإفراج، أو `UNKNOWN` فالحجز يبقى.
 *
 * الحجز في (١) قبل النداء في (٢) لا بعده: لو مات العامل بين النداء والكتابة
 * لَخرج مالٌ لا يعرفه الدفتر ويُردّ ثانيةً. والحجز المسبق يجعل الغموض آمنًا:
 * أسوأ ما يقع أنّ مبلغًا محجوزًا لم يخرج بعد — ويُحسم بإعادة السؤال بالمفتاح
 * نفسه، لا بمحاولةٍ جديدة.
 *
 * ─── المحجوز غير المحسوم ───
 *
 * `refunded_total_minor` ما خرج فعلًا (وعليه حالة الطلب والتقارير)،
 * و`refund_reserved_minor` ما ينتظر جواب المزوّد. السقف على مجموعهما — في
 * الشيفرة وفي قيد المحرّك — فلا يُقال للزبون «رُدّ مالك» عن نداءٍ لم يُجَب
 * بعد، ولا يُردّ فوقه مالٌ ثانٍ.
 */
class RefundService
{
    /** الحالات التي وصل فيها المال — لا الحالات التي لم يُسلَّم فيها بعد. */
    private const CAPTURED_STATUSES = OrderStatus::EVER_COLLECTED;

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly OutboxWriter $outbox,
        private readonly FulfillmentOrchestrator $fulfillment,
        private readonly InventoryService $inventory,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * @param  string|null  $idempotencyKey  مفتاح العميل؛ إعادة الإرسال به لا تُنشئ استردادًا ثانيًا
     *
     * @throws RefundExceedsCaptured
     * @throws RefundFailed رفضٌ حاسم من المزوّد — الحجز أُفرج عنه
     * @throws RefundOutcomeUnknown لا ردّ حاسم — الحجز باقٍ، أعد النداء بالمفتاح نفسه
     */
    public function refund(
        Order $order,
        int $amountMinor,
        ?string $reason,
        RevocationPolicy $policy,
        ?string $idempotencyKey = null,
    ): Refund {
        /*
         * الغامض يُحسم قبل أيّ جديد. استردادٌ لم يُجَب عنه قد يكون أخرج
         * المال، فطبقةٌ فوقه تُخاطر بردٍّ مضاعف. يُعاد سؤالُ المزوّد عنه
         * بمفتاحه؛ فإن أجاب مضى الجديد، وإن صمت وقف الجديد معه.
         */
        foreach ($this->unresolvedFor($order, $idempotencyKey) as $open) {
            // المفتوح كلُّه يُسأل عنه أوّلًا: الغامض يُعاد بمفتاحه، والمعلَّق يُستعلم.
            $this->resolveOpen($open);
        }

        // وما بقي غامضًا بعد السؤال يوقف الجديد: قد يكون المال خرج.
        $stillUnknown = Refund::query()->where('order_id', $order->id)->where('status', 'UNKNOWN')
            ->when($idempotencyKey !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('idempotency_key')->orWhere('idempotency_key', '!=', $idempotencyKey)))
            ->exists();
        if ($stillUnknown) {
            throw new RefundOutcomeUnknown('A previous refund on this order is still unresolved at the provider; no new refund until it is settled.');
        }

        try {
            [$refund, $existing] = DB::transaction(
                fn () => $this->reserve($order, $amountMinor, $reason, $policy, $idempotencyKey),
            );
        } catch (UniqueConstraintViolationException $e) {
            // طلبان متزامنان بالمفتاح نفسه: الثاني يجد صفّ الأوّل ويعود به.
            $winner = $idempotencyKey === null ? null : Refund::query()
                ->where('order_id', $order->id)->where('idempotency_key', $idempotencyKey)->first();

            if ($winner === null) {
                throw $e;
            }

            [$refund, $existing] = [$winner, true];
        }

        if ($existing) {
            $this->assertSameRequest($refund, $amountMinor, $policy);
        }

        if ($existing && ! in_array($refund->status, Refund::OPEN, true)) {
            // ردٌّ ضاع في الشبكة — لا يخرج المال مرّتين لأجله.
            return $refund;
        }

        if ($refund->status === 'MANUAL') {
            return $refund;
        }

        $this->execute($refund);

        return $refund->fresh();
    }

    /**
     * يحسم استردادًا بما أبلغ به المزوّد في خطّافه.
     *
     * @return 'applied'|'duplicate'|'unmatched'|'ignored'
     */
    public function settleFromProvider(
        string $provider,
        string $providerRefundId,
        string $providerStatus,
        ?string $chargeId = null,
        ?int $amountMinor = null,
        ?string $currency = null,
    ): string {
        $refund = Refund::query()
            ->where('provider', $provider)
            ->where('provider_refund_id', $providerRefundId)
            ->first();

        if ($refund === null) {
            return mb_strtoupper($providerStatus) === 'REFUNDED'
                ? $this->recordProviderRefund($provider, $providerRefundId, $chargeId, $amountMinor, $currency)
                : 'unmatched';
        }

        $status = mb_strtoupper($providerStatus);

        if ($status === 'REFUNDED') {
            if ($refund->status === 'SUCCEEDED') {
                return 'duplicate';
            }

            $this->markSucceeded($refund, $providerRefundId);

            return 'applied';
        }

        if (in_array($status, ['FAILED', 'CANCELLED', 'DECLINED'], true)) {
            if ($refund->status === 'FAILED') {
                return 'duplicate';
            }

            if ($refund->status === 'SUCCEEDED') {
                /*
                 * المزوّد يناقض نفسه: قال «رُدّ» ثمّ «فشل». لا يُتراجع عن
                 * أثرٍ وقع بناءً على تأكيده الأوّل؛ يُسجَّل ليُراجَع.
                 */
                Log::error('payments.refund.provider_contradiction', [
                    'refundId' => $refund->id, 'providerRefundId' => $providerRefundId, 'status' => $status,
                ]);
                $refund->update(['provider_error' => 'Provider reported '.$status.' after REFUNDED.']);

                return 'ignored';
            }

            $this->markFailed($refund, 'Provider reported '.$status.'.');

            return 'applied';
        }

        // PENDING / IN_PROGRESS وأمثالها: لا حسم بعد.
        return 'ignored';
    }

    /**
     * يحسم استردادًا مفتوحًا بسؤال المزوّد — بلا إنشاء استردادٍ جديد أبدًا.
     *
     *   UNKNOWN، أو PENDING بلا هويّةٍ عند المزوّد (مات العامل بين الحجز
     *   والنداء): يُعاد النداء **بالمفتاح نفسه** — المزوّد يُعيد الأوّل.
     *   PENDING بهويّة: يُسأل المزوّد عن حالته.
     *
     * @return string الحالة بعد المحاولة
     */
    public function resolveOpen(Refund $refund): string
    {
        $refund->refresh();

        if (! in_array($refund->status, Refund::OPEN, true)) {
            return $refund->status;
        }

        if ($refund->status === 'PENDING' && $refund->provider_refund_id !== null) {
            $adapter = $this->providers->resolve((string) $refund->provider);
            if ($adapter === null) {
                return $refund->status;
            }

            try {
                $result = $adapter->retrieveRefund((string) $refund->provider_refund_id);
            } catch (ProviderUnavailable $e) {
                Log::warning('payments.refund.requery_unavailable', ['refundId' => $refund->id, 'error' => $e->getMessage()]);

                return $refund->status;
            }

            match ($result->status) {
                RefundResult::SUCCEEDED => $this->markSucceeded($refund, $result->providerRefundId),
                RefundResult::FAILED => $this->markFailed($refund, 'Provider reported '.($result->message ?? 'FAILED').' on re-query.'),
                default => null,
            };

            return $refund->fresh()->status;
        }

        try {
            $this->execute($refund);
        } catch (RefundFailed|RefundOutcomeUnknown) {
            // الحالة مكتوبة على الصفّ؛ المستدعي يقرؤها.
        }

        return $refund->fresh()->status;
    }

    /**
     * @return list<Refund>
     */
    private function unresolvedFor(Order $order, ?string $idempotencyKey): array
    {
        return Refund::query()
            ->where('order_id', $order->id)
            ->whereIn('status', Refund::OPEN)
            ->when($idempotencyKey !== null, fn ($q) => $q->where(
                fn ($q) => $q->whereNull('idempotency_key')->orWhere('idempotency_key', '!=', $idempotencyKey),
            ))
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * تحت قفل الطلب: السقف، الصفّ، الحجز.
     *
     * @return array{0: Refund, 1: bool} الصفّ، وهل كان قائمًا بالمفتاح نفسه
     */
    private function reserve(Order $order, int $amountMinor, ?string $reason, RevocationPolicy $policy, ?string $idempotencyKey): array
    {
        /** @var Order $fresh */
        $fresh = Order::query()->lockForUpdate()->findOrFail($order->id);

        if ($idempotencyKey !== null) {
            $existing = Refund::query()
                ->where('order_id', $fresh->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return [$existing, true];
            }
        }

        $captured = $this->capturedMinor($fresh);
        $already = (int) $fresh->refunded_total_minor + (int) $fresh->refund_reserved_minor;

        if ($amountMinor <= 0 || $already + $amountMinor > $captured) {
            throw new RefundExceedsCaptured($captured, $already, $amountMinor);
        }

        /*
         * السقف يُفرض في **الكتابة نفسها** لا في القراءة التي سبقتها.
         *
         * «اقرأ المتبقّي ثمّ قرّر ثمّ اكتب» صحيحٌ تحت القفل وحده؛ ولو سقط
         * القفل يومًا (أو تجاوزه مستدعٍ باتّصالٍ آخر) لَقرأ طلبان المتبقّي
         * نفسه وكتبا معًا. التحديث الشرطيّ يجعل المحرّك هو الحَكَم: صفٌّ
         * واحد يتأثّر أو لا شيء — والقيد `orders_refund_reserve_within_captured`
         * خلفه للكتابة الخام.
         */

        /*
         * التحصيل الذي يُردّ منه: آخر تحصيلٍ معروف المزوّد. وطلبٌ بلا
         * تحصيلٍ مسجَّل (قديم، أو مؤسَّس يدويًّا) يُردّ يدويًّا ويُسجَّل
         * `MANUAL` — لا يُخترع له مزوّد ولا يُرفض ردُّه.
         */
        $payment = Payment::query()
            ->where('order_id', $fresh->id)
            ->where('state', 'CAPTURED')
            ->whereNotNull('intent_id')
            // يُردّ من التحصيل الذي موَّل الطلب — لا من تحصيلٍ مرفوضٍ أو زائد.
            ->where(fn ($q) => $q->whereNull('funding')->orWhere('funding', 'FUNDED'))
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($payment !== null && $this->providers->resolve((string) $payment->provider) === null) {
            throw new RefundFailed("مزوّد الدفع «{$payment->provider}» غير متاح في هذه البيئة — لا يمكن تنفيذ الاسترداد.");
        }

        $manual = $payment === null;

        $affected = Order::query()
            ->whereKey($fresh->id)
            ->whereIn('status', self::CAPTURED_STATUSES)
            ->whereRaw('refunded_total_minor + refund_reserved_minor + ? <= total_minor', [$amountMinor])
            ->update($manual ? self::settledLedgerWrite($amountMinor, fromReserve: false)
                : ['refund_reserved_minor' => DB::raw("refund_reserved_minor + {$amountMinor}")]);

        if ($affected !== 1) {
            $fresh->refresh();

            throw new RefundExceedsCaptured(
                $this->capturedMinor($fresh),
                (int) $fresh->refunded_total_minor + (int) $fresh->refund_reserved_minor,
                $amountMinor,
            );
        }

        if ($payment !== null) {
            $this->reserveOnCapture($payment, $amountMinor, settled: false);
        }

        $fresh->refresh();

        $refund = Refund::query()->create([
            'order_id' => $fresh->id,
            'amount_minor' => $amountMinor,
            'scope' => 'order',
            'status' => $manual ? 'MANUAL' : 'PENDING',
            'provider' => $payment?->provider,
            'payment_id' => $payment?->id,
            'reason' => $reason,
            'revocation_policy' => $policy->value,
            'idempotency_key' => $idempotencyKey,
            'settled_at' => $manual ? now() : null,
        ]);

        if ($manual) {
            $this->applyCompleted($fresh, $refund);
        }

        return [$refund, false];
    }

    /**
     * كتابة «خرج المال» على الطلب: المبلغ والحالة في تحديثٍ واحد.
     *
     * قيدُ `orders_refund_status_agrees` في المحرّك يرفض صفًّا مبلغُه موجب
     * وحالتُه ليست استردادًا — فلا يجوز كتابتهما في خطوتين.
     *
     * @return array<string, Expression>
     */
    private static function settledLedgerWrite(int $amountMinor, bool $fromReserve): array
    {
        $write = [
            'refunded_total_minor' => DB::raw("refunded_total_minor + {$amountMinor}"),
            'status' => DB::raw(
                "CASE WHEN refunded_total_minor + {$amountMinor} >= total_minor THEN 'REFUNDED' ELSE 'PARTIALLY_REFUNDED' END",
            ),
        ];

        if ($fromReserve) {
            $write['refund_reserved_minor'] = DB::raw("greatest(0, refund_reserved_minor - {$amountMinor})");
        }

        return $write;
    }

    /** ينادي المزوّد عن صفٍّ محجوز ويحسمه. */
    private function execute(Refund $refund): void
    {
        $refund->refresh();

        if (! in_array($refund->status, Refund::OPEN, true)) {
            return;
        }

        if ($refund->status === 'PENDING' && $refund->provider_refund_id !== null) {
            return; // قبِله المزوّد ويُحسم بخطّافه
        }

        $payment = Payment::query()->find($refund->payment_id);
        $adapter = $this->providers->resolve((string) $refund->provider);

        if ($payment === null || $adapter === null) {
            $this->markFailed($refund, 'No resolvable provider or payment for this refund.');

            throw new RefundFailed('لا تحصيل أو مزوّد يمكن تنفيذ الاسترداد عبره.', $refund);
        }

        try {
            $result = $adapter->refund(
                $payment, (int) $refund->amount_minor, OrderFinalizer::STORE_CURRENCY,
                "refund:{$refund->id}", $refund->reason,
            );
        } catch (ProviderRefundRejected $e) {
            $this->markFailed($refund, $e->getMessage());

            throw new RefundFailed('رفض المزوّد الاسترداد: '.$e->getMessage(), $refund);
        } catch (RefundOutcomeUnknown $e) {
            Log::error('payments.refund.outcome_unknown', ['refundId' => $refund->id, 'error' => $e->getMessage()]);
            $refund->update(['status' => 'UNKNOWN', 'provider_error' => mb_substr($e->getMessage(), 0, 1000)]);

            throw $e;
        }

        match ($result->status) {
            RefundResult::SUCCEEDED => $this->markSucceeded($refund, $result->providerRefundId),
            RefundResult::PENDING => $refund->update([
                'status' => 'PENDING',
                'provider_refund_id' => $result->providerRefundId,
                'provider_error' => null,
            ]),
            default => (function () use ($refund, $result): void {
                $this->markFailed($refund, $result->message ?? 'Provider reported failure.');

                throw new RefundFailed('رفض المزوّد الاسترداد: '.($result->message ?? ''), $refund);
            })(),
        };
    }

    private function markSucceeded(Refund $refund, ?string $providerRefundId): void
    {
        DB::transaction(function () use ($refund, $providerRefundId): void {
            $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($refund->status === 'SUCCEEDED') {
                return;
            }

            $refund->update([
                'status' => 'SUCCEEDED',
                'provider_refund_id' => $providerRefundId ?? $refund->provider_refund_id,
                'provider_error' => null,
                'settled_at' => now(),
            ]);

            $amount = (int) $refund->amount_minor;

            if ($refund->payment_id !== null) {
                Payment::query()->whereKey($refund->payment_id)->update([
                    'refund_reserved_minor' => DB::raw("greatest(0, refund_reserved_minor - {$amount})"),
                    'refunded_minor' => DB::raw("refunded_minor + {$amount}"),
                ]);
            }

            if ($refund->scope === 'capture') {
                // مالٌ لم يُموِّل الطلب رُدّ: لا حالةُ طلبٍ تتغيّر ولا استحقاقٌ يُسحب.
                $this->emitCompleted($order, $refund, (int) $order->refunded_total_minor, $this->capturedMinor($order));

                return;
            }

            // من المحجوز إلى المحسوم — والحالة تتبع المحسوم.
            Order::query()->whereKey($order->id)
                ->update(self::settledLedgerWrite($amount, fromReserve: true));
            $order->refresh();

            $this->applyCompleted($order, $refund);
        });
    }

    private function markFailed(Refund $refund, string $error): void
    {
        DB::transaction(function () use ($refund, $error): void {
            $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if (! in_array($refund->status, Refund::OPEN, true)) {
                return;
            }

            $refund->update(['status' => 'FAILED', 'provider_error' => mb_substr($error, 0, 1000)]);

            // الإفراج عن الحجز: المال لم يخرج، والحالة لم تتغيّر أصلًا.
            $amount = (int) $refund->amount_minor;
            if ($refund->payment_id !== null) {
                Payment::query()->whereKey($refund->payment_id)->update([
                    'refund_reserved_minor' => DB::raw("greatest(0, refund_reserved_minor - {$amount})"),
                ]);
            }
            if ($refund->scope !== 'capture') {
                Order::query()->whereKey($order->id)->update([
                    'refund_reserved_minor' => DB::raw("greatest(0, refund_reserved_minor - {$amount})"),
                ]);
            }
        });
    }

    /**
     * آثار الاسترداد المحسوم — تحت قفل الطلب، في معاملة المستدعي.
     */
    private function applyCompleted(Order $order, Refund $refund): void
    {
        $captured = $this->capturedMinor($order);
        $settled = (int) $order->refunded_total_minor;
        $policy = RevocationPolicy::from((string) $refund->revocation_policy);

        /*
         * §٣٤ — السحب بشرطين: ردٌّ **كامل** (من المحسوم لا المحجوز)، وسياسةٌ
         * تسحب فعلًا. ردُّ هللةٍ من ألف لا يحرق مفاتيح الرخص، و`Keep`
         * و`Grace3d` تُحترمان داخل `revoke`.
         */
        $fullRefund = $settled >= $captured;

        if ($fullRefund && $policy === RevocationPolicy::Immediate) {
            $this->fulfillment->revokeAll($order->fresh('items'), $refund->reason ?? 'refund');
            $this->inventory->settleFor('order', (int) $order->id, 'RELEASED');
        }

        if ($fullRefund) {
            foreach (Entitlement::query()->where('order_id', $order->id)->get() as $entitlement) {
                $this->entitlements->revoke($entitlement, $refund->reason ?? 'refund', $policy);
            }
        }

        $this->emitCompleted($order, $refund, $settled, $captured);
    }

    private function emitCompleted(Order $order, Refund $refund, int $settled, int $captured): void
    {
        $this->outbox->write(
            aggregateType: 'refund',
            aggregateId: (string) $refund->id,
            eventType: 'refund.completed',
            payload: [
                'refund_id' => $refund->id,
                'order_id' => $order->id,
                'payment_id' => $refund->payment_id,
                'scope' => $refund->scope ?? 'order',
                'source' => $refund->source ?? 'app',
                'amount_minor' => (int) $refund->amount_minor,
                'refunded_total_minor' => $settled,
                'captured_minor' => $captured,
                'policy' => $refund->revocation_policy,
            ],
            idempotencyKey: "refund:{$refund->id}:completed",
        );
    }

    /**
     * يردّ مالًا وصل ولم يُموِّل الطلب — تحصيلٌ مرفوض (مبلغ/عملة/طلبٌ مُلغى)
     * أو زائد (دُفع الطلب مرّتين). المسار نفسه عند المزوّد (مفتاح خمول،
     * UNKNOWN، خطّاف)، لكنّ السقف دفترُ التحصيل لا دفترُ الطلب، ولا تتغيّر
     * حالة الطلب ولا استحقاقُه.
     *
     * @throws RefundExceedsCaptured|RefundFailed|RefundOutcomeUnknown|RefundIdempotencyConflict
     */
    public function refundCapture(Payment $payment, int $amountMinor, ?string $reason, string $idempotencyKey): Refund
    {
        try {
            [$refund, $existing] = DB::transaction(function () use ($payment, $amountMinor, $reason, $idempotencyKey): array {
                $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
                $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

                $existing = Refund::query()->where('order_id', $order->id)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    return [$existing, true];
                }

                if ($locked->state !== 'CAPTURED' || ! in_array($locked->funding, ['UNFUNDABLE', 'EXTRA'], true)) {
                    throw new RefundFailed('هذا التحصيل موَّل الطلب — يُردّ من صفحة الطلب لا من هنا.');
                }
                if ($locked->amount_minor === null) {
                    throw new RefundFailed('مبلغ هذا التحصيل غير معروف — راجع لوحة المزوّد قبل الردّ.');
                }
                if ($this->providers->resolve((string) $locked->provider) === null) {
                    throw new RefundFailed("مزوّد الدفع «{$locked->provider}» غير متاح في هذه البيئة.");
                }

                $this->reserveOnCapture($locked, $amountMinor, settled: false);

                return [Refund::query()->create([
                    'order_id' => $order->id,
                    'amount_minor' => $amountMinor,
                    'scope' => 'capture',
                    'status' => 'PENDING',
                    'provider' => $locked->provider,
                    'payment_id' => $locked->id,
                    'reason' => $reason,
                    'revocation_policy' => RevocationPolicy::Keep->value,
                    'idempotency_key' => $idempotencyKey,
                ]), false];
            });
        } catch (UniqueConstraintViolationException $e) {
            $winner = Refund::query()->where('order_id', $payment->order_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($winner === null) {
                throw $e;
            }
            [$refund, $existing] = [$winner, true];
        }

        if ($existing) {
            $this->assertSameRequest($refund, $amountMinor, RevocationPolicy::Keep, 'capture', (int) $payment->id);
            if (! in_array($refund->status, Refund::OPEN, true)) {
                return $refund;
            }
        }

        $this->execute($refund);

        return $refund->fresh();
    }

    /**
     * حجزٌ (أو تسجيلٌ محسوم) على دفتر التحصيل — تحديثٌ شرطيّ واحد، وقيدُ
     * `payments_refund_within_amount` خلفه.
     *
     * @throws RefundExceedsCaptured
     */
    private function reserveOnCapture(Payment $payment, int $amountMinor, bool $settled): void
    {
        if ($amountMinor <= 0) {
            throw new RefundExceedsCaptured((int) ($payment->amount_minor ?? 0), 0, $amountMinor);
        }

        $column = $settled ? 'refunded_minor' : 'refund_reserved_minor';

        $affected = Payment::query()
            ->whereKey($payment->id)
            ->where(fn ($q) => $q->whereNull('amount_minor')
                ->orWhereRaw('refunded_minor + refund_reserved_minor + ? <= amount_minor', [$amountMinor]))
            ->update([$column => DB::raw("{$column} + {$amountMinor}")]);

        if ($affected !== 1) {
            $payment->refresh();

            throw new RefundExceedsCaptured(
                (int) $payment->amount_minor,
                (int) $payment->refunded_minor + (int) $payment->refund_reserved_minor,
                $amountMinor,
            );
        }
    }

    /**
     * المفتاح نفسه يجب أن يحمل الطلب نفسه.
     *
     * @throws RefundIdempotencyConflict
     */
    private function assertSameRequest(Refund $refund, int $amountMinor, RevocationPolicy $policy, string $scope = 'order', ?int $paymentId = null): void
    {
        $diff = [];
        if ((int) $refund->amount_minor !== $amountMinor) {
            $diff[] = "amount {$refund->amount_minor}≠{$amountMinor}";
        }
        if ((string) $refund->revocation_policy !== $policy->value) {
            $diff[] = "policy {$refund->revocation_policy}≠{$policy->value}";
        }
        if (($refund->scope ?? 'order') !== $scope) {
            $diff[] = 'scope';
        }
        if ($paymentId !== null && (int) $refund->payment_id !== $paymentId) {
            $diff[] = 'payment';
        }

        if ($diff !== []) {
            throw new RefundIdempotencyConflict((int) $refund->id, implode(', ', $diff));
        }
    }

    /**
     * استردادٌ نفّذه المشغّل من لوحة المزوّد مباشرةً — المال خرج ولم يطلبه
     * التطبيق. يُسجَّل كما هو (محسومًا، بمبلغه وتحصيله) ولا يُهمَل «غير
     * مطابق»: الدفتر الذي لا يعرفه يعرض المبلغ قابلًا للردّ مرّةً ثانية.
     *
     * لا يسحب استحقاقًا تلقائيًّا (سياسة `keep`): قرارُ السحب لمن ردّ المال،
     * والتسوية تفتح له حالةً ليتّخذه.
     *
     * @return 'applied'|'duplicate'|'unmatched'
     */
    private function recordProviderRefund(string $provider, string $providerRefundId, ?string $chargeId, ?int $amountMinor, ?string $currency): string
    {
        if ($chargeId === null || $chargeId === '' || $amountMinor === null
            || mb_strtoupper((string) ($currency ?? '')) !== OrderFinalizer::STORE_CURRENCY) {
            Log::error('payments.refund.provider_refund_unreadable', [
                'provider' => $provider, 'providerRefundId' => $providerRefundId, 'chargeId' => $chargeId,
            ]);

            return 'unmatched';
        }

        // التحصيل بمفتاحه (`provider:chargeId`) — هويّة الرسم التي سُجِّل بها المال.
        $payment = Payment::query()->where('provider', $provider)->where('state', 'CAPTURED')
            ->where(fn ($q) => $q->where('idempotency_key', "{$provider}:{$chargeId}")->orWhere('intent_id', $chargeId))
            ->orderBy('id')->first();

        if ($payment === null) {
            return 'unmatched';
        }

        try {
            return DB::transaction(function () use ($payment, $provider, $providerRefundId, $amountMinor): string {
                $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
                $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

                if (Refund::query()->where('provider', $provider)->where('provider_refund_id', $providerRefundId)->exists()) {
                    return 'duplicate';
                }

                $orderScope = $locked->funding === null || $locked->funding === 'FUNDED';

                $this->reserveOnCapture($locked, $amountMinor, settled: true);

                if ($orderScope) {
                    $affected = Order::query()->whereKey($order->id)
                        ->whereRaw('refunded_total_minor + refund_reserved_minor + ? <= total_minor', [$amountMinor])
                        ->update(self::settledLedgerWrite($amountMinor, fromReserve: false));
                    if ($affected !== 1) {
                        throw new RefundExceedsCaptured((int) $order->total_minor, (int) $order->refunded_total_minor + (int) $order->refund_reserved_minor, $amountMinor);
                    }
                    $order->refresh();
                }

                $refund = Refund::query()->create([
                    'order_id' => $order->id,
                    'amount_minor' => $amountMinor,
                    'scope' => $orderScope ? 'order' : 'capture',
                    'source' => 'provider',
                    'status' => 'SUCCEEDED',
                    'provider' => $provider,
                    'payment_id' => $locked->id,
                    'provider_refund_id' => $providerRefundId,
                    'reason' => 'provider_dashboard',
                    'revocation_policy' => RevocationPolicy::Keep->value,
                    'settled_at' => now(),
                ]);

                if ($orderScope) {
                    $this->applyCompleted($order, $refund);
                } else {
                    $this->emitCompleted($order, $refund, (int) $order->refunded_total_minor, $this->capturedMinor($order));
                }

                Log::warning('payments.refund.recorded_from_provider', [
                    'refundId' => $refund->id, 'orderId' => $order->id, 'paymentId' => $locked->id,
                    'providerRefundId' => $providerRefundId, 'amountMinor' => $amountMinor,
                ]);

                return 'applied';
            });
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        } catch (RefundExceedsCaptured $e) {
            // المزوّد يقول إنّ مالًا أكثر ممّا نعرفه خرج: تناقضٌ يُرفع ولا يُطوى.
            Log::critical('payments.refund.provider_refund_exceeds_ledger', [
                'providerRefundId' => $providerRefundId, 'paymentId' => $payment->id, 'error' => $e->getMessage(),
            ]);

            return 'unmatched';
        }
    }

    /**
     * «المحصَّل» هو `total_minor` للطلب الذي وصل مالُه. وطلبٌ لم يصل مالُه
     * محصَّلُه صفر فيُرفض كلّ ردّ. (مطابقة المبلغ للمزوّد يحرسها
     * `OrderFinalizer`: ما لا يطابق لا يُموِّل.)
     */
    private function capturedMinor(Order $order): int
    {
        return in_array($order->status, self::CAPTURED_STATUSES, true) ? (int) $order->total_minor : 0;
    }
}
