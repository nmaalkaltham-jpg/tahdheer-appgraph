<?php

namespace App\Domain\Fulfillment\Executors;

use App\Domain\Assets\Models\AssetSource;
use App\Domain\Entitlements\EntitlementService;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * التسليم الرقميّ: استحقاقٌ يُمنح فور ثبوت الدفع (§١٧ §١٨).
 */
class DigitalDeliveryExecutor implements FulfillmentExecutor
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function strategies(): array
    {
        /*
         * `GOOGLE_DRIVE_DELIVERY` خرجت من هنا إلى منفّذها.
         *
         * كانت تسلك هذا المسار بالضبط، وفحصُه يقرأ حالةً **مخزَّنة** من آخر
         * مزامنة. وملفّ Drive يملكه غيرنا: يُحذف أو يُسحب إذنه بلا خبر، فتبقى
         * الحالة المخزَّنة تقول «سليم» بثقة. ولذلك صار له منفّذٌ يُعيد التحقّق
         * لحظة التسليم.
         */
        return [
            'DIGITAL_DOWNLOAD', 'CLOUD_FILE_DELIVERY',
            'OBJECT_STORAGE_DELIVERY', 'EXTERNAL_SECURE_LINK', 'ACCOUNT_ENTITLEMENT',
            'STREAMING_ACCESS',
        ];
    }

    public function execute(FulfillmentUnit $unit, Order $order, OrderItem $item): FulfillmentOutcome
    {
        if ($order->customer_id === null) {
            return FulfillmentOutcome::failed('لا زبون على الطلب — لا يُمنح استحقاقٌ بلا مالك.');
        }

        /*
         * مصدر الأصل يُفحص قبل المنح لا بعده (§١٥): مصدرٌ مفقودٌ أو مسحوب
         * الإذن يعني استحقاقًا يقود إلى تنزيلٍ فاشل. الفشل هنا مرئيٌّ في
         * اللوحة؛ هناك يظهر شكوى زبون.
         */
        $blocked = AssetSource::query()
            ->whereIn('asset_id', function ($q) use ($item) {
                $q->select('asset_id')->from('product_files')->where('product_id', $item->product_id);
            })
            ->blocking()
            ->first();

        if ($blocked !== null) {
            return FulfillmentOutcome::failed("مصدر الأصل في حالة {$blocked->sync_state}: {$blocked->last_error}");
        }

        return FulfillmentOutcome::fulfilled(
            entitlementId: $this->entitlements->grantForItem($order, $item)->id,
        );
    }

    /**
     * الاستحقاق يُلغى بسياسته في `EntitlementService`.
     *
     * `null` هنا ليست تقصيرًا: خدمة الاسترداد تُلغي استحقاقات الطلب كلّها
     * بسياسة السحب المختارة (فوريّة أو بمهلة)، وإلغاؤه هنا ثانيةً كان
     * سيتجاوز تلك السياسة — فيفقد من اشترى مهلته المعلَنة.
     */
    public function revoke(FulfillmentUnit $unit, Order $order, OrderItem $item, string $reason): ?string
    {
        return $unit->entitlement_id === null
            ? null
            : "الاستحقاق {$unit->entitlement_id} يُلغى بسياسة السحب";
    }
}
