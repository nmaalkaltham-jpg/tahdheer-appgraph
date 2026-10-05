<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Shipping\Models\Carrier;
use App\Domain\Shipping\Models\ShippingClass;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Shipping\ShippingRateResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * مناطق الشحن وقواعده — ومعاينة ما سيقرّره المحلّل.
 *
 * ─── لماذا هذه الشاشة ───
 *
 * `ShippingRateResolver` كامل ومختبَر، ولم يكن لبياناته سطحُ إدارة: المناطق
 * والقواعد تُزرع بذورًا أو تُكتب في قاعدة البيانات يدويًّا. أي أنّ تغيير
 * سعر شحنٍ — وهو قرارٌ تجاريّ يوميّ — كان يمرّ خارج التدقيق وخارج الصلاحيات.
 *
 * ─── لماذا المعاينة ───
 *
 * قواعد الشحن نظامُ أسبقيّاتٍ متشابك: الأخصّ يغلب الأعمّ، والأولويّة تفصل
 * بين المتساويين، والقاعدة ذات الصنف لا تصلح إلّا حين تكون السلّة كلّها من
 * صنفها. لا أحد يستطيع حساب ذلك في رأسه، والخطأ فيه لا يظهر إلّا في طلبٍ
 * حقيقيّ — إمّا بسعرٍ يخسر وإمّا برفض شحنٍ ممكن.
 *
 * المعاينة تسأل المحلّل **نفسه** لا نسخةً منه: نسختان تتباعدان، والمعاينة
 * التي تكذب أسوأ من غيابها.
 *
 * ─── لماذا لا حذف للمنطقة ذات القواعد ───
 *
 * حذفُها يُيتّم قواعدها فتختفي من الحلّ بلا أثر، فيتحوّل الطلب إلى «لا نشحن
 * إلى هنا» ولا يعرف أحدٌ لماذا. التعطيل صريحٌ ومرئيّ، والحذف ليس كذلك.
 */
