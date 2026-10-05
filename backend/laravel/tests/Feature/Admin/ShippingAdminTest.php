<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Shipping\Models\Carrier;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * سطح إدارة الشحن — والمعاينة التي تسأل المحلّل نفسه.
 *
 * المحلّل كان كاملًا ومختبَرًا ولبياناته لا سطح: تغييرُ سعر شحنٍ — قرارٌ
 * تجاريّ يوميّ — كان يمرّ خارج التدقيق وخارج الصلاحيات.
 */
class ShippingAdminTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function carrier(): Carrier
    {
        return Carrier::create(['code' => 'C-'.uniqid(), 'name' => 'ناقل']);
    }

    private function zone(array $overrides = []): ShippingZone
    {
        // الاتّحاد يُبقي **اليسار**: الافتراضات على اليمين وإلّا لم تُتجاوَز.
        return ShippingZone::create($overrides + [
            'code' => 'Z-'.uniqid(), 'name' => 'المملكة',
            'country_codes' => ['SA'], 'regions' => [],
            'priority' => 0, 'active' => true,
        ]);
    }

    // ── القراءة ──────────────────────────────────────────────────────────

    public function test_the_index_requires_order_read(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::SeoManager));
        $this->getJson('/api/v1/admin/shipping')->assertStatus(403);
    }

    public function test_the_index_counts_zones_without_rates(): void
    {
        $zone = $this->zone();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/shipping')
            ->assertOk()
            ->assertJsonPath('zones.0.code', $zone->code)
            // منطقةٌ فعّالة بلا قاعدة ترفض الشحن صامتةً — تُعدّ لتُرى.
            ->assertJsonPath('zones.0.rateCount', 0);
    }

    // ── الكتابة ──────────────────────────────────────────────────────────

    public function test_creating_a_zone_requires_the_shipping_permission(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $this->postJson('/api/v1/admin/shipping/zones', [
            'code' => 'GULF', 'name' => 'الخليج', 'countryCodes' => ['AE'],
        ])->assertStatus(403);
    }

    public function test_a_zone_is_created_and_audited(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/shipping/zones', [
            'code' => 'GULF', 'name' => 'الخليج',
            'countryCodes' => ['ae', 'kw'], 'priority' => 5,
        ])->assertStatus(201);

        $zone = ShippingZone::query()->where('code', 'GULF')->firstOrFail();
        // رموز الدول تُرفع حالتُها: المحلّل يقارن حرفيًّا بـ`SA` الكبيرة.
        $this->assertSame(['AE', 'KW'], $zone->country_codes);

        $this->assertSame(1, DB::table('admin_audit_log')
            ->where('action', 'shipping.zone.created')->count());
    }

    public function test_a_duplicate_zone_code_is_refused(): void
    {
        $this->zone(['code' => 'GULF']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/shipping/zones', [
            'code' => 'GULF', 'name' => 'آخر', 'countryCodes' => ['AE'],
        ])->assertStatus(422);
    }

    public function test_updating_a_missing_zone_is_404(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->patchJson('/api/v1/admin/shipping/zones/999999', ['name' => 'x'])->assertStatus(404);
    }

    public function test_a_zone_update_is_audited_with_before_and_after(): void
    {
        $zone = $this->zone(['name' => 'قديم']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->patchJson("/api/v1/admin/shipping/zones/{$zone->id}", ['name' => 'جديد'])->assertOk();

        $entry = DB::table('admin_audit_log')->where('action', 'shipping.zone.updated')->firstOrFail();

        // JSON يهرّب العربيّة إلى \uXXXX — تُفكّ قبل المقارنة لا يُطابَق الهروب.
        $before = json_decode((string) $entry->before, true, 512, JSON_THROW_ON_ERROR);
        $after = json_decode((string) $entry->after, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('قديم', $before['name']);
        $this->assertSame('جديد', $after['name']);
    }

    public function test_a_rate_is_created_against_a_zone(): void
    {
        $zone = $this->zone();
        $carrier = $this->carrier();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/shipping/rates', [
            'zoneId' => $zone->id, 'carrierId' => $carrier->id,
            'serviceName' => 'عاديّ', 'priceMinor' => 2500,
        ])->assertStatus(201);

        $this->assertSame(1, ShippingRate::query()->where('shipping_zone_id', $zone->id)->count());
    }

    public function test_a_rate_for_a_missing_zone_is_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/shipping/rates', [
            'zoneId' => 999999, 'carrierId' => $this->carrier()->id,
            'serviceName' => 'عاديّ', 'priceMinor' => 2500,
        ])->assertStatus(422);
    }

    // ── حدود القاعدة: ٤٢٢ باسم الحقل لا ٥٠٠ من المحرّك ──────────────────

    /**
     * نطاقٌ مقلوب يُردّ باسم حقله.
     *
     * القاعدة ترتّب الحدّين بقيدٍ صريح (`shipping_rates_weight_range_ordered`)
     * ولم يكن التحقّق يرتّبهما، فكان المقلوب يمرّ ثمّ يسقط على القيد: ٥٠٠
     * بلا اسم حقل، ويصل المشغّل «فشل الطلب (500).» — وهي الرسالة العامّة
     * التي يمنعها عقد الصدق.
     */
    public function test_an_inverted_weight_band_is_refused_by_name(): void
    {
        $zone = $this->zone();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/shipping/rates', [
            'zoneId' => $zone->id, 'carrierId' => $this->carrier()->id,
            'serviceName' => 'عاديّ', 'priceMinor' => 2500,
            'minWeightGrams' => 1000, 'maxWeightGrams' => 500,
        ])->assertStatus(422)->assertJsonValidationErrors('maxWeightGrams');
    }

    /** الطرفان متساويان ليسا نطاقًا: القيد `>` لا `>=` على الوزن والقيمة. */
    public function test_an_empty_order_band_is_refused(): void
    {
        $zone = $this->zone();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/shipping/rates', [
            'zoneId' => $zone->id, 'carrierId' => $this->carrier()->id,
            'serviceName' => 'عاديّ', 'priceMinor' => 2500,
            'minOrderMinor' => 5000, 'maxOrderMinor' => 5000,
        ])->assertStatus(422)->assertJsonValidationErrors('maxOrderMinor');
    }

    /** والمدّة قيدُها `>=`: يومان إلى يومين نطاقٌ مشروع ولا يُردّ. */
    public function test_an_equal_day_band_is_accepted(): void
    {
        $zone = $this->zone();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/shipping/rates', [
            'zoneId' => $zone->id, 'carrierId' => $this->carrier()->id,
            'serviceName' => 'عاديّ', 'priceMinor' => 2500,
            'minDays' => 2, 'maxDays' => 2,
        ])->assertStatus(201);
    }

    /**
     * التعديل يقارن بالمحفوظ لا بالعدم.
     *
     * إرسالُ السقف وحده لا يحمل القاع، فبلا الرجوع إلى الصفّ القائم يمرّ
     * سقفٌ دون قاعه المحفوظ ويسقط على القيد.
     */
    public function test_updating_only_the_ceiling_is_compared_against_the_stored_floor(): void
    {
        $zone = $this->zone();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $rate = ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'carrier_id' => $this->carrier()->id,
            'service_name' => 'عاديّ', 'price_minor' => 2500,
            'min_weight_grams' => 1000, 'max_weight_grams' => 5000,
        ]);

        $this->patchJson("/api/v1/admin/shipping/rates/{$rate->id}", [
            'carrierId' => $rate->carrier_id, 'serviceName' => 'عاديّ',
            'priceMinor' => 2500, 'maxWeightGrams' => 500,
        ])->assertStatus(422)->assertJsonValidationErrors('maxWeightGrams');
    }

    /**
     * العمودان `NOT NULL` بافتراضٍ صفر، وكان التحقّق يَعِد بقبول `null`.
     *
     * فتمرّ الصريحةُ ويردّها المحرّك — ٥٠٠ أخرى على وعدٍ لم يكن ينبغي أن
     * يُقطع.
     */
    public function test_a_null_floor_is_refused_by_validation_not_by_the_engine(): void
    {
        $zone = $this->zone();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/shipping/rates', [
            'zoneId' => $zone->id, 'carrierId' => $this->carrier()->id,
            'serviceName' => 'عاديّ', 'priceMinor' => 2500,
            'minWeightGrams' => null,
        ])->assertStatus(422)->assertJsonValidationErrors('minWeightGrams');
    }

    // ── المعاينة ─────────────────────────────────────────────────────────

    public function test_the_preview_answers_with_the_cheapest_rate(): void
    {
        $zone = $this->zone();
        $carrier = $this->carrier();

        ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'carrier_id' => $carrier->id,
            'service_name' => 'سريع', 'price_minor' => 4000, 'active' => true, 'priority' => 0,
        ]);
        ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'carrier_id' => $carrier->id,
            'service_name' => 'عاديّ', 'price_minor' => 1500, 'active' => true, 'priority' => 0,
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/shipping/preview', [
            'countryCode' => 'sa', 'weightGrams' => 1000, 'orderMinor' => 20000,
        ])->assertOk()
            ->assertJsonPath('shippable', true)
            ->assertJsonPath('rate.priceMinor', 1500)
            ->assertJsonPath('rate.serviceName', 'عاديّ');
    }

    public function test_the_preview_says_no_shipping_instead_of_zero(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $response = $this->postJson('/api/v1/admin/shipping/preview', [
            'countryCode' => 'JP', 'weightGrams' => 500, 'orderMinor' => 10000,
        ])->assertOk();

        /*
         * `null` تعني «لا نشحن إلى هنا». صفرٌ هنا كان يعني شحنةً مجّانيّة في
         * كلّ طلبٍ لم يضع له أحدٌ قاعدة — خسارةٌ صامتة لا تُكتشف.
         */
        $response->assertJsonPath('shippable', false)->assertJsonPath('rate', null);
    }

    public function test_the_preview_explains_which_zones_matched_and_in_what_order(): void
    {
        $this->zone(['code' => 'ALL-SA', 'name' => 'كلّ المملكة', 'regions' => [], 'priority' => 9]);
        $this->zone(['code' => 'RIYADH', 'name' => 'الرياض', 'regions' => ['الرياض'], 'priority' => 0]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $response = $this->postJson('/api/v1/admin/shipping/preview', [
            'countryCode' => 'SA', 'region' => 'الرياض', 'weightGrams' => 100, 'orderMinor' => 5000,
        ])->assertOk();

        /*
         * الأخصّ أوّلًا مهما كانت الأولويّة المكتوبة. هذا الترتيب هو الشرح
         * الذي يحتاجه المشغّل: «لماذا هذا السعر؟» لا يُجاب برقمٍ وحده.
         */
        $response->assertJsonPath('zones.0.code', 'RIYADH');
        $response->assertJsonPath('zones.1.code', 'ALL-SA');
    }

    public function test_the_preview_respects_the_free_over_threshold(): void
    {
        $zone = $this->zone();
        ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'carrier_id' => $this->carrier()->id,
            'service_name' => 'عاديّ', 'price_minor' => 2500,
            'free_over_minor' => 30000, 'active' => true, 'priority' => 0,
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/shipping/preview', [
            'countryCode' => 'SA', 'weightGrams' => 100, 'orderMinor' => 40000,
        ])->assertOk()
            ->assertJsonPath('rate.priceMinor', 0)
            // والسعر المعلن يبقى مرئيًّا: «صفر» بلا سياقٍ يبدو عطلًا.
            ->assertJsonPath('rate.listPriceMinor', 2500);
    }

    public function test_the_preview_requires_a_country(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/shipping/preview', [
            'weightGrams' => 100, 'orderMinor' => 5000,
        ])->assertStatus(422);
    }
}
