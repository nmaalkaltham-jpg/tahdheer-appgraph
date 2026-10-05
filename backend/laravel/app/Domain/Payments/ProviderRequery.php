<?php

namespace App\Domain\Payments;

use App\Domain\Orders\Models\Refund;
use App\Domain\Orders\OrderFinalizer;
use App\Domain\Orders\RefundService;
use App\Domain\Payments\Models\Payment;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * سؤال المزوّد عمّا لم يصلنا منه — التعافي بلا يدٍ بشريّة.
 *
 * ─── لماذا ───
 *
 * الخطّاف قد يضيع: Tap يستسلم بعد محاولات، والخادم قد يكون متوقّفًا لحظة
 * الإرسال. فيبقى رسمٌ مقبوض على طلبٍ «بانتظار الدفع» إلى الأبد — دفع الزبون
 * ولا ملفّ. واستردادٌ `UNKNOWN` كان لا يُحسم إلّا حين يحاول مشغّلٌ ردًّا آخر.
 *
 * ─── ما يفعله ───
 *
 *   · نيّةٌ `CREATED` أقدم من المهلة على طلبٍ ينتظر: يُسأل المزوّد. مقبوضٌ ⇒
 *     المسار نفسه الذي يسلكه الخطّاف (`OrderFinalizer` بمفتاح
 *     `provider:chargeId`)، فالخطّاف المتأخّر بعده مكرّرٌ بلا أثر، والفرق
 *     في المبلغ يُرفض تمويله كما يُرفض من الخطّاف. فاشلٌ نهائيًّا ⇒ `FAILED`
 *     فيُنشئ «ادفع الآن» رسمًا جديدًا لا يعيد رابطًا ميتًا.
 *   · استردادٌ مفتوح (`UNKNOWN`، أو `PENDING` قديم): `RefundService::resolveOpen`.
 *
 * لا يُنشئ رسمًا ولا استردادًا جديدًا أبدًا — يسأل ويحسم.
 */
final class ProviderRequery
{
    /** لا يُسأل عن نيّةٍ أحدث من هذا: الزبون ربّما ما زال في صفحة الدفع. */
    public const CHARGE_GRACE_MINUTES = 10;

    /** ولا عن نيّةٍ أقدم من هذا: Tap يُنهي الرسم غير المدفوع قبله بكثير. */
    public const CHARGE_HORIZON_DAYS = 7;

    public const REFUND_PENDING_GRACE_MINUTES = 10;

