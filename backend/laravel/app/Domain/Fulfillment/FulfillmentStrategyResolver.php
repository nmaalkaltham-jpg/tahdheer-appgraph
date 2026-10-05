<?php

namespace App\Domain\Fulfillment;

use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductCapabilities;
use App\Models\Product;
use RuntimeException;

/**
 * اشتقاق استراتيجيّات التسليم — مشترَكٌ بين السلّة والطلب (§٨ §١٠٠).
 *
 * استُخرج من المنسّق لأنّ السؤال يُطرح مرّتين في حياة البند: عند الدفع
 * ليُعرف ما يُطلب من الزبون (عنوانٌ؟ موعدٌ؟ لا شيء؟)، وبعد السداد ليُعرف
 * ما يُنفَّذ. نسختان من المنطق كانتا ستفترقان، فيَعِد الدفعُ بشيءٍ وينفّذ
 * التسليمُ غيره — وهو أسوأ أنواع الاختلاف لأنّه يظهر بعد أخذ المال.
 *
 * السلسلة تنزل من الأوثق إلى الأضعف، ولا تنتهي بافتراض.
 */
class FulfillmentStrategyResolver
{
    public function resolve(?int $offerId, int $productId): FulfillmentPlan
    {
        // ١ — عرضٌ مربوط صراحةً: أوثق مصدر، فهو ما اختاره الزبون.
        if ($offerId !== null) {
            $strategies = $this->strategiesOf($offerId);
            if ($strategies !== []) {
                return new FulfillmentPlan($strategies, 'line.offer');
            }
        }

        // ٢ — جسر المنتج القديم: ما سبق النموذج الكونيّ.
        $variant = ProductVariant::query()->where('legacy_product_id', $productId)->first();
        if ($variant !== null) {
            $offer = ProductOffer::query()
                ->where('product_variant_id', $variant->id)
                ->orderByRaw("case when status = 'active' then 0 else 1 end")
                ->orderBy('id')
                ->first();

            if ($offer !== null) {
                $strategies = $this->strategiesOf((int) $offer->id);
                if ($strategies !== []) {
                    return new FulfillmentPlan($strategies, 'legacy_bridge.offer');
                }
            }
        }

        // ٣ — العمود القديم وحده. أضعف مصدر، ويُسجَّل بوصفه كذلك.
        /*
         * الترجمة تمرّ بسجلّ القدرات لا بجدولٍ رابع هنا.
         *
         * كانت `match` تعرف ثلاثًا من ستّ قيمٍ قديمة، فمنتجٌ `access` بلا عرضٍ
         * يوقف الدفع برسالة «تعذّر اشتقاق استراتيجيّة تسليم» — والجواب الصحيح
         * على بُعد نداء. و`hybrid` و`composite` ما يزالان يرميان، وذاك صواب:
         * الهجين يوجب اختيارًا صريحًا من عالمين، والمركَّب تحكمه مكوّناته.
         */
        $nature = ProductCapabilities::natureOfLegacy(
            Product::query()->whereKey($productId)->value('fulfillment_type'),
        );

        $mapped = $nature === null ? null : ProductCapabilities::defaultStrategyFor($nature);

        if ($mapped !== null) {
            return new FulfillmentPlan([$mapped], 'legacy_product.fulfillment_type');
        }

        throw new RuntimeException(
            "تعذّر اشتقاق استراتيجيّة تسليمٍ للمنتج {$productId}. "
            .'لا تسليم بالظنّ — اربط البند بعرضٍ أو صحّح المنتج.'
        );
    }

    /** @return list<string> */
    private function strategiesOf(int $offerId): array
    {
        return OfferFulfillment::query()
            ->where('product_offer_id', $offerId)
            ->orderBy('position')
            ->pluck('strategy')
            ->all();
    }
}
