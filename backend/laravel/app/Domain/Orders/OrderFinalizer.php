<?php

namespace App\Domain\Orders;

use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Inventory\InventoryService;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\PaymentUnfundable;
use App\Domain\Payments\ProviderRegistry;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE critical path (MIP 5.2): one DB transaction —
 * payment event → payment CAPTURED → order PAID → entitlements →
 * outbox rows → COMMIT. Idempotency key = "{provider}:{chargeId}"
 * (payments.idempotency_key UNIQUE); a duplicate is a logged no-op.
 */
class OrderFinalizer
{
    public function __construct(
        private readonly FulfillmentOrchestrator $fulfillment,
        private readonly OutboxWriter $outbox,
        private readonly InventoryService $inventory,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * المسار الوحيد الذي يُموِّل طلبًا — يسلكه الخطّاف وسؤالُ المزوّد
     * (`ProviderRequery`) وإعادةُ التشغيل والتأكيدُ التجريبيّ، ولا يُموِّل
     * شيءٌ غيرُه.
     *
     * ─── «سُجِّل» غير «موَّل» ───
     *
     * مفتاح الخمول `provider:chargeId` يمنع تسجيل التحصيل مرّتين. أمّا قرار
     * التمويل فعلى الصفّ (`payments.funding`): FUNDED أو UNFUNDABLE (مع سببه)
     * أو EXTRA. تحصيلٌ رُفض تمويله يُعاد تقييمه عند كلّ تسليمٍ أو إعادة —
     * فإن زال السبب بحقّ (أُعيد فتح الطلب مثلًا) موَّل مرّةً واحدة، وإلّا بقي
     * مرفوضًا بسببه. وما موَّل أو كان زائدًا لا يُعاد لمسه أبدًا.
     *
     * @param  array<string, mixed>  $payload
     * @param  int|null  $amountMinor  ما أبلغ به المزوّد؛ `null` = لم يُبلَّغ
     * @param  string|null  $amountProblem  مبلغٌ حاضر لا يُقرأ بدقّة — يُرفض
     * @param  string|null  $merchantId  تاجرُ الرسم كما أبلغ به المزوّد
     * @return bool true حين وقع أثرٌ جديد (تمويل أو تسجيل زائد)، false للمكرّر
     *
     * @throws PaymentUnfundable بعد الالتزام، حين سُجِّل المال ولم يُموِّل
     */
    public function finalize(
        Order $order,
        string $provider,
        string $chargeId,
        array $payload = [],
        ?int $amountMinor = null,
        ?string $currency = null,
        ?string $amountProblem = null,
        ?string $merchantId = null,
    ): bool {
        $key = "{$provider}:{$chargeId}";
        $reportedCurrency = $amountMinor === null ? null : mb_strtoupper((string) ($currency ?? 'SAR'));
        $evidence = compact('amountMinor', 'reportedCurrency', 'amountProblem', 'merchantId');

        // مسارٌ سريع: ما حُسم تمويلُه لا يُعاد. والمرفوض يُعاد تقييمه أدناه.
        $settled = Payment::query()->where('idempotency_key', $key)
            ->where(fn ($q) => $q->whereNull('funding')->orWhere('funding', '!=', 'UNFUNDABLE'))
            ->exists();
        if ($settled) {
            Log::info('payments.webhook.duplicate', ['idempotency_key' => $key, 'order_id' => $order->id]);

            return false;
        }

        /*
         * الاستعلام الخارجيّ (Drive) قبل المعاملة لا داخل أقفالها — يُسأل
         * فقط حين يبدو التمويل ممكنًا من القراءة الأوّليّة؛ والحكم تحت القفل.
         */
        if ($this->unfundableReason($order, $provider, $chargeId, $evidence) === null) {
            $this->fulfillment->preflight($order->fresh('items'));
        }

        $unfundable = null;

        try {
            $applied = DB::transaction(function () use ($order, $provider, $chargeId, $key, $payload, $amountMinor, $currency, $evidence, &$unfundable): bool {
                /*
                 * قفل الطلب أوّلًا ثمّ صفّ التحصيل — بالترتيب نفسه في كلّ
                 * مسار (الاسترداد يقفل الطلب أوّلًا أيضًا)، فلا جمود بينهما.
                 */
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);

                $existing = Payment::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing !== null && $existing->funding !== 'UNFUNDABLE') {
                    Log::info('payments.webhook.duplicate', ['idempotency_key' => $key, 'order_id' => $order->id]);

                    return false;
                }

                // الحكم تحت القفل: الحالة التي تُقرأ هنا هي الحقيقة.
                $unfundable = $this->unfundableReason($order, $provider, $chargeId, $evidence);

                if ($existing !== null) {
                    return $this->reevaluate($order, $existing, $unfundable, $payload);
                }

                /*
                 * رسمٌ يخصّ طلبًا آخر يُسجَّل على **صاحبه**: صفُّ نيّته موجود
                 * هناك، والكتابةُ على هذا الطلب تُنشئ تحصيلًا لطلبٍ لم يدفع.
                 */
                $ownerId = $unfundable?->reason === PaymentUnfundable::CHARGE_BELONGS_TO_ANOTHER_ORDER
                    ? (int) Payment::query()->where('provider', $provider)->where('intent_id', $chargeId)->value('order_id')
                    : (int) $order->id;

                /*
                 * يُعاد استعمال صفّ **النيّة** لهذا الرسم وحده. رسمٌ مقبوضٌ ثانٍ
                 * صفٌّ ثانٍ: الكتابة فوق الأوّل كانت تمحو مفتاح خموله وتُخفي
                 * مبلغه من التسوية. ولا يُلتقط صفُّ نيّةٍ لرسمٍ آخر (طريقةٌ
                 * أخرى): ذاك رسمٌ ما زال قائمًا عند المزوّد.
                 */
                $payment = Payment::query()
                    ->where('order_id', $ownerId)
                    ->where('provider', $provider)
                    ->where('intent_id', $chargeId)
                    ->lockForUpdate()
                    ->first();

                /*
                 * مزوّدٌ لا يربط رسومه بنيّاتنا (المزيَّف) قد يُبلّغ برسمٍ
                 * بهويّةٍ غير هويّة النيّة: يُعاد استعمال صفّ النيّة غير المقبوض
                 * كما كان. أمّا Tap فرسمٌ آخر نيّةٌ أخرى قائمة — لا تُلتقط.
                 */
                if ($payment === null && ! ($this->providers->resolve($provider)?->bindsChargesToIntents() ?? false)) {
                    $payment = Payment::query()
                        ->where('order_id', $ownerId)
                        ->where('provider', $provider)
                        ->where('state', '!=', 'CAPTURED')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();
                }

                $funding = match (true) {
                    $unfundable !== null => 'UNFUNDABLE',
                    $this->alreadyFunded($order, $payment?->id) => 'EXTRA',
                    default => 'FUNDED',
                };

                /*
                 * المبلغ يُحفظ كما أبلغ به المزوّد ولا يُشتقّ من الطلب —
                 * نسخُ `total_minor` كان سيخلق مطابقةً مصطنعة.
                 */
                $captured = [
                    'state' => 'CAPTURED',
                    'idempotency_key' => $key,
                    'captured_at' => now(),
                    'funding' => $funding,
                    'funding_reason' => $unfundable?->reason,
                ] + ($amountMinor === null ? [] : [
                    'amount_minor' => $amountMinor,
                    'currency' => $currency ?? 'SAR',
                ]);

                if ($payment !== null) {
                    $payment->update($captured);
                } else {
                    $payment = Payment::query()->create([
                        'order_id' => $ownerId,
                        'provider' => $provider,
                        'intent_id' => $chargeId,
                    ] + $captured);
                }

                PaymentEvent::query()->create([
                    'payment_id' => $payment->id,
                    'event_type' => match ($funding) {
                        'UNFUNDABLE' => 'charge.unfundable',
                        'EXTRA' => 'charge.extra_capture',
                        default => 'charge.captured',
                    },
                    'payload' => $payload + ($unfundable !== null ? ['unfundable' => $unfundable->reason, 'detail' => $unfundable->detail] : []),
                ]);

                if ($funding === 'UNFUNDABLE') {
                    /*
                     * لا رمي من داخل المعاملة: الرمي هنا يتراجع عن التحصيل
                     * المسجَّل والمال وصل فعلًا. يُلتزَم، ثمّ تُرمى
                     * `PaymentUnfundable` بعد الالتزام في أسفل الدالّة.
                     */
                    return false;
                }

                if ($funding === 'EXTRA') {
                    /*
                     * مالٌ ثانٍ على طلبٍ مموَّل (أو استُرد): يُسجَّل ولا يُعيد
                     * التمويل ولا يمنح شيئًا — والتسوية تفتح له حادثًا حرجًا
                     * («تحصيلان على طلب») ليُردّ.
                     */
                    Log::warning('payments.webhook.extra_capture', [
                        'idempotency_key' => $key, 'order_id' => $order->id, 'status' => $order->status,
                    ]);

                    return true;
                }

                $this->fund($order, $key);

                return true;
            });
        } catch (QueryException $e) {
            /*
             * يُبتلَع انتهاك التفرّد وحده، وحين يثبت التكرار بوجود المفتاح بعد
             * التراجع — لا كلّ خطأ قاعدة بيانات. الجمود وانقطاع الاتصال يُعاد
             * رميهما فيردّ الخطّاف بخطأ وتعيد البوّابة المحاولة؛ ابتلاعهما كان
             * يترك مالًا مقبوضًا وطلبًا لا يُموَّل أبدًا بلا إنذار.
             */
            if (! $this->isUniqueViolation($e)
                || ! Payment::query()->where('idempotency_key', $key)->exists()) {
                Log::error('payments.webhook.finalize_failed', [
                    'idempotency_key' => $key,
                    'sqlstate' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            Log::info('payments.webhook.duplicate_race', ['idempotency_key' => $key]);

            return false;
        }

        if ($unfundable !== null) {
            // بعد الالتزام: التحصيل محفوظ، والطلب لم يُموَّل، والمستدعي يعلم لماذا.
            Log::error('payments.webhook.unfundable', [
                'idempotency_key' => $key, 'order_id' => $order->id, 'reason' => $unfundable->reason,
                'detail' => $unfundable->detail, 'reported_minor' => $amountMinor, 'reported_currency' => $currency,
            ]);

            throw $unfundable;
        }

        return $applied;
    }

    /**
     * إعادة تقييم تحصيلٍ رُفض تمويله — تحت قفل الطلب والتحصيل.
     *
     * @param  array<string, mixed>  $payload
     */
    private function reevaluate(Order $order, Payment $payment, ?PaymentUnfundable $unfundable, array $payload): bool
    {
        if ((int) $payment->order_id !== (int) $order->id) {
            // التحصيل مسجَّلٌ على طلبٍ آخر (صاحبه): لا يُموِّل هذا الطلب أبدًا.
            $unfundable ??= new PaymentUnfundable(
                PaymentUnfundable::CHARGE_BELONGS_TO_ANOTHER_ORDER, (int) $order->id,
                "capture is recorded on order #{$payment->order_id}",
            );
        }

        if ($unfundable === null && ((int) $payment->refunded_minor + (int) $payment->refund_reserved_minor) > 0) {
            // مالٌ رُدّ منه (أو يُردّ) لا يُموِّل طلبًا: ما وصل لم يعد كاملًا.
            $unfundable = new PaymentUnfundable(
                PaymentUnfundable::CAPTURE_REFUNDED, (int) $order->id,
                'capture has refunds recorded against it',
            );
        }

        if ($unfundable !== null) {
            $payment->update(['funding_reason' => $unfundable->reason]);

            return false;
        }

        if ($this->alreadyFunded($order, (int) $payment->id)) {
            // موَّله تحصيلٌ آخر في أثناء ذلك: هذا زائد.
            $payment->update(['funding' => 'EXTRA', 'funding_reason' => null]);
            PaymentEvent::query()->create(['payment_id' => $payment->id, 'event_type' => 'charge.extra_capture', 'payload' => $payload]);

            return true;
        }

        $payment->update(['funding' => 'FUNDED', 'funding_reason' => null]);
        PaymentEvent::query()->create([
            'payment_id' => $payment->id,
            'event_type' => 'charge.funded_on_reevaluation',
            'payload' => $payload,
        ]);
        Log::warning('payments.webhook.funded_on_reevaluation', ['paymentId' => $payment->id, 'orderId' => $order->id]);

        $this->fund($order, (string) $payment->idempotency_key);

        return true;
    }

    /**
     * آثار التمويل — تحت قفل الطلب، في معاملة المستدعي، مرّةً واحدة.
     */
    private function fund(Order $order, string $key): void
    {
        /*
         * «وصل المال يومًا» لا «المال في اليد»: طلبٌ «مكتمل» لا يعود
         * «مدفوعًا»، وطابع الدفع يُكتب مرّةً واحدة (القاعدة ١٣).
         */
        if (! OrderStatus::wasEverCollected($order->status)) {
            $order->update(['status' => 'PAID', 'paid_at' => $order->paid_at ?? now()]);
        }

        /*
         * §٢١ — المال وصل، فالحجز يصير بيعًا. وإلّا بقي معلّقًا حتّى تنتهي
         * مهلتُه فيعود ما بِيع إلى المتاح.
         */
        $this->inventory->settleFor('order', (int) $order->id, 'CONSUMED');

        // الاستحقاق والتنفيذ من استراتيجيّة كلّ بند (§٩ §١٠).
        $this->fulfillment->fulfill($order->fresh('items'));

        // طلبٌ كلُّه رقميّ يكتمل هنا: وحداته نُفِّذت أعلاه فلا شيء ينتظر.
        $this->fulfillment->closeIfDelivered($order->fresh());

        $this->outbox->write(
            aggregateType: 'order',
            aggregateId: (string) $order->id,
            eventType: 'order.paid',
            payload: ['order_id' => $order->id, 'number' => $order->number, 'total_minor' => $order->total_minor],
            idempotencyKey: "order:{$order->id}:paid",
        );

        Log::info('payments.funded', ['idempotency_key' => $key, 'order_id' => $order->id]);
    }

    public const STORE_CURRENCY = 'SAR';

    /**
     * هل موَّل هذا الطلبَ مالٌ سلفًا؟ تحصيلٌ آخر موِّل (أو قديمٌ بلا قرار)،
     * أو طلبٌ رُدّ ماله — فمالٌ جديدٌ عليه زائدٌ لا تمويل.
     *
     * طلبٌ «مدفوع» بلا أيّ تحصيلٍ مسجَّل (قديم، أو أُعلن يدويًّا) ليس مموَّلًا
     * بدليل: أوّل تحصيلٍ صالح يصل هو دليله.
     */
    private function alreadyFunded(Order $order, ?int $exceptPaymentId): bool
    {
        if (in_array((string) $order->status, ['REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
            return true;
        }

        return Payment::query()
            ->where('order_id', $order->id)
            ->where('state', 'CAPTURED')
            ->where(fn ($q) => $q->whereNull('funding')->orWhere('funding', 'FUNDED'))
            ->when($exceptPaymentId !== null, fn ($q) => $q->whereKeyNot($exceptPaymentId))
            ->exists();
    }

    /**
     * لماذا لا يُموِّل هذا الرسم هذا الطلب — أو `null` حين يجوز.
     *
     * يُنادى مرّتين: قراءةً أوّليّة (لتجنّب سؤال Drive عبثًا) وتحت القفل
     * (وهو الحكم). الترتيب: ملكيّة الرسم، ثمّ حالة الطلب، ثمّ المبلغ والعملة.
     */
    /**
     * @param  array{amountMinor: ?int, reportedCurrency: ?string, amountProblem: ?string, merchantId: ?string}  $evidence
     */
    private function unfundableReason(Order $order, string $provider, string $chargeId, array $evidence): ?PaymentUnfundable
    {
        ['amountMinor' => $amountMinor, 'reportedCurrency' => $reportedCurrency, 'amountProblem' => $amountProblem, 'merchantId' => $merchantId] = $evidence;

        $intentOwner = Payment::query()
            ->where('provider', $provider)->where('intent_id', $chargeId)
            ->whereNotNull('idempotency_key')
            ->orderBy('id')
            ->value('order_id');

        if ($intentOwner !== null && (int) $intentOwner !== (int) $order->id) {
            return new PaymentUnfundable(
                PaymentUnfundable::CHARGE_BELONGS_TO_ANOTHER_ORDER, (int) $order->id,
                "charge {$chargeId} was created for order #{$intentOwner}",
            );
        }

        /*
         * الملكيّة القانونيّة: Tap لا يوقّع `reference.order` (التوقيع على
         * id·amount·currency·references·status·created وحدها)، فالطلب في
         * الحمولة ادّعاءٌ لا دليل. المزوّد الذي يربط رسومه بنيّاتنا لا
         * يُموِّل برسمٍ لم نُنشئه نحن لهذا الطلب.
         */
        $adapter = $this->providers->resolve($provider);
        if ($intentOwner === null && $adapter !== null && $adapter->bindsChargesToIntents()) {
            return new PaymentUnfundable(
                PaymentUnfundable::UNKNOWN_CHARGE, (int) $order->id,
                "charge {$chargeId} was not created by this store for any order",
            );
        }

        $expectedMerchant = $adapter?->merchantId();
        if ($merchantId !== null && $expectedMerchant !== null && $merchantId !== $expectedMerchant) {
            return new PaymentUnfundable(
                PaymentUnfundable::MERCHANT_MISMATCH, (int) $order->id,
                "charge belongs to merchant {$merchantId}, store is {$expectedMerchant}",
            );
        }

        /*
         * يُموَّل ما ينتظر الدفع، وما وصل مالُه سلفًا (رسمٌ ثانٍ يُسجَّل ولا
         * يُعيد التمويل — انظر `wasEverCollected` أدناه). المُلغى والفاشل لا.
         */
        if (! in_array((string) $order->status, ['PENDING', 'AWAITING_PAYMENT'], true)
            && ! OrderStatus::wasEverCollected($order->status)) {
            return new PaymentUnfundable(
                PaymentUnfundable::ORDER_NOT_FUNDABLE, (int) $order->id,
                "order is {$order->status}",
            );
        }

        if ($amountProblem !== null) {
            // مبلغٌ لا يُقرأ بدقّة ليس «غير معروف» يُموِّل — هو فرقٌ.
            return new PaymentUnfundable(
                PaymentUnfundable::AMOUNT_MISMATCH, (int) $order->id,
                "provider amount unreadable ({$amountProblem})",
            );
        }

        if ($amountMinor !== null && $reportedCurrency !== self::STORE_CURRENCY) {
            return new PaymentUnfundable(
                PaymentUnfundable::CURRENCY_MISMATCH, (int) $order->id,
                "provider reported {$reportedCurrency}, store is ".self::STORE_CURRENCY,
            );
        }

        if ($amountMinor !== null && $amountMinor !== (int) $order->total_minor) {
            return new PaymentUnfundable(
                PaymentUnfundable::AMOUNT_MISMATCH, (int) $order->id,
                "provider reported {$amountMinor} minor, order expects {$order->total_minor}",
            );
        }

        return null;
    }

    /**
     * انتهاك قيد تفرّد فعلي — لا أيّ خطأ قاعدة بيانات.
     *
     * 23505 معيار PostgreSQL. SQLite (الاختبارات) يعطي 23000 ونصًّا يحوي
     * UNIQUE، فيُفحص الاثنان حتى يبقى الاختبار ممثّلًا للإنتاج.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $state = (string) $e->getCode();

        return $state === '23505'
            || ($state === '23000' && str_contains(strtoupper($e->getMessage()), 'UNIQUE'));
    }
}