    /** نافذةُ اكتشاف الاستردادات من لوحة المزوّد: تحصيلاتُ هذه الأيّام. */
    public const PROVIDER_REFUND_WINDOW_DAYS = 30;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly OrderFinalizer $finalizer,
        private readonly RefundService $refunds,
    ) {}

    /**
     * @return array{chargesChecked: int, captured: int, unfundable: int, failed: int, open: int, unavailable: int, refundsChecked: int, refundsSettled: int}
     */
    public function run(int $limit = 100): array
    {
        $out = ['chargesChecked' => 0, 'captured' => 0, 'unfundable' => 0, 'failed' => 0, 'open' => 0, 'unavailable' => 0, 'refundsChecked' => 0, 'refundsSettled' => 0];

        $stale = Payment::query()
            ->where('state', 'CREATED')
            ->whereNotNull('intent_id')
            ->where('created_at', '<', now()->subMinutes(self::CHARGE_GRACE_MINUTES))
            ->where('created_at', '>', now()->subDays(self::CHARGE_HORIZON_DAYS))
            ->whereIn('order_id', Order::query()->select('id')->whereIn('status', ['PENDING', 'AWAITING_PAYMENT']))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($stale as $payment) {
            $out['chargesChecked']++;
            $outcome = $this->requeryCharge($payment);
            $out[$outcome]++;
        }

        $openRefunds = Refund::query()
            ->where(fn ($q) => $q->where('status', 'UNKNOWN')
                ->orWhere(fn ($q) => $q->where('status', 'PENDING')
                    ->where('updated_at', '<', now()->subMinutes(self::REFUND_PENDING_GRACE_MINUTES))))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($openRefunds as $refund) {
            $out['refundsChecked']++;
            $status = $this->refunds->resolveOpen($refund);
            if (in_array($status, ['SUCCEEDED', 'FAILED'], true)) {
                $out['refundsSettled']++;
            }
        }

        return $out;
    }

    /**
     * يكتشف ما رُدّ من لوحة المزوّد (أو من أيّ مكانٍ خارج التطبيق) بسؤال Tap
     * عن كلّ تحصيلٍ حديث — ولا ينتظر خطّافًا قد لا يصل.
     *
     * كلّ استردادٍ «مستردّ» لا نعرفه يُسجَّل عبر المسار نفسه الذي يسلكه خطّاف
     * الاسترداد (`RefundService::settleFromProvider`): محسومًا، بمصدر `provider`،
     * وبسقف الدفترين — فلا يبقى «غير مطابق» ولا يُردّ المال نفسه مرّتين.
     *
     * @return array{capturesChecked: int, refundsRecorded: int, unavailable: int}
     */
    public function discoverProviderRefunds(int $limit = 200): array
    {
        $out = ['capturesChecked' => 0, 'refundsRecorded' => 0, 'unavailable' => 0];

        $captures = Payment::query()
            ->where('state', 'CAPTURED')
            ->whereNotNull('intent_id')
            ->where('captured_at', '>', now()->subDays(self::PROVIDER_REFUND_WINDOW_DAYS))
            ->orderByDesc('captured_at')
            ->limit($limit)
            ->get();

        foreach ($captures as $payment) {
            $adapter = $this->providers->resolve((string) $payment->provider);
            if ($adapter === null) {
                continue;
            }
            $out['capturesChecked']++;

            try {
                $charge = $adapter->retrieveCharge((string) $payment->intent_id);
            } catch (ProviderUnavailable) {
                $out['unavailable']++;

                continue;
            }

            foreach ($charge->refunds as $refund) {
                if ($refund['status'] !== 'REFUNDED') {
                    continue;
                }
                $result = $this->refunds->settleFromProvider(
                    (string) $payment->provider, $refund['id'], 'REFUNDED',
                    (string) $payment->intent_id, $refund['amountMinor'], $refund['currency'],
                );
                if ($result === 'applied') {
                    $out['refundsRecorded']++;
                    Log::warning('payments.requery.provider_refund_discovered', [
                        'paymentId' => $payment->id, 'providerRefundId' => $refund['id'], 'amountMinor' => $refund['amountMinor'],
                    ]);
                }
            }
        }

        return $out;
    }

    /**
     * يسأل المزوّد عن نيّةٍ واحدة ويحسمها عبر المسار المحروس نفسه.
     * يُستعمل من الجدولة ومن الدفع قبل فتح رسمٍ ثانٍ للطلب.
     *
     * @return 'captured'|'unfundable'|'failed'|'open'|'unavailable'
     */
    public function requeryCharge(Payment $payment): string
    {
        $adapter = $this->providers->resolve((string) $payment->provider);
        if ($adapter === null) {
            return 'unavailable';
        }

        try {
            $charge = $adapter->retrieveCharge((string) $payment->intent_id);
        } catch (ProviderUnavailable $e) {
            Log::warning('payments.requery.unavailable', ['paymentId' => $payment->id, 'error' => $e->getMessage()]);

            return 'unavailable';
        }

        if ($charge->status === ProviderCharge::FAILED) {
            // نهائيٌّ بلا مال: الرابط ميت، و«ادفع الآن» يُنشئ رسمًا جديدًا.
            Payment::query()->whereKey($payment->id)->where('state', 'CREATED')->update(['state' => 'FAILED']);
            Log::info('payments.requery.charge_failed', ['paymentId' => $payment->id, 'providerStatus' => $charge->providerStatus]);

            return 'failed';
        }

        if ($charge->status === ProviderCharge::OPEN) {
            return 'open';
        }

        if ($charge->status !== ProviderCharge::CAPTURED) {
            // حالةٌ لا نعرفها: لا نجاح ولا فشل.
            return 'unavailable';
        }

        $order = Order::query()->findOrFail($payment->order_id);

        try {
            $this->finalizer->finalize(
                $order, (string) $payment->provider, (string) $payment->intent_id,
                ['source' => 'provider_requery', 'provider_status' => $charge->providerStatus],
                $charge->amountMinor, $charge->currency, $charge->amountProblem, $charge->merchantId,
            );
        } catch (PaymentUnfundable $e) {
            // المال سُجِّل ولم يُموِّل؛ التسوية تفتح الحادث.
            return 'unfundable';
        }

        Log::warning('payments.requery.recovered_capture', [
            'paymentId' => $payment->id, 'orderId' => $order->id, 'chargeId' => $payment->intent_id,
        ]);

        return 'captured';
    }
}
