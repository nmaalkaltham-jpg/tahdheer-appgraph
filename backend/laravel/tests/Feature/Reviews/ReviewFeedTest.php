<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\AggregateRebuilder;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The public feed. Its single hardest guarantee: nothing unapproved is
 * reachable, by any parameter.
 */
class ReviewFeedTest extends TestCase
{
    use RefreshDatabase, ReviewsFixtures;

    public function test_a_product_with_no_reviews_returns_an_explicit_empty_state(): void
    {
        $product = $this->makeProduct();

        $response = $this->getJson("/api/v1/products/{$product->id}/reviews")
            ->assertOk()
            ->assertJsonPath('items', [])
            // null, NOT a zeroed summary — the UI invites the first review.
            ->assertJsonPath('aggregate', null)
            ->assertJsonPath('pageInfo.nextCursor', null);

        // The bars still exist as a shape, all at zero, so the UI never has to
        // invent a scaffold.
        $this->assertSame(
            [['stars' => 5, 'count' => 0, 'pct' => 0]],
            array_slice($response->json('distribution'), 0, 1),
        );
        $this->assertSame(0, array_sum(array_column($response->json('distribution'), 'pct')));
    }

    public function test_pending_and_rejected_reviews_are_invisible_to_the_public(): void
    {
        $product = $this->makeProduct();
        $this->seedApproved($product, [5 => 1]);
        $this->seedApproved($product, [1 => 1], Review::PENDING);
        $this->seedApproved($product, [1 => 1], Review::REJECTED);

        $this->assertDatabaseCount('reviews', 3);

        $response = $this->getJson("/api/v1/products/{$product->id}/reviews")->assertOk();

        $this->assertCount(1, $response->json('items'));
        $this->assertSame(5, $response->json('items.0.rating'));

        // Nor through a filter that targets exactly the hidden rating.
        $this->getJson("/api/v1/products/{$product->id}/reviews?stars=1")
            ->assertOk()
            ->assertJsonPath('items', []);
    }

    public function test_the_aggregate_block_carries_the_full_contract(): void
    {
        $product = $this->makeProduct();
        $this->seedApproved($product, [5 => 190, 4 => 16, 3 => 4, 2 => 1, 1 => 1]);
        app(AggregateRebuilder::class)->rebuild((int) $product->id);

        $this->getJson("/api/v1/products/{$product->id}/reviews")
            ->assertOk()
            ->assertJsonStructure([
                'items',
                'aggregate' => ['avgRating', 'count', 'recommendPct', 'schemaEligible', 'distribution'],
                'distribution',
                'sort',
                'pageInfo' => ['nextCursor'],
            ])
            ->assertJsonPath('aggregate.avgRating', 4.85)
            ->assertJsonPath('aggregate.count', 212)
            ->assertJsonPath('aggregate.recommendPct', 97)
            ->assertJsonPath('aggregate.schemaEligible', true)
            ->assertJsonPath('aggregate.distribution.0', ['stars' => 5, 'count' => 190, 'pct' => 90])
            ->assertJsonPath('sort', 'helpful');
    }

    public function test_schema_eligibility_is_false_below_three_real_reviews(): void
    {
        $product = $this->makeProduct();
        $this->seedApproved($product, [5 => 2]);
        app(AggregateRebuilder::class)->rebuild((int) $product->id);

        $this->getJson("/api/v1/products/{$product->id}/reviews")
            ->assertOk()
            ->assertJsonPath('aggregate.count', 2)
            ->assertJsonPath('aggregate.schemaEligible', false);
    }

    public function test_filtering_by_stars_narrows_the_list_but_never_the_summary(): void
    {
        $product = $this->makeProduct();
        $this->seedApproved($product, [5 => 3, 1 => 1]);
        app(AggregateRebuilder::class)->rebuild((int) $product->id);

        $response = $this->getJson("/api/v1/products/{$product->id}/reviews?stars=1")->assertOk();

        $this->assertCount(1, $response->json('items'));
        // The average still describes all four reviews, not the filtered one.
        $this->assertSame(4, $response->json('aggregate.count'));
        $this->assertSame(100, array_sum(array_column($response->json('distribution'), 'pct')));
    }

