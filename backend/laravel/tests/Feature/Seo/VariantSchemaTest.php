<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\SEO\SchemaBuilder;
use App\Domain\SEO\VisibleContent;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البيانات المبنيَّة تتبع الصيغ (§٦٩ §٧٠).
 *
 * منتجٌ بثلاث صيغٍ بأثمانٍ مختلفة كان يُعلن ثمنًا واحدًا لمحرّكات البحث.
 * والنتيجة الغنيّة تُقارَن بما في الصفحة: اختلافُهما يُسقط النتيجة وقد يجلب
 * إجراءً يدويًّا — أي أنّ العيب ليس نقصًا في الظهور بل خطرًا عليه.
 */
class VariantSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function builder(): SchemaBuilder
    {
        return $this->app->make(SchemaBuilder::class);
    }

    private function product(int $regularMinor = 5000): Product
    {
        $n = uniqid();
        $product = Product::create([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => 'مخطّط المعلّم',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => $regularMinor]);

        return $product;
    }

    /** @param list<int> $prices */
    private function variants(Product $product, array $prices): ProductGroup
    {
        $n = uniqid();
        $group = ProductGroup::create([
            'slug' => "g-{$n}", 'title' => 'مخطّط المعلّم', 'product_nature' => 'DIGITAL',
            'status' => 'published', 'legacy_product_id' => $product->id,
        ]);

        foreach ($prices as $i => $price) {
            $variant = ProductVariant::create([
                'product_group_id' => $group->id, 'sku' => "V-{$n}-{$i}", 'slug' => "v-{$n}-{$i}",
                'status' => 'published',
            ]);
            ProductOffer::create([
                'product_variant_id' => $variant->id, 'regular_minor' => $price, 'status' => 'active',
            ]);
        }

        return $group;
    }

    /** @return array<string, mixed> */
    private function productNode(Product $product): array
    {
        $graph = $this->builder()->productPage($product->fresh(), new VisibleContent)['@graph'];

        foreach ($graph as $node) {
            if (($node['@type'] ?? null) === 'Product' && str_ends_with((string) $node['@id'], '#product')) {
                return $node;
            }
        }

        $this->fail('لا عقدة منتج في الرسم.');
    }

    public function test_a_single_variant_still_emits_a_plain_offer(): void
    {
        $product = $this->product(5000);
        $this->variants($product, [5000]);

        $offers = $this->productNode($product)['offers'];

        // صيغةٌ واحدة هي المنتج نفسه — لا تجميع ولا عقدةٌ مكرّرة.
        $this->assertSame('Offer', $offers['@type']);
    }

    public function test_many_variants_emit_an_aggregate_offer_with_both_ends(): void
    {
        $product = $this->product(3000);
        $this->variants($product, [3000, 2500, 9000]);

        $offers = $this->productNode($product)['offers'];

        /*
         * الحدّان كلاهما: «من ٢٥» وحدها تُغري بنقرةٍ تنتهي إلى ٩٠، وتلك
         * تجربةٌ تُحسب ارتدادًا لا فوزًا.
         */
        $this->assertSame('AggregateOffer', $offers['@type']);
        $this->assertSame('25.00', $offers['lowPrice']);
        $this->assertSame('90.00', $offers['highPrice']);
        $this->assertSame(3, $offers['offerCount']);
    }

    public function test_each_variant_becomes_its_own_node_with_its_own_sku(): void
    {
        $product = $this->product();
        $group = $this->variants($product, [3000, 2500]);

        $node = $this->productNode($product);

        /*
         * الصيغ لا تتشارك SKU، وإعلانُ رمزٍ واحد لثلاث صيغٍ يجعل تغذية
         * التسوّق ترفضها بوصفها مكرّرة.
         */
        $this->assertCount(2, $node['hasVariant']);

        $graph = $this->builder()->productPage($product->fresh(), new VisibleContent)['@graph'];
        $variantNodes = array_values(array_filter(
            $graph,
            static fn (array $n) => str_contains((string) ($n['@id'] ?? ''), '#variant-') && ! str_contains((string) $n['@id'], '-offer'),
        ));

        $skus = array_column($variantNodes, 'sku');
        $this->assertCount(2, array_unique($skus));
        $this->assertSame($group->variants->pluck('sku')->sort()->values()->all(), collect($skus)->sort()->values()->all());
    }

    public function test_a_variant_without_an_active_offer_is_not_advertised_as_buyable(): void
    {
        $product = $this->product();
        $group = $this->variants($product, [3000, 2500]);
        $group->variants->first()->offers->first()->update(['status' => 'paused']);

        $node = $this->productNode($product->fresh());

        // عرضٌ معلَّق ليس سعرًا يُعلَن — وإلّا نقر الزبون على ما لا يُشترى.
        $this->assertCount(1, $node['hasVariant']);
    }

    public function test_a_legacy_product_without_a_group_keeps_its_single_offer(): void
    {
        $product = $this->product(4500);

        $offers = $this->productNode($product)['offers'];

        // البنود السابقة للنموذج الكونيّ لا تفقد بياناتها المبنيَّة.
        $this->assertSame('Offer', $offers['@type']);
        $this->assertSame('45.00', $offers['price']);
    }

    public function test_the_aggregate_price_follows_an_active_sale(): void
    {
        $product = $this->product();
        $group = $this->variants($product, [9000, 8000]);
        $group->variants->first()->offers->first()->update([
            'sale_minor' => 4000, 'sale_from' => now()->subDay(), 'sale_to' => now()->addDay(),
        ]);

        $offers = $this->productNode($product->fresh())['offers'];

        // الثمن المُعلَن هو النافذ الآن؛ إعلانُ الأصليّ أثناء تخفيضٍ كذبة.
        $this->assertSame('40.00', $offers['lowPrice']);
    }
}
