<?php

namespace App\Domain\Content;

use App\Domain\Cart\CouponRules;
use App\Domain\Cart\Models\Coupon;
use App\Domain\Content\Models\Campaign;

/**
 * The promo strip: a live campaign, an advertised coupon, both, or neither.
 *
 * Both halves are resolved against REAL rows and re-validated on every request:
 *  · the campaign comes from `campaigns` and must still be inside its window;
 *  · the coupon is named by `home.promo_coupon` (an explicit editorial opt-in —
 *    coupons are not public by default) and must pass the SAME CouponRules the
 *    cart and checkout apply. An expired coupon, or one that has burned through
 *    its usage limit, is a code the visitor could not redeem, so it is not shown.
 *  · the coupon's description is Coupon::label() — «خصم ١٥٪» derived from the
 *    row's own type/value. No free text, so the strip cannot promise a discount
 *    the coupon does not actually give.
 */
final class PromoResolver
{
    /**
     * @return array{campaign?: array{title: string, description?: string, ends_at: string, url: string}, coupon?: array{code: string, description: string}}
     */
    public static function payload(): array
    {
        $out = [];

        // Soonest deadline first: the campaign a visitor could still miss.
        $campaign = Campaign::query()->live()->orderBy('ends_at')->first();

        if ($campaign !== null) {
            $out['campaign'] = array_filter([
                'title' => $campaign->title,
                'description' => $campaign->description,
                'ends_at' => $campaign->ends_at->toIso8601String(),
                'url' => $campaign->url,
            ], fn ($value) => $value !== null && $value !== '');

            /*
             * الشارة نصُّ المالك من كتلة `home.promo` (null حين لم يكتب)، و`endsAt`
             * الاسم الموحَّد لموعد الانتهاء نفسه — العدّ التنازليّ يعدّ إلى تاريخٍ حقيقيّ.
             */
            $out['campaign']['badge'] = HomeContent::promoBadge();
            $out['campaign']['endsAt'] = $campaign->ends_at->toIso8601String();
        }

        $coupon = self::advertisedCoupon();

        if ($coupon !== null) {
            $out['coupon'] = ['code' => $coupon->code, 'description' => $coupon->label()];
        }

        return $out;
    }

    private static function advertisedCoupon(): ?Coupon
    {
        $code = HomeContent::promotedCouponCode();
        if ($code === null) {
            return null;
        }

        $coupon = Coupon::query()->where('code', $code)->first();

        return $coupon !== null && CouponRules::usable($coupon) ? $coupon : null;
    }
}