    public function test_sorting_and_search_select_the_right_rows(): void
    {
        $product = $this->makeProduct();
        $this->seedApproved($product, [5 => 1, 3 => 1, 1 => 1]);

        $lowest = $this->getJson("/api/v1/products/{$product->id}/reviews?sort=lowest")->assertOk();
        $this->assertSame(1, $lowest->json('items.0.rating'));

        $highest = $this->getJson("/api/v1/products/{$product->id}/reviews?sort=highest")->assertOk();
        $this->assertSame(5, $highest->json('items.0.rating'));

        // An unknown sort falls back to the default rather than 500-ing.
        $this->getJson("/api/v1/products/{$product->id}/reviews?sort=whatever")
            ->assertOk()
            ->assertJsonPath('sort', 'helpful');

        // Body search.
        $found = $this->getJson("/api/v1/products/{$product->id}/reviews?q=".rawurlencode('موثّق رقم 2'))->assertOk();
        $this->assertCount(1, $found->json('items'));

        $none = $this->getJson("/api/v1/products/{$product->id}/reviews?q=".rawurlencode('كلمة غير موجودة'))->assertOk();
        $this->assertSame([], $none->json('items'));
    }

    public function test_the_cursor_walks_every_review_exactly_once(): void
    {
        $product = $this->makeProduct();
        $this->seedApproved($product, [5 => 12]);

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $url = "/api/v1/products/{$product->id}/reviews?sort=newest".($cursor ? "&cursor={$cursor}" : '');
            $response = $this->getJson($url)->assertOk();
            foreach ($response->json('items') as $item) {
                $seen[] = $item['id'];
            }
            $cursor = $response->json('pageInfo.nextCursor');
            $pages++;
        } while ($cursor !== null && $pages < 5);

        $this->assertCount(12, $seen);
        $this->assertSame(12, count(array_unique($seen)), 'A review appeared on two pages.');
    }

    public function test_identity_comes_from_the_account_and_the_badge_from_a_real_order(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer('noura@example.com', 'نورة العتيبي');
        $order = $this->makePaidOrder($customer, $product);

        $service = app(ReviewService::class);
        $review = $service->submit($customer, (int) $product->id, ['rating' => 5, 'body' => 'ملف متكامل']);
        $service->approve($review);
        $service->reply($review, 'شكرًا لك، سعداء بأنه نفعك.');

        $response = $this->getJson("/api/v1/products/{$product->id}/reviews")->assertOk();

        // The account's own name, family name reduced to an initial.
        $this->assertSame('نورة ع.', $response->json('items.0.authorName'));
        $this->assertTrue($response->json('items.0.verifiedPurchase'));
        $this->assertSame('التحاضير الحديثة', $response->json('items.0.reply.author'));
    }

    /**
     * Paid-content sanctity (§1.3): a private asset must never reach a
     * renderer, and unmoderated media must never be published. Both gates are
     * proven here because neither has a UI that could reveal a regression.
     */
    public function test_review_media_is_published_only_when_public_approved_and_url_backed(): void
    {
        $product = $this->makeProduct();
        $this->seedApproved($product, [5 => 1]);
        $reviewId = Review::query()->orderBy('id')->value('id');

        $cases = [
            // [visibility, media status, provider_ref, should appear]
            ['public_sample', Review::APPROVED, 'https://cdn.example/ok.webp', true],
            ['paid_private', Review::APPROVED, 'https://cdn.example/paid.webp', false],
            ['public_sample', Review::PENDING, 'https://cdn.example/unmoderated.webp', false],
            // No usable URL (object-storage ref) ⇒ omitted, never guessed at.
            ['public_sample', Review::APPROVED, 'garage/bucket/object-key', false],
        ];

        foreach ($cases as $i => [$visibility, $status, $ref, $shouldAppear]) {
            $assetId = DB::table('assets')->insertGetId([
                'provider' => 'external',
                'provider_ref' => $ref,
                'original_name' => "shot{$i}.webp",
                'sha256' => hash('sha256', (string) $i),
                'size_bytes' => 1024,
                'mime' => 'image/webp',
                'visibility' => $visibility,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('review_media')->insert([
                'review_id' => $reviewId,
                'asset_id' => $assetId,
                'alt' => "صورة {$i}",
                'status' => $status,
                'position' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $media = $this->getJson("/api/v1/products/{$product->id}/reviews")
                ->assertOk()
                ->json('items.0.media') ?? [];

            $urls = array_column($media, 'url');
            $this->assertSame(
                $shouldAppear,
                in_array($ref, $urls, true),
                "case {$i} ({$visibility}/{$status}) leaked or dropped incorrectly",
            );

            DB::table('review_media')->where('asset_id', $assetId)->delete();
        }
    }

    public function test_an_unknown_product_is_a_real_404(): void
    {
        $this->getJson('/api/v1/products/999999/reviews')->assertNotFound();
    }
}
