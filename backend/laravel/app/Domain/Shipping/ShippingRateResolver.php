<?php

namespace App\Domain\Shipping;

use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;

/**
 * اختيار قاعدة الشحن لوجهةٍ ووزنٍ وقيمة (§٣١).
 *
 * «لا تدفن منطق الشحن في واجهة الدفع»: القرار هنا يُقرأ ويُختبر ويُراجَع،
 * ونتيجته سعرٌ واحد له سببٌ يُشار إليه بصفٍّ في قاعدة البيانات.
 *
 * والمنطقة الأخصّ تفوز: قاعدةٌ لمنطقة الرياض وحدها تسبق قاعدةً لكلّ
 * السعوديّة. بغير ترتيبٍ صريح كان السعر يتبدّل بتبدّل ترتيب الصفوف.
 */
class ShippingRateResolver
{
    /**
     * المناطق التي تغطّي هذه الوجهة، من الأخصّ إلى الأعمّ.
     *
     * @return list<ShippingZone>
     */
    public function zonesFor(string $countryCode, ?string $region = null): array
    {
        $zones = ShippingZone::query()->where('active', true)->orderByDesc('priority')->get()
            ->filter(function (ShippingZone $zone) use ($countryCode, $region) {
                if (! in_array($countryCode, (array) $zone->country_codes, true)) {
                    return false;
                }

                // مناطق فارغة = كلّ الدولة. وإلّا فالمطابقة على المنطقة.
                $regions = $zone->regions;
                if ($regions === null || $regions === []) {
                    return true;
                }

                return $region !== null && in_array($region, $regions, true);
            });

        /*
         * الأخصّ أوّلًا: منطقةٌ تُسمّي مناطق أضيق من منطقةٍ تشمل الدولة
         * كلّها، مهما كانت الأولويّة المكتوبة. الأولويّة تفصل بين المتساويين.
         */
        return $zones->sortBy([
            fn (ShippingZone $a, ShippingZone $b) => $this->specificity($b) <=> $this->specificity($a),
            fn (ShippingZone $a, ShippingZone $b) => $b->priority <=> $a->priority,
        ])->values()->all();
    }

    /**
     * أرخص قاعدةٍ صالحة، وإلّا null.
     *
     * null تعني «لا نشحن إلى هنا» صراحةً. لا سعر افتراضيّ ولا صفر: شحنةٌ
     * بصفرٍ لأنّ أحدًا لم يضع قاعدةً خسارةٌ صامتة في كلّ طلب.
     *
     * @param  list<int>  $shippingClassIds  أصناف ما في السلّة
     */
    public function cheapestFor(
        string $countryCode,
        ?string $region,
        int $weightGrams,
        int $orderMinor,
        array $shippingClassIds = [],
    ): ?ShippingRate {
        foreach ($this->zonesFor($countryCode, $region) as $zone) {
            $candidates = ShippingRate::query()
                ->active()
                ->where('shipping_zone_id', $zone->id)
                ->with('carrier')
                ->get()
                ->filter(function (ShippingRate $rate) use ($weightGrams, $orderMinor, $shippingClassIds) {
                    if (! $rate->covers($weightGrams, $orderMinor)) {
                        return false;
                    }

                    /*
                     * قاعدةٌ بلا صنف تصلح للجميع. وقاعدةٌ بصنفٍ تصلح فقط حين
                     * يكون **كلّ** ما في السلّة من صنفها — وإلّا شُحن الهشّ
                     * بقاعدة العاديّ.
                     */
                    if ($rate->shipping_class_id === null) {
                        return true;
                    }

                    return $shippingClassIds !== []
                        && array_unique($shippingClassIds) === [$rate->shipping_class_id];
                });

            if ($candidates->isEmpty()) {
                continue; // جرّب المنطقة الأعمّ
            }

            return $candidates->sortBy([
                fn (ShippingRate $a, ShippingRate $b) => $a->priceFor($orderMinor) <=> $b->priceFor($orderMinor),
                fn (ShippingRate $a, ShippingRate $b) => $b->priority <=> $a->priority,
            ])->first();
        }

        return null;
    }

    /** كم منطقةً تسمّي المنطقة صراحةً — مقياسُ الخصوصيّة. */
    private function specificity(ShippingZone $zone): int
    {
        return count((array) ($zone->regions ?? []));
    }
}
