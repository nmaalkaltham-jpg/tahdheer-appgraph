<?php

namespace Tests\Feature\Reviews;

use App\Domain\Outbox\OutboxProcessor;
use App\Domain\Outbox\OutboxRegistry;
use App\Domain\Reviews\AggregateRebuilder;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewAggregate;
use App\Domain\Reviews\ReviewOutboxSubscriber;
use App\Domain\Reviews\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The aggregate is the only place a product's rating may come from, and it is
 * rebuilt exclusively through the outbox consumer or the artisan command.
 */
class ReviewAggregateTest extends TestCase
{
    use RefreshDatabase, ReviewsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        OutboxRegistry::flush();
    }

    public function test_the_212_review_golden_set_rebuilds_end_to_end(): void
    {
        $product = $this->makeProduct();
        $this->seedApproved($product, [5 => 190, 4 => 16, 3 => 4, 2 => 1, 1 => 1]);

        $aggregate = app(AggregateRebuilder::class)->rebuild((int) $product->id);

        $this->assertNotNull($aggregate);
        $this->assertSame(212, $aggregate->count);
        $this->assertSame(4.85, (float) $aggregate->avg_rating);
        $this->assertSame('4.9', number_format((float) $aggregate->avg_rating, 1));
        $this->assertSame(97, $aggregate->recommend_pct);

        $pct = array_column($aggregate->distribution, 'pct');
        $this->assertSame([90, 8, 2, 0, 0], array_values($pct));
        $this->assertSame(100, array_sum($pct));
        $this->assertSame(190, $aggregate->distribution[5]['count']);
    }

    public function test_approval_rebuilds_the_aggregate_through_the_outbox_consumer(): void
    {
        ReviewOutboxSubscriber::register();

        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makePaidOrder($customer, $product);

        $review = app(ReviewService::class)->submit($customer, (int) $product->id, [
            'rating' => 4,
            'body' => 'مفيد جدًا في التحضير.',
        ]);

        // Pending: nothing exists to aggregate yet.
        (new OutboxProcessor)->process();
        $this->assertDatabaseCount('review_aggregates', 0);

        app(ReviewService::class)->approve($review);

        // Still nothing until the outbox is drained — the rebuild is async
        // by design, never a side effect of the write.
        $this->assertDatabaseCount('review_aggregates', 0);

        $stats = (new OutboxProcessor)->process();
        $this->assertSame(1, $stats['processed']);

        $aggregate = ReviewAggregate::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(1, $aggregate->count);
        $this->assertSame(4.0, (float) $aggregate->avg_rating);
        $this->assertSame(100, $aggregate->recommend_pct);
        $this->assertSame(100, $aggregate->distribution[4]['pct']);
    }

    public function test_the_consumer_is_idempotent_under_retry(): void
    {
        ReviewOutboxSubscriber::register();

        $product = $this->makeProduct();
        $this->seedApproved($product, [5 => 2, 4 => 1]);
        $rebuilder = app(AggregateRebuilder::class);

        $first = $rebuilder->rebuild((int) $product->id);
        $second = $rebuilder->rebuild((int) $product->id);

        $this->assertSame($first->id, $second->id, 'A rebuild must overwrite, never insert a second row.');
        $this->assertSame(1, ReviewAggregate::query()->count());
        $this->assertSame($first->distribution, $second->distribution);
    }

    public function test_rejecting_the_last_approved_review_removes_the_aggregate_entirely(): void
    {
        ReviewOutboxSubscriber::register();

        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makePaidOrder($customer, $product);

        $service = app(ReviewService::class);
        $review = $service->submit($customer, (int) $product->id, ['rating' => 5, 'body' => 'ممتاز']);
        $service->approve($review);
        (new OutboxProcessor)->process();
        $this->assertDatabaseCount('review_aggregates', 1);

        // Taking it back must take the number back too — not leave a stale 5.0.
        $service->reject($review->refresh(), 'مخالف للشروط');
        (new OutboxProcessor)->process();

        $this->assertDatabaseCount('review_aggregates', 0);
    }

    public function test_pending_and_rejected_reviews_never_enter_the_average(): void
    {
        $product = $this->makeProduct();
        $this->seedApproved($product, [5 => 2]);
        $this->seedApproved($product, [1 => 3], Review::PENDING);
        $this->seedApproved($product, [1 => 2], Review::REJECTED);

        $aggregate = app(AggregateRebuilder::class)->rebuild((int) $product->id);

        $this->assertSame(2, $aggregate->count);
        $this->assertSame(5.0, (float) $aggregate->avg_rating);
    }

    public function test_the_artisan_command_rebuilds_every_product_with_reviews(): void
    {
        $first = $this->makeProduct('TD-A1');
        $second = $this->makeProduct('TD-A2');
        $this->seedApproved($first, [5 => 3]);
        $this->seedApproved($second, [3 => 1, 2 => 1]);

        $this->artisan('reviews:rebuild')
            ->expectsOutputToContain('2 product(s)')
            ->assertExitCode(0);

        $this->assertSame(3, ReviewAggregate::query()->where('product_id', $first->id)->value('count'));
        $this->assertSame(2.5, (float) ReviewAggregate::query()->where('product_id', $second->id)->value('avg_rating'));
    }

    public function test_the_artisan_command_can_target_one_product(): void
    {
        $product = $this->makeProduct();
        $this->seedApproved($product, [4 => 2]);

        $this->artisan('reviews:rebuild', ['--product' => (string) $product->id])->assertExitCode(0);
        $this->assertDatabaseCount('review_aggregates', 1);

        $this->artisan('reviews:rebuild', ['--product' => 'abc'])->assertExitCode(2);
    }
}
