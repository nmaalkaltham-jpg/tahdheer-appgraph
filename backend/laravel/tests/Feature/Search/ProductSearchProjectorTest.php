<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Catalog\Models\AttributeDefinition;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Search\ProductSearchProjector;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الإسقاط القانونيّ للبحث (§٦٣ §١٢٤).
 *
 * كان المستند يُبنى داخل محوّل Meilisearch من `products` مباشرةً. فلم يكن
 * يعرف شيئًا عن الصيغ ولا العروض ولا السمات المكتوبة النوع، وبنى البديل
 * (PostgreSQL) شكلًا آخر من الجداول نفسها.
 */
class ProductSearchProjectorTest extends TestCase
{
    use RefreshDatabase;

    private function projector(): ProductSearchProjector
    {
        return $this->app->make(ProductSearchProjector::class);
    }

    private function legacyProduct(array $overrides = []): Product
    {
        $n = uniqid();

        $product = Product::create($overrides + [
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => 'منتج',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => 5000]);

        return $product;
    }

    /** مجموعةٌ بثلاث صيغٍ بأثمانٍ مختلفة — الحالة التي كسرت الترشيح. */
    private function groupWithVariants(Product $product, array $prices, array $strategies = ['DIGITAL_DOWNLOAD']): ProductGroup
    {
        $n = uniqid();
        $group = ProductGroup::create([
            'slug' => "g-{$n}", 'title' => 'مخطّط المعلّم', 'product_nature' => 'HYBRID',
            'status' => 'published', 'legacy_product_id' => $product->id,
        ]);

        foreach ($prices as $i => $price) {
            $variant = ProductVariant::create([
                'product_group_id' => $group->id, 'sku' => "V-{$n}-{$i}", 'slug' => "v-{$n}-{$i}",
                'status' => 'published',
            ]);
            $offer = ProductOffer::create([
                'product_variant_id' => $variant->id, 'regular_minor' => $price, 'status' => 'active',
            ]);
            foreach ($strategies as $s) {
                OfferFulfillment::create(['product_offer_id' => $offer->id, 'strategy' => $s]);
            }
        }

        return $group->fresh(['variants.offers.fulfillments', 'attributeValues.definition']);
    }

    public function test_the_price_is_a_range_across_variants_not_a_single_number(): void
    {
        $product = $this->legacyProduct();
        $this->groupWithVariants($product, [3000, 2500, 9000]);

        $doc = $this->projector()->forProduct($product->fresh());

        /*
         * بثمنٍ واحد كان الزبون يُرشِّح «أقلّ من ٣٠» فيسقط منتجٌ له صيغةٌ
         * بـ٢٥ — وهي بالضبط ما يريد.
         */
        $this->assertSame(2500, $doc->priceMinMinor);
        $this->assertSame(9000, $doc->priceMaxMinor);
        $this->assertSame(3, $doc->variantCount);
    }

    public function test_fulfillment_strategies_are_indexed_so_shipping_queries_work(): void
    {
        $product = $this->legacyProduct();
        $this->groupWithVariants($product, [5000], ['DIGITAL_DOWNLOAD', 'PHYSICAL_SHIPMENT']);

        $doc = $this->projector()->forProduct($product->fresh());

        // §١٥٤ تذكر «تنزيل فوري» و«يُشحن» بين الأسئلة الذهبيّة.
        $this->assertEqualsCanonicalizing(['DIGITAL_DOWNLOAD', 'PHYSICAL_SHIPMENT'], $doc->fulfillmentStrategies);
        $this->assertSame('HYBRID', $doc->nature);
    }

