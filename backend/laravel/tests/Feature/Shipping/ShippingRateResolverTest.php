<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Domain\Shipping\Models\Carrier;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShippingClass;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Shipping\ShippingRateResolver;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تسعير الشحن (§٣١).
 *
 * الغرض إثبات أنّ السعر له **سبب**: صفٌّ في قاعدة البيانات يُشار إليه، لا
 * شرطٌ في مكوّن واجهة يعرفه من كتبه وحده.
 */
class ShippingRateResolverTest extends TestCase
{
    use RefreshDatabase;

    private ShippingRateResolver $resolver;

    private Carrier $carrier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->app->make(ShippingRateResolver::class);
        $this->carrier = Carrier::create([
            'code' => 'smsa', 'name' => 'سمسا',
            'tracking_url_template' => 'https://track.example/{tracking}',
        ]);
    }

    private function order(): Order
    {
        return Order::create([
            'number' => 'TD-'.uniqid(), 'email' => 'x@test.local',
            'status' => 'PAID', 'total_minor' => 5000,
        ]);
    }

    private function zone(string $code, array $countries, ?array $regions = null, int $priority = 0): ShippingZone
    {
        return ShippingZone::create([
            'code' => $code, 'name' => $code,
            'country_codes' => $countries, 'regions' => $regions, 'priority' => $priority,
        ]);
    }

    private function rate(ShippingZone $zone, int $price, array $extra = []): ShippingRate
    {
        return ShippingRate::create($extra + [
            'shipping_zone_id' => $zone->id,
            'carrier_id' => $this->carrier->id,
            'service_name' => 'قياسي',
            'price_minor' => $price,
        ]);
    }

    public function test_a_matching_rate_is_found_for_the_destination(): void
    {
        $this->rate($this->zone('sa', ['SA']), 2500);

        $rate = $this->resolver->cheapestFor('SA', 'الرياض', 1000, 10000);

        $this->assertNotNull($rate);
        $this->assertSame(2500, $rate->priceFor(10000));
    }

    public function test_an_uncovered_destination_returns_null_not_zero(): void
    {
        $this->rate($this->zone('sa', ['SA']), 2500);

        /*
         * null تعني «لا نشحن إلى هنا» صراحةً. لو أُعيد صفرٌ لَشُحن المتجر
         * إلى الكويت مجّانًا لأنّ أحدًا لم يضع قاعدة — خسارةٌ صامتة في كلّ طلب.
         */
        $this->assertNull($this->resolver->cheapestFor('KW', null, 1000, 10000));
    }

    public function test_the_more_specific_zone_wins_over_the_country_wide_one(): void
    {
        $this->rate($this->zone('sa-all', ['SA']), 3000);
        $this->rate($this->zone('sa-riyadh', ['SA'], ['الرياض']), 1500);

        $rate = $this->resolver->cheapestFor('SA', 'الرياض', 1000, 10000);

        // بلا ترتيبٍ صريح كان السعر يتبدّل بتبدّل ترتيب الصفوف.
        $this->assertSame(1500, $rate->priceFor(10000));
    }

    public function test_a_region_outside_the_specific_zone_falls_back_to_the_country_rate(): void
    {
        $this->rate($this->zone('sa-all', ['SA']), 3000);
        $this->rate($this->zone('sa-riyadh', ['SA'], ['الرياض']), 1500);

        $rate = $this->resolver->cheapestFor('SA', 'جازان', 1000, 10000);

        $this->assertSame(3000, $rate->priceFor(10000));
    }

    public function test_weight_bands_do_not_overlap_at_the_boundary(): void
    {
        $zone = $this->zone('sa', ['SA']);
        $this->rate($zone, 1000, ['min_weight_grams' => 0, 'max_weight_grams' => 1000]);
        $this->rate($zone, 2000, ['min_weight_grams' => 1000, 'max_weight_grams' => 5000]);

        /*
         * عند ١٠٠٠ جرامًا بالضبط: النطاق نصفُ مفتوح، فالقاعدة الثانية وحدها
         * تُطابق. بحدّين مغلقين كانت الاثنتان تُطابقان ويُختار السعر بحسب
         * ترتيب الاستعلام — خطأٌ لا يظهر إلّا عند الوزن الحدّيّ تمامًا.
         */
        $this->assertSame(1000, $this->resolver->cheapestFor('SA', null, 999, 10000)->priceFor(10000));
        $this->assertSame(2000, $this->resolver->cheapestFor('SA', null, 1000, 10000)->priceFor(10000));
    }

    public function test_free_shipping_applies_only_above_its_threshold(): void
    {
        $this->rate($this->zone('sa', ['SA']), 2500, ['free_over_minor' => 20000]);

        $rate = $this->resolver->cheapestFor('SA', null, 1000, 10000);
        $this->assertSame(2500, $rate->priceFor(10000));
        $this->assertSame(0, $rate->priceFor(20000));
    }

    public function test_a_null_free_threshold_means_never_free_not_always_free(): void
    {
        $this->rate($this->zone('sa', ['SA']), 2500);

        $rate = $this->resolver->cheapestFor('SA', null, 1000, 999999);

        // الفرق بين المعنيين متجرٌ يشحن مجّانًا بلا أن يقصد.
        $this->assertSame(2500, $rate->priceFor(999999));
    }

    public function test_a_class_rate_is_used_only_when_the_whole_cart_is_that_class(): void
    {
        $fragile = ShippingClass::create(['code' => 'fragile', 'name' => 'قابل للكسر', 'fragile' => true]);
        $zone = $this->zone('sa', ['SA']);
        $this->rate($zone, 5000, ['shipping_class_id' => $fragile->id]);
        $this->rate($zone, 2500);

        // سلّةٌ كلّها هشّة: قاعدة الصنف واردة، وتُختار الأرخص بينهما.
        $mixedIn = $this->resolver->cheapestFor('SA', null, 1000, 10000, [$fragile->id, 999]);
        // سلّةٌ مختلطة: قاعدة الصنف تسقط، فلا يُشحن الهشّ بقاعدة العاديّ.
        $this->assertSame(2500, $mixedIn->priceFor(10000));
        $this->assertNull($mixedIn->shipping_class_id);
    }

    public function test_the_tracking_url_is_built_from_the_carrier_template(): void
    {
        $shipment = Shipment::create([
            'order_id' => $this->order()->id, 'carrier_id' => $this->carrier->id,
            'status' => 'PENDING', 'tracking_number' => 'AB 12/34',
            'idempotency_key' => uniqid(),
        ]);

        $this->assertSame(
            'https://track.example/AB%2012%2F34',
            $shipment->load('carrier')->trackingUrl(),
        );
    }

    public function test_no_tracking_number_means_no_fabricated_link(): void
    {
        $shipment = Shipment::create([
            'order_id' => $this->order()->id, 'carrier_id' => $this->carrier->id,
            'status' => 'PENDING', 'idempotency_key' => uniqid(),
        ]);

        // رابطٌ مُلفَّق يقود الزبون إلى صفحة خطأ عند الناقل.
        $this->assertNull($shipment->load('carrier')->trackingUrl());
    }
}
