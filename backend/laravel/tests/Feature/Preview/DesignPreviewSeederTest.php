<?php

namespace Tests\Feature\Preview;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewAggregate;
use App\Domain\SEO\Models\ProductFaq;
use App\Models\Product;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\DesignPreviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Checks on the design-preview fixtures (§ owner request «أضف منتجات وهمية من
 * أجل جعل مظهر التصميم واضح»). The fixtures exist so the storefront design can
 * be judged — they must never leak into production, and inside a preview
 * database they must stay honest:
 *
 *  · production is a hard no-op, checked for every table the seeder touches;
 *  · every fixture product carries a REAL public_sample cover, and no
 *    paid_private asset is ever reachable as one;
 *  · `review_aggregates` matches the approved reviews exactly — nothing is
 *    typed into that table;
 *  · some products keep no reviews at all, so the honest empty state is still
 *    previewable next to the populated one.
 */
class DesignPreviewSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = 'DESIGN-PREVIEW-';

    /** The cover role must stay OUTSIDE the delivered set. */
    private const COVER_ROLE = 'sample';

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->seed(CurriculumSeeder::class);
        Storage::fake('assets');
        Storage::fake('public');

        $this->app['env'] = 'production';

        (new DesignPreviewSeeder)->run();

        $this->assertSame(0, Product::query()->where('sku', 'like', self::PREFIX.'%')->count());
        $this->assertSame(0, Review::query()->count());
        $this->assertSame(0, ReviewAggregate::query()->count());
        $this->assertSame(0, DB::table('product_media')->count());
        $this->assertSame(0, ProductFaq::query()->count());
        $this->assertSame(0, Asset::query()->count());
        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    public function test_every_fixture_product_has_a_public_sample_cover_and_no_paid_file_is_exposed(): void
    {
        $products = $this->seedPreview();

        // The role a cover is attached under can never be delivered as the
        // paid file — that is a property of the domain, not of this seeder.
        $this->assertNotContains(self::COVER_ROLE, ProductFile::DELIVERABLE_ROLES);

        foreach ($products as $product) {
            $cover = DB::table('product_media')
                ->where('product_id', $product->id)
                ->where('is_cover', true)
                ->first();

            $this->assertNotNull($cover, "No cover row for {$product->sku}.");
            $this->assertNotEmpty($cover->url);
            $this->assertNotEmpty($cover->alt_ar);

            // The bytes behind that URL really exist on the public disk.
            Storage::disk('public')->assertExists(Str::after((string) $cover->url, '/storage/'));

            $asset = Asset::query()
                ->join('product_files', 'product_files.asset_id', '=', 'assets.id')
                ->where('product_files.product_id', $product->id)
                ->where('product_files.role', self::COVER_ROLE)
                ->select('assets.*')
                ->first();

            $this->assertNotNull($asset, "No ledger asset for the cover of {$product->sku}.");
            $this->assertSame('public_sample', $asset->visibility);
            $this->assertSame('image/svg+xml', $asset->mime);
            $this->assertFalse($asset->isPaidPrivate());
        }

        // Paid content sanctity: no private original is attached as a cover…
        $this->assertSame(0, DB::table('product_files')
            ->join('assets', 'assets.id', '=', 'product_files.asset_id')
            ->where('product_files.role', self::COVER_ROLE)
            ->where('assets.visibility', 'paid_private')
            ->count(), 'A paid_private asset was attached as a cover.');

        // …and no product_media URL leaks a private object key.
        foreach (Asset::query()->where('visibility', 'paid_private')->pluck('provider_ref') as $ref) {
            $this->assertSame(0, DB::table('product_media')
                ->where('url', 'like', '%'.$ref.'%')
                ->count(), 'A paid object key reached product_media.');
        }
    }

    public function test_it_gives_every_fixture_product_the_pdp_depth_the_design_needs(): void
    {
        $products = $this->seedPreview();

        foreach ($products as $product) {
            $this->assertGreaterThan(0, $product->facts()->count(), "No facts for {$product->sku}.");
            $this->assertGreaterThanOrEqual(
                3,
                ProductFaq::query()->where('product_id', $product->id)->count(),
                "Too few FAQ rows for {$product->sku}.",
            );

            $seo = $product->seo;
            $this->assertNotNull($seo, "No SEO row for {$product->sku}.");
            $this->assertNotEmpty($seo->seo_desc);
            $this->assertNotEmpty($seo->quick_answer);
        }
    }

    public function test_review_aggregates_match_the_seeded_reviews_and_some_products_stay_review_free(): void
    {
        $products = $this->seedPreview();

        $this->assertGreaterThan(0, Review::query()->count());

        // Nothing pending or rejected slipped into the preview feed.
        $this->assertSame(
            Review::query()->count(),
            Review::query()->where('status', Review::APPROVED)->count(),
        );

        foreach (ReviewAggregate::query()->get() as $aggregate) {
            $approved = Review::query()
                ->where('product_id', $aggregate->product_id)
                ->where('status', Review::APPROVED)
                ->get();

            $this->assertSame($approved->count(), $aggregate->count);
            $this->assertEqualsWithDelta(
                round((float) $approved->avg('rating'), 2),
                (float) $aggregate->avg_rating,
                0.005,
            );

            $bars = $aggregate->distribution;
            $this->assertSame(
                $approved->count(),
                array_sum(array_column($bars, 'count')),
                'The distribution bars do not add up to the approved reviews.',
            );
            $this->assertSame(100, array_sum(array_column($bars, 'pct')));

            foreach ([5, 4, 3, 2, 1] as $star) {
                $this->assertSame(
                    $approved->where('rating', $star)->count(),
                    (int) $bars[$star]['count'],
                );
            }
        }

        // A product with no approved review has NO aggregate row — absence, not
        // a row of zeros.
        $reviewed = Review::query()->distinct()->pluck('product_id');
        $this->assertSame($reviewed->count(), ReviewAggregate::query()->count());

        $reviewFree = $products->filter(
            fn (Product $p) => Review::query()->where('product_id', $p->id)->doesntExist(),
        );

        $this->assertGreaterThanOrEqual(
            2,
            $reviewFree->count(),
            'Every fixture has reviews — the honest empty state is no longer previewable.',
        );

        // Every review is a verified purchase: the seeder cannot produce an
        // opinion without an order behind it.
        $this->assertSame(0, Review::query()->whereNull('order_id')->count());
    }

    /**
     * @return Collection<int, Product>
     */
    private function seedPreview()
    {
        Storage::fake('assets');
        Storage::fake('public');

        $this->seed(CurriculumSeeder::class);
        $this->seed(DesignPreviewSeeder::class);

        $products = Product::query()->where('sku', 'like', self::PREFIX.'%')->get();
        $this->assertGreaterThanOrEqual(24, $products->count());

        return $products;
    }
}