    public function test_only_attributes_declared_filterable_reach_the_index(): void
    {
        $product = $this->legacyProduct();
        $group = $this->groupWithVariants($product, [5000]);

        $filterable = AttributeDefinition::create([
            'key' => 'pages', 'name' => 'الصفحات', 'data_type' => 'INTEGER', 'filterable' => true,
        ]);
        $internal = AttributeDefinition::create([
            'key' => 'internal_note', 'name' => 'ملاحظة', 'data_type' => 'TEXT', 'filterable' => false,
        ]);
        AttributeValue::create([
            'attribute_definition_id' => $filterable->id, 'product_group_id' => $group->id, 'int_value' => 120,
        ]);
        AttributeValue::create([
            'attribute_definition_id' => $internal->id, 'product_group_id' => $group->id, 'text_value' => 'سرّ',
        ]);

        $doc = $this->projector()->forProduct($product->fresh());

        /*
         * العلم في السجلّ هو القرار: فهرسةُ كلّ سمةٍ تُضخّم المستند بما لا
         * يُرشَّح به أحد وتجعل إعدادات المحرّك تنمو بلا سقف.
         */
        $this->assertSame(120, $doc->attributes['pages']);
        $this->assertArrayNotHasKey('internal_note', $doc->attributes);
        $this->assertStringNotContainsString('سرّ', json_encode($doc->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_a_tracked_variant_with_no_stock_is_indexed_as_out_of_stock(): void
    {
        $product = $this->legacyProduct();
        $group = $this->groupWithVariants($product, [5000], ['PHYSICAL_SHIPMENT']);
        $group->variants->first()->offers->first()->update(['inventory_strategy' => 'TRACKED']);

        $doc = $this->projector()->forProduct($product->fresh());

        /*
         * يظهر ويُرشَّح ولا يختفي: الزبون يبحث عنه ويريد أن يعرف أنّه نفد،
         * لا أن يظنّ أنّ المتجر لا يبيعه.
         */
        $this->assertFalse($doc->inStock);
    }

    public function test_stock_makes_it_available_again(): void
    {
        $product = $this->legacyProduct();
        $group = $this->groupWithVariants($product, [5000], ['PHYSICAL_SHIPMENT']);
        $offer = $group->variants->first()->offers->first();
        $offer->update(['inventory_strategy' => 'TRACKED']);

        $location = InventoryLocation::create(['code' => 'L-'.uniqid(), 'name' => 'م', 'kind' => 'WAREHOUSE']);
        $this->app->make(InventoryService::class)
            ->receive((int) $offer->product_variant_id, $location->id, 4, 'شحنة');

        $this->assertTrue($this->projector()->forProduct($product->fresh())->inStock);
    }

    public function test_a_digital_product_is_always_in_stock(): void
    {
        $product = $this->legacyProduct();
        $this->groupWithVariants($product, [5000]); // UNLIMITED افتراضًا

        // الرقميّ لا ينفد؛ اشتراطُ مخزونٍ له كان سيُخفيه من كلّ نتيجة.
        $this->assertTrue($this->projector()->forProduct($product->fresh())->inStock);
    }

    public function test_a_legacy_product_without_a_group_is_still_searchable(): void
    {
        $product = $this->legacyProduct(['resource_type' => 'تحضير', 'format' => 'pdf', 'editable' => 'yes']);

        $doc = $this->projector()->forProduct($product->fresh());

        /*
         * الترحيل القسريّ كان سيُخرج كلّ ما لم يُرحَّل بعدُ من نتائج البحث
         * فجأةً — وهو أسوأ ما يقع لمتجرٍ في منتصف هجرة.
         */
        $this->assertSame($product->id, $doc->id);
        $this->assertSame('تحضير', $doc->resourceType);
        $this->assertSame(['PDF'], $doc->formats);
        $this->assertTrue($doc->editable);
        $this->assertSame(5000, $doc->priceMinMinor);
    }

    public function test_a_rating_is_indexed_only_when_it_exists(): void
    {
        $product = $this->legacyProduct();

        $doc = $this->projector()->forProduct($product->fresh())->toArray();

        // صفرٌ يُقرأ «سيّئ»؛ الغياب يُقرأ «لا تقييم بعد».
        $this->assertArrayNotHasKey('rating_avg', $doc);
        $this->assertArrayNotHasKey('rating_count', $doc);
    }

    public function test_null_fields_are_dropped_rather_than_sent_as_null(): void
    {
        $product = $this->legacyProduct();

        $doc = $this->projector()->forProduct($product->fresh())->toArray();

        /*
         * Meilisearch يُرشِّح على وجود الحقل: حقلٌ قيمته null يجعل المنتج
         * يُطابق «له تقييم» وهو بلا تقييم.
         */
        $this->assertNotContains(null, $doc, 'لا قيمة null تصل المحرّك');
    }

    public function test_paid_file_keys_never_reach_the_index(): void
    {
        $product = $this->legacyProduct();
        $this->groupWithVariants($product, [5000]);

        $serialized = json_encode($this->projector()->forProduct($product->fresh())->toArray());

        // §١٧ — الفهرس يُقرأ من الواجهة العامّة.
        foreach (['provider_ref', 'asset_id', 'file_key', 'sha256'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $serialized);
        }
    }
}
