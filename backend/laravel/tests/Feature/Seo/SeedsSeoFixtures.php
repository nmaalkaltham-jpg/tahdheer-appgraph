<?php

namespace Tests\Feature\Seo;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductSeo;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Explicit SEO fixtures. Every value is written by the test that needs it —
 * nothing is generated, so an assertion always points at a stated fact.
 */
trait SeedsSeoFixtures
{
    /** @param array<string, mixed> $attributes */
    protected function makeProduct(string $sku, string $title, array $attributes = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => $sku,
            'slug' => strtolower($sku),
            'title' => $title,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'fulfillment_type' => 'digital',
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    protected function makePrice(Product $product, int $regularMinor, array $attributes = []): ProductPrice
    {
        $price = ProductPrice::query()->create(array_merge([
            'product_id' => $product->id,
            'regular_minor' => $regularMinor,
            'currency' => 'SAR',
        ], $attributes));

        $product->setRelation('price', $price);

        return $price;
    }

    /** @param array<string, mixed> $attributes */
    protected function makeSeoRow(Product $product, array $attributes): ProductSeo
    {
        // الاتّحاد يُبقي اليسار: وسائط المستدعي على اليسار وإلّا أُهملت.
        $seo = ProductSeo::query()->create($attributes + ['product_id' => $product->id]);
        $product->setRelation('seo', $seo);

        return $seo;
    }

    /** Write a real review aggregate row (the reviews domain owns this table). */
    protected function makeReviewAggregate(Product $product, float $avg, int $count): void
    {
        DB::table('review_aggregates')->insert([
            'product_id' => $product->id,
            'avg_rating' => $avg,
            'count' => $count,
            'distribution' => json_encode([5 => $count]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Turn a storefront route class on for the duration of a test. */
    protected function enableRoute(string $key): void
    {
        $this->setRoute($key, true);
    }

    /**
     * إطفاء صنف مسار من الإعدادات.
     *
     * كل أصناف المسارات صارت مبنية، فلا يبقى صنفٌ خامل «طبيعيًّا» يُختبَر عليه
     * سلوك الخمول. الإطفاء من الإعدادات يختبر **الآلية** — وهي ما يجب أن يبقى
     * صحيحًا — بدل أن يعلّق الاختبار على واقعٍ مؤقّت يتغيّر مع أول صفحة تُبنى.
     */
    protected function disableRoute(string $key): void
    {
        $this->setRoute($key, false);
    }

    private function setRoute(string $key, bool $enabled): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'seo.routes.'.$key],
            ['value' => ['enabled' => $enabled]],
        );
    }
}
