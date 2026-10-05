<?php

namespace Tests\Feature\Home;

use App\Domain\Reviews\AggregateRebuilder;
use App\Domain\Reviews\Models\Review;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Reviews\ReviewsFixtures;
use Tests\TestCase;

/**
 * /api/v1/home/reviews is the section nobody can edit: it is arithmetic over
 * the reviews domain. These tests pin the two failure modes that would matter
 * most on a storefront — showing stars nobody gave, and showing a testimonial
 * nobody wrote or nobody paid for.
 */
class HomeReviewsTest extends TestCase
{
    use RefreshDatabase;
    use ReviewsFixtures;

    private function approve(Product $product, int $rating, string $body, ?int $orderId, string $email): Review
    {
        $customer = $this->makeCustomer($email, 'نورة العتيبي');

        return Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'order_id' => $orderId,
            'rating' => $rating,
            'body' => $body,
            'status' => Review::APPROVED,
        ]);
    }

    public function test_no_approved_ratings_means_no_section(): void
    {
        $product = $this->makeProduct();
        $this->seedApproved($product, [5 => 3], Review::PENDING);

        $this->getJson('/api/v1/home/reviews')->assertNoContent();
    }

    public function test_aggregate_is_the_weighted_rollup_of_published_products(): void
    {
        $rebuilder = new AggregateRebuilder;

        $one = $this->makeProduct('TD-R1');
        $this->seedApproved($one, [5 => 3, 4 => 1]);   // 19 / 4 = 4.75
        $rebuilder->rebuild($one->id);

        $two = $this->makeProduct('TD-R2');
        $this->seedApproved($two, [3 => 1]);           // 3 / 1 = 3.00
        $rebuilder->rebuild($two->id);

        // A retired product's stars must not be lent to the storefront.
        $hidden = $this->makeProduct('TD-R3');
        $this->seedApproved($hidden, [1 => 4]);
        $rebuilder->rebuild($hidden->id);
        $hidden->update(['status' => 'retired']);

        $response = $this->getJson('/api/v1/home/reviews')->assertOk()
            ->assertJsonPath('rating_count', 5);

        $this->assertEqualsWithDelta(4.4, $response->json('rating_avg'), 0.001); // (19 + 3) / 5
    }

    public function test_quotes_are_approved_order_backed_reviews_only(): void
    {
        $product = $this->makeProduct();
        $buyer = $this->makeCustomer('sara@example.com', 'سارة القحطاني');
        $order = $this->makePaidOrder($buyer, $product);

        $quoted = Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $buyer->id,
            'order_id' => $order['order']->id,
            'rating' => 5,
            'body' => 'الملف وفّر عليّ ساعات تحضير.',
            'status' => Review::APPROVED,
        ]);

        // Approved, but nothing backs the purchase.
        $this->approve($product, 5, 'رأي بلا طلب', null, 'anon@example.com');
        // Bought, wrote, but not moderated yet.
        $pendingBuyer = $this->makeCustomer('mona@example.com', 'منى الشهري');
        $pendingOrder = $this->makePaidOrder($pendingBuyer, $product);
        Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $pendingBuyer->id,
            'order_id' => $pendingOrder['order']->id,
            'rating' => 5,
            'body' => 'بانتظار المراجعة',
            'status' => Review::PENDING,
        ]);

        (new AggregateRebuilder)->rebuild($product->id);

        $response = $this->getJson('/api/v1/home/reviews')->assertOk()->assertJsonCount(1, 'quotes');

        $response->assertJsonPath('quotes.0.id', $quoted->id)
            ->assertJsonPath('quotes.0.text', 'الملف وفّر عليّ ساعات تحضير.')
            ->assertJsonPath('quotes.0.author', 'سارة ق.')
            ->assertJsonPath('quotes.0.verified', true)
            ->assertJsonPath('quotes.0.product_slug', $product->slug)
            ->assertJsonPath('quotes.0.product_title', $product->title);

        // No invented stat tiles and no invented job titles.
        $this->assertArrayNotHasKey('stats', $response->json());
        $this->assertArrayNotHasKey('author_role', $response->json('quotes.0'));
        $response->assertJsonPath('quotes.0.authorRole', null)->assertJsonPath('quotes.0.authorCity', null);
    }

    /** الصفة والمدينة تظهران حين كتبهما صاحب المراجعة — لا حين تُخمَّنان. */
    public function test_quote_carries_the_reviewers_own_role_and_city_when_present(): void
    {
        $product = $this->makeProduct();
        $buyer = $this->makeCustomer('noura@example.com', 'نورة العتيبي');
        $order = $this->makePaidOrder($buyer, $product);

        Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $buyer->id,
            'order_id' => $order['order']->id,
            'rating' => 5,
            'body' => 'منظّم وواضح.',
            'status' => Review::APPROVED,
            'author_role' => 'معلمة علوم',
            'author_city' => 'الرياض',
        ]);

        (new AggregateRebuilder)->rebuild($product->id);

        $this->getJson('/api/v1/home/reviews')
            ->assertOk()
            ->assertJsonPath('quotes.0.author_role', 'معلمة علوم')
            ->assertJsonPath('quotes.0.author_city', 'الرياض')
            ->assertJsonPath('quotes.0.authorRole', 'معلمة علوم')
            ->assertJsonPath('quotes.0.authorCity', 'الرياض');
    }

    public function test_real_aggregate_with_no_quotable_review_still_reports_the_rating(): void
    {
        $product = $this->makeProduct();
        $this->seedApproved($product, [5 => 2]);   // approved, but no order_id
        (new AggregateRebuilder)->rebuild($product->id);

        $response = $this->getJson('/api/v1/home/reviews')->assertOk()
            ->assertJsonPath('rating_count', 2)
            ->assertJsonCount(0, 'quotes');

        $this->assertEqualsWithDelta(5.0, $response->json('rating_avg'), 0.001);
    }
}
