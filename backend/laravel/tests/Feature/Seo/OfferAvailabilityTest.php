<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductGroupAuthoring;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\SEO\SchemaBuilder;
use App\Domain\SEO\VisibleContent;
use App\Domain\Shipping\Models\Carrier;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بيانات المدرسة تقول عن المخزون والشحن ما هو كائن.
 *
 * ─── العيب الذي تحرسه ───
 *
 * كان التوفّر يُشتقّ من `status === 'published'`: كلّ منتجٍ منشور يُعلَن
 * «متوفّر» لمحرّكات البحث ولو نفد مخزونه كلُّه. وذاك إعلانٌ كاذب يُعاقَب
 * عليه في نتائج التسوّق، ويُرسل زائرًا إلى صفحةٍ لا يشتري منها.
 */
class OfferAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Product, 1: ProductVariant, 2: ProductOffer} */
    private function tracked(string $nature = 'PHYSICAL', int $weight = 600): array
    {
        $n = bin2hex(random_bytes(4));

        $product = Product::create([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => 'كتاب',
            'status' => 'published', 'published_at' => now()->subDay(),
            'fulfillment_type' => 'physical',
        ]);
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => 9000, 'currency' => 'SAR']);

        $group = app(ProductGroupAuthoring::class)->create($product, $nature);
        $group->update(['status' => 'published']);

        $variant = ProductVariant::query()->where('legacy_product_id', $product->id)->firstOrFail();
        $variant->update(['status' => 'published', 'weight_grams' => $weight]);

        $offer = ProductOffer::query()->where('product_variant_id', $variant->id)->firstOrFail();
        $offer->update(['status' => 'active', 'regular_minor' => 9000, 'inventory_strategy' => 'TRACKED']);

        return [$product, $variant, $offer->refresh()];
    }

    private function offerNode(Product $product): array
    {
        $graph = app(SchemaBuilder::class)->productPage(
            $product->fresh(['price']),
            new VisibleContent,
        );

        /* الرسم مغلَّفٌ في `@graph` — العقدة تُلتمس داخله لا في جذره. */
        foreach ($graph['@graph'] ?? [] as $node) {
            if (isset($node['offers'])) {
                return $node['offers'];
            }
        }

        return [];
    }

    private function stock(ProductVariant $variant, int $quantity): void
    {
        $location = InventoryLocation::query()->sellable()->first()
            ?? InventoryLocation::create(['code' => 'L-'.uniqid(), 'name' => 'مستودع', 'kind' => 'WAREHOUSE']);

        app(InventoryService::class)->receive(
            variantId: (int) $variant->id,
            locationId: (int) $location->id,
            quantity: $quantity,
            reason: 'بذرة اختبار',
        );
    }

    public function test_a_tracked_product_with_no_stock_is_declared_out_of_stock(): void
    {
        [$product] = $this->tracked();

        /* منشورٌ ونافد: الحالة القديمة كانت تقول «متوفّر» لأنّه منشور. */
        $this->assertSame('https://schema.org/OutOfStock', $this->offerNode($product)['availability'] ?? null);
    }

    public function test_receiving_stock_flips_the_declaration(): void
    {
        [$product, $variant] = $this->tracked();
        $this->stock($variant, 5);

        $this->assertSame('https://schema.org/InStock', $this->offerNode($product)['availability'] ?? null);
    }

    public function test_an_unlimited_digital_offer_is_always_in_stock(): void
    {
        [$product] = $this->tracked('DIGITAL');
        ProductOffer::query()->update(['inventory_strategy' => 'UNLIMITED']);

        $this->assertSame('https://schema.org/InStock', $this->offerNode($product)['availability'] ?? null);
    }

    public function test_a_strategy_with_no_counter_omits_the_node_rather_than_guessing(): void
    {
        [$product] = $this->tracked('DIGITAL');
        ProductOffer::query()->update(['inventory_strategy' => 'SEAT_CAPACITY']);

        /*
         * §١٥٨ — لا عدّاد للمقاعد في هذا المستودع. فالعقدة تُحذف ولا تُملأ
         * بـ«متوفّر»: الغياب يُعلَن ولا يُخترع له جواب.
         */
        $this->assertArrayNotHasKey('availability', $this->offerNode($product));
    }

    public function test_shipping_details_appear_only_with_a_real_zone_and_rate(): void
    {
        [$product, $variant] = $this->tracked();
        $this->stock($variant, 5);

        /* بلا منطقةٍ ولا قاعدة سعر: لا عقدة شحنٍ تُخترع. */
        $this->assertArrayNotHasKey('shippingDetails', $this->offerNode($product));

        $carrier = Carrier::create(['code' => 'c-'.uniqid(), 'name' => 'ناقل']);
        $zone = ShippingZone::create(['code' => 'z-'.uniqid(), 'name' => 'السعوديّة', 'country_codes' => ['SA']]);
        ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'carrier_id' => $carrier->id,
            'service_name' => 'قياسي', 'price_minor' => 2500, 'max_days' => 4,
        ]);

        $shipping = $this->offerNode($product)['shippingDetails'] ?? null;

        $this->assertNotNull($shipping);
        $this->assertSame('25.00', $shipping['shippingRate']['value']);
        $this->assertSame(4, $shipping['deliveryTime']['transitTime']['maxValue']);
        /* زمنُ التجهيز غائبٌ عمدًا: لا عمود له، وإعلانُ صفرٍ وعدٌ لا يُملَك. */
        $this->assertArrayNotHasKey('handlingTime', $shipping['deliveryTime']);
    }

    public function test_a_digital_product_carries_no_shipping_node(): void
    {
        [$product] = $this->tracked('DIGITAL');
        ProductOffer::query()->update(['inventory_strategy' => 'UNLIMITED']);

        $this->assertArrayNotHasKey('shippingDetails', $this->offerNode($product));
    }

    public function test_no_return_policy_node_is_invented(): void
    {
        [$product, $variant] = $this->tracked();
        $this->stock($variant, 3);

        /* الجدول قائمٌ وفارغٌ وبلا قارئ — فكلّ حقلٍ في العقدة كان سيُخترع. */
        $this->assertArrayNotHasKey('hasMerchantReturnPolicy', $this->offerNode($product));
    }
}