class ShippingController extends Controller
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly ShippingRateResolver $resolver,
    ) {}

    public function index(): JsonResponse
    {
        $zones = ShippingZone::query()->orderByDesc('priority')->orderBy('code')->get();
        $rates = ShippingRate::query()->with(['carrier:id,code,name', 'shippingClass:id,code,name'])->get();

        return response()->json([
            'zones' => $zones->map(fn (ShippingZone $z) => [
                'id' => $z->id,
                'code' => $z->code,
                'name' => $z->name,
                'countryCodes' => (array) $z->country_codes,
                'regions' => (array) ($z->regions ?? []),
                'priority' => (int) $z->priority,
                'active' => (bool) $z->active,
                'rateCount' => $rates->where('shipping_zone_id', $z->id)->count(),
            ]),
            'rates' => $rates->map(fn (ShippingRate $r) => [
                'id' => $r->id,
                'zoneId' => $r->shipping_zone_id,
                'carrier' => $r->carrier?->name,
                'shippingClass' => $r->shippingClass?->name,
                'serviceName' => $r->service_name,
                'minWeightGrams' => $r->min_weight_grams,
                'maxWeightGrams' => $r->max_weight_grams,
                'minOrderMinor' => $r->min_order_minor,
                'maxOrderMinor' => $r->max_order_minor,
                'priceMinor' => (int) $r->price_minor,
                'freeOverMinor' => $r->free_over_minor,
                'minDays' => $r->min_days,
                'maxDays' => $r->max_days,
                'priority' => (int) $r->priority,
                'active' => (bool) $r->active,
            ]),
            'carriers' => Carrier::query()->orderBy('name')->get(['id', 'code', 'name']),
            'classes' => ShippingClass::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function storeZone(Request $request, AdminUser $actor): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:32|unique:shipping_zones,code',
            'name' => 'required|string|max:120',
            'countryCodes' => 'required|array|min:1',
            'countryCodes.*' => 'required|string|size:2',
            'regions' => 'sometimes|array',
            'regions.*' => 'required|string|max:120',
            'priority' => 'sometimes|integer|min:0|max:1000',
            'active' => 'sometimes|boolean',
        ]);

        $zone = DB::transaction(function () use ($data, $actor, $request) {
            $zone = ShippingZone::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'country_codes' => array_map(mb_strtoupper(...), $data['countryCodes']),
                'regions' => $data['regions'] ?? [],
                'priority' => $data['priority'] ?? 0,
                'active' => $data['active'] ?? true,
            ]);

            $this->audit->log(
                $actor, 'shipping.zone.created', 'shipping_zone', $zone->id,
                null, $zone->toArray(), $request->input('reason'), $request,
            );

            return $zone;
        });

        return response()->json(['id' => $zone->id], 201);
    }

    public function updateZone(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $zone = ShippingZone::query()->find($id);

        if ($zone === null) {
            return response()->json(['message' => 'لا منطقة شحنٍ بهذا المعرّف.'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'countryCodes' => 'sometimes|array|min:1',
            'countryCodes.*' => 'required|string|size:2',
            'regions' => 'sometimes|array',
            'regions.*' => 'required|string|max:120',
            'priority' => 'sometimes|integer|min:0|max:1000',
            'active' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($zone, $data, $actor, $request) {
            $before = $zone->toArray();

            $zone->fill(array_filter([
                'name' => $data['name'] ?? null,
                'priority' => $data['priority'] ?? null,
            ], fn ($v) => $v !== null));

            if (array_key_exists('countryCodes', $data)) {
                $zone->country_codes = array_map(mb_strtoupper(...), $data['countryCodes']);
            }

            if (array_key_exists('regions', $data)) {
                $zone->regions = $data['regions'];
            }

            if (array_key_exists('active', $data)) {
                $zone->active = $data['active'];
            }

            $zone->save();

            $this->audit->log(
                $actor, 'shipping.zone.updated', 'shipping_zone', $zone->id,
                $before, $zone->toArray(), $request->input('reason'), $request,
            );
        });

        return response()->json(['id' => $zone->id]);
    }

    public function storeRate(Request $request, AdminUser $actor): JsonResponse
    {
        $data = $request->validate($this->rateRules($request) + [
            'zoneId' => 'required|integer|exists:shipping_zones,id',
        ]);

        $rate = DB::transaction(function () use ($data, $actor, $request) {
            $rate = ShippingRate::create($this->rateAttributes($data) + [
                'shipping_zone_id' => $data['zoneId'],
            ]);

            $this->audit->log(
                $actor, 'shipping.rate.created', 'shipping_rate', $rate->id,
                null, $rate->toArray(), $request->input('reason'), $request,
            );

            return $rate;
        });

        return response()->json(['id' => $rate->id], 201);
    }

    public function updateRate(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $rate = ShippingRate::query()->find($id);

        if ($rate === null) {
            return response()->json(['message' => 'لا قاعدة شحنٍ بهذا المعرّف.'], 404);
        }

        $data = $request->validate($this->rateRules($request, $rate));

        DB::transaction(function () use ($rate, $data, $actor, $request) {
            $before = $rate->toArray();
            $rate->fill($this->rateAttributes($data));
            $rate->save();

            $this->audit->log(
                $actor, 'shipping.rate.updated', 'shipping_rate', $rate->id,
                $before, $rate->toArray(), $request->input('reason'), $request,
            );
        });

        return response()->json(['id' => $rate->id]);
    }

    /**
     * معاينة: ماذا سيقرّر المحلّل لهذه الوجهة والسلّة؟
     *
     * تُنادي `cheapestFor` نفسها. و`null` تُعرض «لا شحن إلى هنا» صراحةً —
     * لا صفرًا ولا سعرًا افتراضيًّا: شحنةٌ بصفرٍ لأنّ أحدًا لم يضع قاعدةً
     * خسارةٌ صامتة في كلّ طلب.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'countryCode' => 'required|string|size:2',
            'region' => 'sometimes|nullable|string|max:120',
            'weightGrams' => 'required|integer|min:0|max:1000000',
            'orderMinor' => 'required|integer|min:0',
            'shippingClassIds' => 'sometimes|array',
            'shippingClassIds.*' => 'required|integer|exists:shipping_classes,id',
        ]);

        $country = mb_strtoupper($data['countryCode']);
        $region = $data['region'] ?? null;

        $zones = $this->resolver->zonesFor($country, $region);

        $rate = $this->resolver->cheapestFor(
            $country, $region,
            (int) $data['weightGrams'], (int) $data['orderMinor'],
            array_map('intval', $data['shippingClassIds'] ?? []),
        );

        return response()->json([
            // ترتيب المناطق جزءٌ من الجواب: هو ما يشرح **لماذا** فازت قاعدة.
            'zones' => array_map(fn (ShippingZone $z) => [
                'id' => $z->id, 'code' => $z->code, 'name' => $z->name,
                'regions' => (array) ($z->regions ?? []),
            ], $zones),
            'rate' => $rate === null ? null : [
                'id' => $rate->id,
                'zoneId' => $rate->shipping_zone_id,
                'carrier' => $rate->carrier?->name,
                'serviceName' => $rate->service_name,
                'priceMinor' => $rate->priceFor((int) $data['orderMinor']),
                'listPriceMinor' => (int) $rate->price_minor,
                'freeOverMinor' => $rate->free_over_minor,
                'minDays' => $rate->min_days,
                'maxDays' => $rate->max_days,
            ],
            'shippable' => $rate !== null,
        ]);
    }

    /** @return array<string, mixed> */
    /**
     * قواعد قاعدة الشحن — والترتيب منها لا من المحرّك.
     *
     * ─── ثلاثة عيوب كانت هنا، وكلّها تظهر ٥٠٠ لا ٤٢٢ ───
     *
     * ١ · `kind` كان يُتحقَّق منه ولا يُخزَّن: لا عمود له في
     *     `shipping_rates`، و`rateAttributes` لا يذكره. أي حقلٌ تَعِد
     *     الواجهة بقبوله ثمّ يُسقَط صامتًا — وهو أسوأ من رفضه.
     *
     * ٢ · `minWeightGrams` و`minOrderMinor` كانا `nullable` بينما العمودان
     *     `NOT NULL` بافتراضٍ صفر. فـ`null` صريحة تمرّ التحقّق ويردّها
     *     المحرّك، فيصل المشغّل «فشل الطلب (500).».
     *
     * ٣ · لا ترتيب بين الحدّين هنا، والقاعدة ترتّبهما بقيودٍ صريحة
     *     (`shipping_rates_weight_range_ordered` وأختاها). فنطاقٌ مقلوب
     *     يمرّ ثمّ يسقط على قيد — ٥٠٠ أخرى بلا اسم حقل.
     *
     * والمقارنة تُحلّ بقيمة الطلب إن حضرت، وإلّا بقيمة الصفّ القائم عند
     * التعديل، وإلّا بافتراض العمود. فتعديلٌ يرسل السقف وحده يُقارَن بالقاع
     * المحفوظ لا بالعدم.
     *
     * @param  ShippingRate|null  $current  الصفّ عند التعديل، و`null` عند الإنشاء.
     * @return array<string, mixed>
     */
    private function rateRules(Request $request, ?ShippingRate $current = null): array
    {
        $floor = function (string $input, string $column, int $default) use ($request, $current): ?int {
            if ($request->has($input)) {
                $value = $request->input($input);

                return $value === null ? null : (int) $value;
            }

            return $current === null ? $default : (int) $current->{$column};
        };

        /** يردّ ٤٢٢ باسم الحقل بدل قيدٍ في المحرّك يردّ ٥٠٠ بلا اسم. */
        $ordered = function (string $label, ?int $floorValue, bool $strict) {
            return function (string $attribute, $value, $fail) use ($label, $floorValue, $strict) {
                if ($value === null || $floorValue === null) {
                    return;
                }

                $ok = $strict ? (int) $value > $floorValue : (int) $value >= $floorValue;

                if (! $ok) {
                    $fail($strict
                        ? "يجب أن يكون {$label} أكبر من الحدّ الأدنى ({$floorValue})."
                        : "يجب ألّا يقلّ {$label} عن الحدّ الأدنى ({$floorValue}).");
                }
            };
        };

        return [
            'carrierId' => 'required|integer|exists:carriers,id',
            'shippingClassId' => 'sometimes|nullable|integer|exists:shipping_classes,id',
            'serviceName' => 'required|string|max:120',
            /* العمودان `NOT NULL` بافتراضٍ صفر — فلا `nullable` تَعِد بما يُرَدّ. */
            'minWeightGrams' => 'sometimes|integer|min:0',
            'maxWeightGrams' => [
                'sometimes', 'nullable', 'integer', 'min:0',
                $ordered('أقصى وزن', $floor('minWeightGrams', 'min_weight_grams', 0), true),
            ],
            'minOrderMinor' => 'sometimes|integer|min:0',
            'maxOrderMinor' => [
                'sometimes', 'nullable', 'integer', 'min:0',
                $ordered('أقصى قيمة طلب', $floor('minOrderMinor', 'min_order_minor', 0), true),
            ],
            'priceMinor' => 'required|integer|min:0',
            'freeOverMinor' => 'sometimes|nullable|integer|min:0',
            'minDays' => 'sometimes|nullable|integer|min:0|max:365',
            /* المدّة قيدُها `>=`: يومان إلى يومين نطاقٌ مشروع. */
            'maxDays' => [
                'sometimes', 'nullable', 'integer', 'min:0', 'max:365',
                $ordered('أكثر مدّة', $floor('minDays', 'min_days', 0), false),
            ],
            'priority' => 'sometimes|integer|min:0|max:1000',
            'active' => 'sometimes|boolean',
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function rateAttributes(array $data): array
    {
        $map = [
            'carrierId' => 'carrier_id',
            'shippingClassId' => 'shipping_class_id',
            'serviceName' => 'service_name',
            'minWeightGrams' => 'min_weight_grams',
            'maxWeightGrams' => 'max_weight_grams',
            'minOrderMinor' => 'min_order_minor',
            'maxOrderMinor' => 'max_order_minor',
            'priceMinor' => 'price_minor',
            'freeOverMinor' => 'free_over_minor',
            'minDays' => 'min_days',
            'maxDays' => 'max_days',
            'priority' => 'priority',
            'active' => 'active',
        ];

        $attributes = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        return $attributes;
    }
}
