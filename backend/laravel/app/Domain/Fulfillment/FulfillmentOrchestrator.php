<?php

namespace App\Domain\Fulfillment;

use App\Domain\Fulfillment\Executors\ExecutorRegistry;
use App\Domain\Fulfillment\Executors\FulfillmentOutcome;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\Shipping\Models\Shipment;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * منسّق التنفيذ (§٩) — يقرّر **كيف** يُسلَّم كلّ بند، ثمّ يوزّع على منفّذيه.
 *
 * قبله كان القرار ضمنيًّا: `grantForOrder` يمنح لكلّ بندٍ استحقاقَ تنزيل بلا
 * أن يسأل. مقبولٌ ما دام كلّ شيءٍ رقميًّا، وكارثيٌّ أوّل يوم يُباع فيه شيءٌ
 * يُشحن — فالزبون يأخذ رابط تنزيلٍ لصندوقٍ لم يُرسل، والطلب يُقفل سليمًا.
 *
 * وكان يعرف كلّ استراتيجيّة بنفسه عبر `match`، فكان كلّ نظامٍ جديد يوجب
 * تعديله. صار يوزّع على منفّذين، فإضافةُ الحجز أو التزويد لاحقًا صنفٌ جديد
 * لا جراحةٌ هنا.
 *
 * ثلاث قواعد تحكم هذا الصنف:
 *
 * ١ — **لا تسليم بلا استراتيجيّة مُشتقّة.** يُرمى ولا يُفترض «رقميّ».
 * ٢ — **لا حالةٌ نهائيّة بلا فعلٍ وقع.** ما لا منفّذ له، وما ينتظر بياناتٍ،
 *     يبقى PENDING بسببٍ مكتوب. «نُفّذت» كاذبةً تُقفل الطلب ولا يصل شيء.
 * ٣ — **خامل التكرار** (§٢١): المفتاح من (البند، الاستراتيجيّة)، فإعادة
 *     نداء webhook لا تُنشئ شحنةً ثانية ولا تمنح استحقاقًا مكرّرًا.
 */
class FulfillmentOrchestrator
{
    public function __construct(
        private readonly OutboxWriter $outbox,
        private readonly FulfillmentStrategyResolver $resolver,
        private readonly ExecutorRegistry $executors,
    ) {}

    /**
     * يشتقّ استراتيجيّات بندٍ واحد.
     *
     * المنطق في `FulfillmentStrategyResolver` لأنّ الدفع يسأله أيضًا قبل
     * السداد. نسختان كانتا ستفترقان، فيَعِد الدفعُ بشيءٍ ويسلّم غيره.
     */
    public function plan(OrderItem $item): FulfillmentPlan
    {
        return $this->resolver->resolve(
            $item->product_offer_id === null ? null : (int) $item->product_offer_id,
            (int) $item->product_id,
        );
    }

    /**
     * الفحص المسبق: كلّ سؤالٍ خارجيّ يُطرح هنا **قبل** المعاملة (القاعدة ٢).
     * يُستدعى من المُثبِّت والمُعيد قبل فتح معاملة `fulfill`.
     */
    public function preflight(Order $order): void
    {
        foreach ($order->items as $item) {
            foreach ($this->plan($item)->strategies as $strategy) {
                $executor = $this->executors->for($strategy);

                if (! $executor instanceof Executors\PreflightsExternally) {
                    continue;
                }

                $settled = FulfillmentUnit::query()
                    ->where('order_item_id', $item->id)->where('strategy', $strategy)->first();

                if ($settled === null || ! $settled->isTerminal()) {
                    $executor->preflight($order, $item);
                }
            }
        }
    }

