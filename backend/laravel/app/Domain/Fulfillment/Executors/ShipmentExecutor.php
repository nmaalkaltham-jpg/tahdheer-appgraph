<?php

namespace App\Domain\Fulfillment\Executors;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShipmentItem;
use App\Domain\Shipping\ShippingRateResolver;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * إنشاء شحنة من وحدة تنفيذ (§٢٦ §٣١).
 *
 * الشحنة تُنشأ في حالة `PENDING`: أنشأناها ولم يستلمها ناقل بعد. رفعُها إلى
 * `LABEL_CREATED` فما فوق يقع حين يُصدر الناقل بوليصة — أي بفعلٍ خارجيّ
 * حقيقيّ، لا بانتهاء هذه الدالّة.
 *
 * وغيابُ العنوان أو قاعدة السعر **انتظارٌ لا فشل**: كلاهما بياناتٌ يضيفها
 * المشغّل أو الزبون، ولا عطل في النظام يُصلَح.
 */
class ShipmentExecutor implements FulfillmentExecutor
{
    public function __construct(private readonly ShippingRateResolver $rates) {}

    public function strategies(): array
    {
        return ['PHYSICAL_SHIPMENT', 'COURIER_DELIVERY', 'FREIGHT', 'DROPSHIP'];
    }

    public function execute(FulfillmentUnit $unit, Order $order, OrderItem $item): FulfillmentOutcome
    {
        $address = DB::table('order_addresses')
            ->where('order_id', $order->id)->where('kind', 'SHIPPING')->first();

        if ($address === null) {
            return FulfillmentOutcome::pending('لا عنوان شحنٍ على الطلب — لا وجهة تُشحن إليها (§٣٢).');
        }

        $variant = $item->product_variant_id === null
            ? ProductVariant::query()->where('legacy_product_id', $item->product_id)->first()
            : ProductVariant::query()->find($item->product_variant_id);

        /*
         * وزنٌ مجهول لا يُقدَّر. تقديرُه يُنتج سعرًا خاطئًا يُحصَّل من الزبون
         * أو يُخسر على المتجر، وكلاهما خطأٌ صامت (§١٥٨).
         */
        $weight = $variant?->weight_grams;
        if ($weight === null) {
            return FulfillmentOutcome::pending(
                'لا وزن مسجَّل على الصيغة — الوزن يُقاس ولا يُقدَّر (§٢٧).'
            );
        }

        $quantity = (int) ($item->quantity ?? 1);
        $rate = $this->rates->cheapestFor(
            countryCode: $address->country_code,
            region: $address->region,
            weightGrams: $weight * $quantity,
            orderMinor: (int) $order->total_minor,
            shippingClassIds: $variant?->shipping_class_id === null ? [] : [(int) $variant->shipping_class_id],
        );

        if ($rate === null) {
            return FulfillmentOutcome::pending(
                "لا قاعدة شحنٍ تغطّي «{$address->city}» بهذا الوزن — عرّف منطقةً وقاعدة سعر (§٣١)."
            );
        }

        return DB::transaction(function () use ($unit, $order, $item, $rate, $weight, $quantity) {
            $key = "shipment:unit:{$unit->id}";

            $shipment = Shipment::query()->firstOrCreate(
                ['idempotency_key' => $key],
                [
                    'order_id' => $order->id,
                    'fulfillment_unit_id' => $unit->id,
                    'carrier_id' => $rate->carrier_id,
                    'inventory_location_id' => InventoryLocation::query()->sellable()->value('id'),
                    'service_name' => $rate->service_name,
                    'status' => 'PENDING',
                    'shipping_cost_minor' => $rate->priceFor((int) $order->total_minor),
                    'weight_grams' => $weight * $quantity,
                    'estimated_delivery_at' => $rate->max_days === null ? null : now()->addDays($rate->max_days),
                ],
            );

            ShipmentItem::query()->firstOrCreate(
                ['shipment_id' => $shipment->id, 'order_item_id' => $item->id],
                ['quantity' => $quantity],
            );

            /*
             * الوحدة تُغلق بـ`FULFILLED` بمعنى «سُلّمت إلى مسار الشحن»، لا
             * «وصلت الزبون». الوصول تتبعه حالة الشحنة نفسها، وهما سؤالان
             * مختلفان: الأوّل مسؤوليّتنا والثاني مسؤوليّة الناقل.
             */
            return FulfillmentOutcome::fulfilled(shipmentId: $shipment->id);
        });
    }

    /**
     * الشحنة لا تُسحب بأمرٍ في قاعدة بيانات.
     *
     * ما لم يُشحن بعد يُلغى فعلًا. وما سُلِّم أو صار في الطريق فالبضاعة عند
     * الزبون أو عند الناقل: تعليمُه «مسحوبًا» يجعل السجلّ يقول إنّ شيئًا
     * عاد ولم يعد شيء. فيُقال الصدق ويُترك الارتجاع لمساره (§٢٦).
     */
    public function revoke(FulfillmentUnit $unit, Order $order, OrderItem $item, string $reason): ?string
    {
        $shipment = Shipment::query()
            ->where('fulfillment_unit_id', $unit->id)
            ->lockForUpdate()
            ->first();

        if ($shipment === null) {
            return null;
        }

        /*
         * ما خرج أو انتهى لا يُعاد كتابته.
         *
         * القائمة السابقة كانت «سُلِّمت أو في الطريق» وحدها، فيسقط عليها
         * `RETURNED` و`FAILED` و`CANCELLED`: فتُدهَس حالةٌ نهائيّة إلى
         * `CANCELLED` خارج آلة الحالات — بلا تدقيق ولا حدث ولا فحص انتقال.
         * وشحنةٌ ارتدّت فعلًا تُعلَن «ملغاة» فيضيع أثر الارتجاع.
         *
         * والقاعدة تُقلَب: يُلغى ما لم يغادر فقط، وما عداه يُقال عنه الصدق.
         */
        /* الحالات الحقيقيّة قبل المغادرة، كما يقبلها القيد في المحرّك. */
        $notYetGone = ['PENDING', 'LABEL_CREATED'];

        if (! in_array($shipment->status, $notYetGone, true)) {
            return "الشحنة {$shipment->id} في حالة {$shipment->status} — تُرتجَع ولا تُسحب";
        }

        $shipment->update(['status' => 'CANCELLED']);

        return "أُلغيت الشحنة {$shipment->id} قبل مغادرتها";
    }
}
