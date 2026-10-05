<?php

namespace App\Domain\Checkout;

use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\ProductCapabilities;
use App\Domain\Fulfillment\FulfillmentStrategyResolver;
use Throwable;

/**
 * يقرأ السلّة ويقول ما يلزم لإتمامها (§١٠٠).
 *
 * يستعمل محدِّد الاستراتيجيّات نفسه الذي يستعمله المنسّق بعد السداد، فما
 * يَعِد به الدفعُ هو ما يُنفَّذ بالضبط.
 */
class CheckoutRequirementsResolver
{
    public function __construct(private readonly FulfillmentStrategyResolver $resolver) {}

    public function for(Cart $cart): CheckoutRequirements
    {
        $strategies = [];
        $unresolvable = [];

        /*
         * ─── الطريق السريع: استراتيجيّاتُ العرض محمَّلةً دفعةً واحدة ───
         *
         * يُنادى هذا التابع في **كلّ** عرضٍ للسلّة. والمحدِّد يستعلم لكلّ بند
         * (استراتيجيّاتُ العرض، ثمّ جسرُ المنتج القديم، ثمّ عمودُه) — أي
         * ثلاثةَ استعلاماتٍ لكلّ سطر في أسوأ الأحوال. سلّةٌ من عشرة أسطر
         * تدفع ثلاثين استعلامًا لتُجيب سؤالًا واحدًا.
         *
         * والتحميل المسبق يُجيب عن الغالب — بندٌ مربوطٌ بعرضٍ له تسليم —
         * باستعلامٍ واحد لكلّ السلّة. وما لا يُجاب عنه يهبط إلى المحدِّد
         * كما كان، فلا تُفقَد دقّة ولا تُفقَد نسبةُ المصدر.
         */
        $cart->loadMissing('items.offer.fulfillments');

        foreach ($cart->items as $item) {
            $preloaded = $item->offer?->fulfillments;

            if ($preloaded !== null && $preloaded->isNotEmpty()) {
                $strategies = [...$strategies, ...$preloaded->pluck('strategy')->all()];

                continue;
            }

            try {
                $plan = $this->resolver->resolve(
                    $item->product_offer_id === null ? null : (int) $item->product_offer_id,
                    (int) $item->product_id,
                );
                $strategies = [...$strategies, ...$plan->strategies];
            } catch (Throwable $e) {
                /*
                 * بندٌ لا تُشتقّ له استراتيجيّة يوقف الدفع بدل أن يُفترض
                 * رقميًّا. اكتشافُه هنا رسالةٌ للمشغّل؛ واكتشافُه بعد السداد
                 * طلبٌ عالق ومالٌ مأخوذ.
                 */
                $unresolvable[] = "البند «{$item->product_id}»: {$e->getMessage()}";
            }
        }

        $strategies = array_values(array_unique($strategies));

        return new CheckoutRequirements(
            strategies: $strategies,
            requiresShippingAddress: array_intersect($strategies, ProductCapabilities::SHIPPED) !== [],
            requiresBookingDetails: array_intersect($strategies, ProductCapabilities::SCHEDULED) !== [],
            isMixed: count($strategies) > 1,
            unresolvable: $unresolvable,
        );
    }
}
