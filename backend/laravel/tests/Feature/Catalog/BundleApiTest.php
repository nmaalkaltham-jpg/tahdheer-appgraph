<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\BundleComposition;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductGroupAuthoring;
use App\Models\Kind;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** الحزم العامّة: القائمة والحزمة الواحدة، وعدّ الأنواع في الكتالوج. */
class BundleApiTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** @return array{0: Product, 1: ProductGroup, 2: ProductVariant} */
    private function sellable(string $nature, int $priceMinor, array $attributes = []): array
    {
        $n = ++$this->seq;

        $product = Product::create(array_merge([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => "منتج {$n}", 'format' => 'PDF', 'resource_type' => 'تحضير',
            'status' => 'published', 'published_at' => now()->subDays($n), 'fulfillment_type' => 'digital',
        ], $attributes));
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => $priceMinor, 'currency' => 'SAR']);

        $group = app(ProductGroupAuthoring::class)->create($product, $nature);
        $group->update(['status' => 'published', 'published_at' => now()->subDays($n), 'short_desc' => "وصف {$n}"]);

        $variant = ProductVariant::query()->where('legacy_product_id', $product->id)->firstOrFail();
        $variant->update(['status' => 'published', 'format' => 'PDF']);
        ProductOffer::query()->where('product_variant_id', $variant->id)
            ->update(['status' => 'active', 'regular_minor' => $priceMinor]);

        return [$product, $group->refresh(), $variant->refresh()];
    }

    public function test_only_published_bundles_with_components_are_listed(): void
    {
        [$bundleProduct, $bundle] = $this->sellable('COMPOSITE', 15000);
        [, $empty] = $this->sellable('COMPOSITE', 9000);
        [, $draft] = $this->sellable('COMPOSITE', 9000);
        [$a, , $va] = $this->sellable('DIGITAL', 9000);
        [$b, , $vb] = $this->sellable('DIGITAL', 12000);
        [, , $vc] = $this->sellable('DIGITAL', 1000);

        $composition = app(BundleComposition::class);
        $composition->add($bundle, (int) $va->id);
        $composition->add($bundle, (int) $vb->id);
        $composition->add($draft, (int) $vc->id);
        $draft->update(['status' => 'draft']);

        $response = $this->getJson('/api/v1/bundles')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', $bundle->slug)
            ->assertJsonPath('data.0.title', $bundleProduct->title)
            ->assertJsonPath('data.0.description', 'وصف 1')
            ->assertJsonPath('data.0.itemsCount', 2)
            ->assertJsonPath('data.0.itemCount', 2)
            ->assertJsonPath('data.0.priceMinor', 15000)
            ->assertJsonPath('data.0.regularMinor', 21000)
            ->assertJsonPath('data.0.itemsTotalMinor', 21000)
            ->assertJsonPath('data.0.currency', 'SAR')
            ->assertJsonPath('data.0.url', '/bundles/'.$bundle->slug)
            ->assertJsonPath('data.0.cartProductId', $bundleProduct->id)
            ->assertJsonPath('data.0.items.0.slug', $a->slug)
            ->assertJsonPath('data.0.items.0.url', '/products/'.$a->slug)
            ->assertJsonPath('data.0.items.0.format', 'PDF')
            ->assertJsonPath('data.0.items.0.resourceType', 'تحضير')
            ->assertJsonPath('data.0.items.0.priceMinor', 9000)
            ->assertJsonPath('data.0.items.1.slug', $b->slug);

        // الفارغة والمسوّدة لا تُدرَجان.
        $listed = array_column($response->json('data'), 'slug');
        $this->assertNotContains($empty->slug, $listed);
        $this->assertNotContains($draft->slug, $listed);
    }

    public function test_a_bundle_is_served_by_slug_and_missing_ones_are_404(): void
    {
        [, $bundle] = $this->sellable('COMPOSITE', 15000);
        [, , $va] = $this->sellable('DIGITAL', 9000);
        app(BundleComposition::class)->add($bundle, (int) $va->id);

        $this->getJson('/api/v1/bundles/'.$bundle->slug)->assertOk()
            ->assertJsonPath('data.slug', $bundle->slug)
            ->assertJsonPath('data.items.0.id', $va->id);

        $this->getJson('/api/v1/bundles/nope')->assertNotFound();

        [, $empty] = $this->sellable('COMPOSITE', 100);
        $this->getJson('/api/v1/bundles/'.$empty->slug)->assertNotFound();
    }

    public function test_kinds_carry_published_product_counts_by_resource_type(): void
    {
        Kind::create(['slug' => 'تحضير', 'name_ar' => 'تحضير']);
        Kind::create(['slug' => 'أوراق-عمل', 'name_ar' => 'أوراق عمل']);
        Kind::create(['slug' => 'اختبارات', 'name_ar' => 'اختبارات']);

        $this->sellable('DIGITAL', 100);
        $this->sellable('DIGITAL', 100);
        $this->sellable('DIGITAL', 100, ['resource_type' => 'أوراق عمل']);
        $this->sellable('DIGITAL', 100, ['resource_type' => 'تحضير', 'status' => 'draft']);

        $counts = collect($this->getJson('/api/v1/catalog/kinds')->assertOk()->json('data'))
            ->pluck('productsCount', 'slug')->all();

        $this->assertSame(['تحضير' => 2, 'أوراق-عمل' => 1, 'اختبارات' => 0], $counts);
    }
}
