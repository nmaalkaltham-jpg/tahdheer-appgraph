<?php

namespace Tests\Feature\Home;

use App\Domain\Reviews\Models\Review;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\DesignPreviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The design-preview fixtures must actually light the homepage up — otherwise
 * a designer reviewing the page is reviewing empty states. This walks the same
 * eleven requests the storefront makes after `migrate --seed`.
 *
 * The reviews band is now populated too — the owner asked for fixture reviews
 * so the rating rows and distribution bars could be judged. What is pinned here
 * is therefore not «empty» but «derived»: every figure the band shows has to
 * equal the roll-up of `review_aggregates`, which only AggregateRebuilder
 * writes, and every quote has to be an approved review with a real order behind
 * it. A number that does not come out of those rows is fixture data leaking
 * into a public trust signal.
 */
class DesignPreviewHomepageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurriculumSeeder::class);
        $this->seed(DesignPreviewSeeder::class);
    }

    public function test_the_seeded_preview_fills_every_editorial_section(): void
    {
        $this->getJson('/api/v1/home/hero')->assertOk()
            ->assertJsonStructure(['title', 'title_accent', 'subtitle', 'eyebrow', 'cta_label', 'cta_url', 'trust']);

        $this->getJson('/api/v1/home/why')->assertOk()->assertJsonCount(4);
        $this->getJson('/api/v1/home/benefits')->assertOk()->assertJsonCount(3);
        $this->getJson('/api/v1/home/faq')->assertOk()->assertJsonCount(5)
            ->assertJsonStructure([['id', 'question', 'answer']]);
        $this->getJson('/api/v1/home/cta')->assertOk()->assertJsonStructure(['title', 'cta_label', 'cta_url']);

        $promo = $this->getJson('/api/v1/home/promo')->assertOk()
            ->assertJsonStructure(['campaign' => ['title', 'ends_at', 'url'], 'coupon' => ['code', 'description']]);

        $this->assertTrue(now()->lt($promo->json('campaign.ends_at')), 'the seeded campaign must still be running');
    }

    public function test_the_seeded_preview_fills_both_product_rails_from_real_signals(): void
    {
        $this->getJson('/api/v1/products?source=featured&limit=8')->assertOk()->assertJsonCount(4, 'data');

        // «الأكثر مبيعًا» is not a list the seeder writes: it is COUNTED from
        // paid order lines. The fixtures now carry two independent sources of
        // those lines (the bestseller orders and the order behind every fixture
        // review), so the rail is asserted against the arithmetic rather than
        // against a memorised set of SKUs.
        $skus = $this->getJson('/api/v1/products?source=best&limit=8')
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->json('data.*.sku');

        $units = collect($skus)->map(fn (string $sku) => DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('products.sku', $sku)
            ->whereIn('orders.status', ['PAID', 'PROCESSING', 'COMPLETED'])
            ->count());

        $this->assertTrue($units->every(fn (int $n) => $n > 0), 'a product with no paid line reached the rail');
        $this->assertSame($units->sortDesc()->values()->all(), $units->all(), 'the rail is not ordered by units sold');

        // A fixture nobody bought is ABSENT, not ranked last.
        $this->assertNotContains('DESIGN-PREVIEW-12', $skus);
    }

    public function test_the_reviews_band_reports_exactly_what_the_aggregates_hold(): void
    {
        $roll = DB::table('review_aggregates')
            ->join('products', 'products.id', '=', 'review_aggregates.product_id')
            ->where('products.status', 'published')
            ->selectRaw('sum(review_aggregates.count) as total')
            ->selectRaw('sum(review_aggregates.avg_rating * review_aggregates.count) as weighted')
            ->first();

        $count = (int) $roll->total;
        $this->assertGreaterThan(0, $count, 'the fixture reviews did not reach review_aggregates');

        $band = $this->getJson('/api/v1/home/reviews')->assertOk();

        $this->assertSame($count, $band->json('rating_count'));
        $this->assertEqualsWithDelta(
            round(((float) $roll->weighted) / $count, 2),
            $band->json('rating_avg'),
            0.005,
        );

        // Every quote is an approved review with a real order behind it.
        $this->assertNotEmpty($band->json('quotes'));
        foreach ($band->json('quotes') as $quote) {
            $this->assertTrue($quote['verified']);
            $this->assertNotEmpty($quote['text']);
            $this->assertSame(
                Review::APPROVED,
                Review::query()->whereKey($quote['id'])->value('status'),
            );
        }
    }
}