    /**
     * ينشئ وحدات التنفيذ لطلبٍ كامل ثمّ ينفّذ ما يستطيع. خامل التكرار.
     *
     * @return Collection<int, FulfillmentUnit>
     */
    public function fulfill(Order $order): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('FulfillmentOrchestrator::fulfill must run inside a database transaction.');
        }

        $units = collect();

        foreach ($order->items as $item) {
            $plan = $this->plan($item);

            foreach ($plan->strategies as $i => $strategy) {
                $units->push($this->settleUnit($order, $item, $strategy, $i));
            }
        }

        /*
         * `mixed` يُكتب على الطلب لا على الوحدة: MIXED_FULFILLMENT في §٨
         * وصفُ طلبٍ انقسم، لا استراتيجيّةُ قطعةٍ تُنفَّذ.
         */
        if ($units->pluck('strategy')->unique()->count() > 1) {
            $this->outbox->write(
                aggregateType: 'order',
                aggregateId: (string) $order->id,
                eventType: 'order.fulfillment.mixed',
                payload: ['order_id' => $order->id, 'strategies' => $units->pluck('strategy')->unique()->values()->all()],
                idempotencyKey: "order:{$order->id}:fulfillment-mixed",
            );
        }

        return $units;
    }

    /** هل أُغلقت كلّ وحدات الطلب؟ «مكتمل» خلاصةٌ تُحسب لا حالةٌ تُعلن. */
    public function isFullyFulfilled(Order $order): bool
    {
        $units = FulfillmentUnit::query()->where('order_id', $order->id)->get();

        return $units->isNotEmpty() && $units->every(fn (FulfillmentUnit $u) => $u->isTerminal());
    }

    /**
     * يُغلق الطلب حين يُسلَّم كلّ ما فيه (§٣٤).
     *
     * ─── الحلقة التي كانت مفتوحة ───
     *
     * `isFullyFulfilled` كانت مكتوبةً ومُختبَرةً **ولا يستدعيها شيء في
     * الإنتاج**. و`OrderFinalizer` يقف عند `PAID`، فلا طلبٍ يبلغ `COMPLETED`
     * من وحداته أبدًا مهما سُلِّم. ومعنى ذلك أنّ «تتبّع حالة التنفيذ» كان
     * يصف البداية ولا يصف النهاية: لوحةُ المشغّل تُري طلبًا «مدفوعًا» بعد
     * تسليمه كاملًا، فلا يُميَّز ما يحتاج عملًا ممّا انتهى.
     *
     * ─── و«مكتمل» تعني سُلِّم، لا انتهى ───
     *
     * الشرط هنا `FULFILLED` لكلّ وحدة، لا `isTerminal()`. الفرق يهمّ:
     * `CANCELLED` و`REVOKED` نهائيّتان وليستا تسليمًا، وطلبٌ سُحب استحقاقه
     * يُعلَن «مكتملًا» كان يُخفي عن المشغّل ما يجب أن يراه. ووحدةٌ `FAILED`
     * ليست نهائيّةً أصلًا — تنتظر إعادة محاولة.
     *
     * والانتقال محروسٌ بقفل صفّ: مسارَي «آخر وحدة نُفّذت» و«شحنةٌ سُلِّمت»
     * قد يلتقيان، فيقرأ كلاهما «كلّها منفَّذة» ويكتبان معًا — حدثان في
     * الصندوق الصادر لطلبٍ واحد.
     */
    public function closeIfDelivered(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($fresh === null || ! in_array($fresh->status, ['PAID', 'PROCESSING'], true)) {
                return false;
            }

            $units = FulfillmentUnit::query()->where('order_id', $fresh->id)->get();

            if ($units->isEmpty() || ! $units->every(fn (FulfillmentUnit $u) => $u->status === 'FULFILLED')) {
                return false;
            }

            /*
             * ─── والشحنة لا تكتمل بإنشائها ───
             *
             * `ShipmentExecutor` يُرجع `FULFILLED` لحظة إنشاء الشحنة، وهو
             * صحيحٌ في بابه: عملٌ حقيقيّ وقع. لكنّ «مكتمل» على الطلب تعني
             * **وصل**، لا «سُلِّم للناقل». وبلا هذا الفحص كان كلّ طلبٍ ماديّ
             * يُعلَن مكتملًا لحظة تثبيت الدفع — والطرد لم يغادر المستودع.
             * وكان ذلك يُميت مسارَ «التسليم يُغلق الطلب» كلَّه: لا يبقى طلبٌ
             * في `PAID` ليُغلقه وصولُ الشحنة.
             */
            $undelivered = Shipment::query()
                ->where('order_id', $fresh->id)
                ->where('status', '!=', 'DELIVERED')
                ->exists();

            if ($undelivered) {
                return false;
            }

            $fresh->update(['status' => 'COMPLETED']);

            $this->outbox->write(
                aggregateType: 'order',
                aggregateId: (string) $fresh->id,
                eventType: 'order.completed',
                payload: [
                    'order_id' => $fresh->id,
                    'units' => $units->count(),
                    'strategies' => $units->pluck('strategy')->unique()->values()->all(),
                ],
                idempotencyKey: "order:{$fresh->id}:completed",
            );

            return true;
        });
    }

    /**
     * يسحب تنفيذ الطلب كلّه — كلُّ مزوّدٍ بطريقته (§٣٤).
     *
     * ─── ما كان يقع قبل ذلك ───
     *
     * الاسترداد كان يُلغي الاستحقاقات وحدها. فمفتاح الرخصة يبقى مُسنَدًا بعد
     * ردّ المال، ووحدةُ التنفيذ تبقى `FULFILLED` فيُحسب الطلب مسلَّمًا في كلّ
     * تقرير. والسحب هنا يمرّ بالمنفّذين أنفسهم، فإضافةُ مزوّدٍ جديد لا توجب
     * تعديل خدمة الاسترداد.
     *
     * ─── ولا تُعلَّم وحدةٌ `REVOKED` إلّا وقد سُحب شيء ───
     *
     * الشحنة المُسلَّمة تُرتجَع ولا تُسحب، فيُعاد سببُها ويُكتب في سجلّ
     * الوحدة ولا تتغيّر حالتها. حالةٌ نهائيّة كاذبة في الاتّجاه المعاكس
     * تُخفي عن المشغّل ارتجاعًا يجب أن يتابعه.
     *
     * @return list<string> ما وقع فعلًا، للتدقيق.
     */
    public function revokeAll(Order $order, string $reason): array
    {
        $units = FulfillmentUnit::query()
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->get();

        $items = $order->items->keyBy('id');
        $log = [];

        foreach ($units as $unit) {
            $item = $items[$unit->order_item_id] ?? null;
            $executor = $this->executors->for($unit->strategy);

            if ($item === null || $executor === null) {
                continue;
            }

            $outcome = $executor->revoke($unit, $order, $item, $reason);

            if ($outcome === null) {
                /*
                 * لا شيء مُنح بعد — لكنّ الوحدة **مفتوحة**، وذاك خطرٌ لا فراغ.
                 *
                 * وحدةٌ `PENDING` أو `FAILED` على طلبٍ رُدّ ماله يلتقطها
                 * `RetryFulfillmentUnitJob` وتُعاد محاولتُها: فتُنشأ شحنةٌ
                 * أو يُسحب مفتاحُ رخصةٍ **بعد** خروج المال. فتُلغى صراحةً
                 * وتصير نهائيّة، ولا يعود لها ما يُحرّكها.
                 */
                if (! $unit->isTerminal()) {
                    $unit->update(['status' => 'CANCELLED', 'pending_reason' => null]);
                    $log[] = "أُلغيت وحدةٌ مفتوحة ({$unit->strategy}) فلا تُعاد محاولتها بعد الردّ";
                }

                continue;
            }

            $log[] = $outcome;

            /*
             * «تُرتجَع» تُكتب في السجلّ ولا تُبدّل الحالة: الوحدة ما تزال
             * مسلَّمةً حتّى تعود البضاعة فعلًا.
             */
            if (str_contains($outcome, 'تُرتجَع')) {
                $unit->update(['last_error' => $outcome]);

                continue;
            }

            if (in_array($unit->status, ['CANCELLED', 'REVOKED'], true)) {
                // الملغى والمسحوب نهائيّان: لا يُكتب فوقهما ولو أعاد المنفّذ أثرًا. (المُنجز يُسحب.)
                continue;
            }

            $unit->update(['status' => 'REVOKED', 'pending_reason' => null]);
        }

        if ($log !== []) {
            $this->outbox->write(
                aggregateType: 'order',
                aggregateId: (string) $order->id,
                eventType: 'order.fulfillment.revoked',
                payload: ['order_id' => $order->id, 'reason' => $reason, 'actions' => $log],
                idempotencyKey: "order:{$order->id}:fulfillment-revoked:".substr(sha1($reason), 0, 12),
            );
        }

        return $log;
    }

    private function settleUnit(Order $order, OrderItem $item, string $strategy, int $sequence): FulfillmentUnit
    {
        $unit = FulfillmentUnit::query()->firstOrCreate(
            ['order_item_id' => $item->id, 'strategy' => $strategy],
            [
                'order_id' => $order->id,
                'sequence' => $sequence,
                'idempotency_key' => "item:{$item->id}:{$strategy}",
                'status' => 'PENDING',
                'pending_reason' => 'لم تُنفَّذ بعد.',
            ],
        );

        // أُنجزت سابقًا: إعادة النداء لا تُعيد التنفيذ ولا تُصفّر حالةً نهائيّة.
        if ($unit->isTerminal()) {
            return $unit;
        }

        $executor = $this->executors->for($strategy);

        $outcome = $executor === null
            ? FulfillmentOutcome::pending($this->awaitingReason($strategy))
            : $executor->execute($unit, $order, $item);

        return $this->apply($unit, $outcome);
    }

    private function apply(FulfillmentUnit $unit, FulfillmentOutcome $outcome): FulfillmentUnit
    {
        $unit->status = $outcome->status;

        if ($outcome->isFulfilled()) {
            $unit->fulfilled_at = now();
            $unit->pending_reason = null;
            $unit->last_error = null;
            $unit->entitlement_id = $outcome->entitlementId ?? $unit->entitlement_id;
        } elseif ($outcome->status === 'FAILED') {
            $unit->failed_at = now();
            $unit->attempts = $unit->attempts + 1;
            $unit->last_error = $outcome->reason;
            $unit->pending_reason = null;
        } else {
            // الانتظار ليس فشلًا: لا يُزاد عدّاد المحاولات ولا يُسجَّل خطأ.
            $unit->pending_reason = $outcome->reason;
            $unit->last_error = null;
        }

        $unit->save();

        /*
         * `refresh` لا تجميلٌ: الأعمدة ذات الافتراضات (`attempts`) تُملأ في
         * المحرّك، فالنموذج المُنشأ حديثًا في الذاكرة يعيد null بدلها. قارئٌ
         * يجمع المحاولات كان سيجمع أصفارًا غائبة.
         */
        return $unit->refresh();
    }

    /**
     * ما ينتظر منفّذًا لم يُكتب بعد.
     *
     * الأسباب تغيّرت في ٢٠٢٦-٠٩-١٥: صارت الجداول موجودة، فلم يعد صحيحًا قول
     * «لا جداول». الناقص الآن منفّذٌ أو بياناتُ مشغّل — ورسالةٌ قديمة تُبقي
     * المشغّل يبحث عمّا بُني سلفًا.
     */
    private function awaitingReason(string $strategy): string
    {
        return match ($strategy) {
            'LOCAL_PICKUP', 'STORE_PICKUP' => 'ينتظر موقع استلامٍ مُعرَّفًا في `inventory_locations` (§٣٠).',
            'SAAS_PROVISIONING', 'API_PROVISIONING' => 'ينتظر محوّل تزويدٍ — غير منفَّذ بعد (§٢١).',
            'APPOINTMENT', 'BOOKING' => 'ينتظر اختيار الزبون موعدًا من إتاحة المورد (§٢٥).',
            'MANUAL_SERVICE' => 'خدمة يدويّة — تنتظر إجراء مشغّل (§٢٤).',
            default => "لا منفّذ لاستراتيجيّة «{$strategy}» بعد.",
        };
    }
}
