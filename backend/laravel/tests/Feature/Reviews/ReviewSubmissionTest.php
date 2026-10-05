<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\PurchaseVerifier;
use App\Domain\Reviews\ReviewDenied;
use App\Domain\Reviews\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The gate: only real purchasers, only once, always pending.
 */
class ReviewSubmissionTest extends TestCase
{
    use RefreshDatabase, ReviewsFixtures;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'rating' => 5,
            'title' => 'ملف ممتاز',
            'body' => 'استفدت منه في التحضير الأسبوعي وسهل التعديل.',
        ], $overrides);
    }

    public function test_a_purchaser_submits_a_pending_review_with_a_verified_badge(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makePaidOrder($customer, $product, 'PAID', 'نسخة 1446');

        $this->withHeader('X-Order-Token', $order['token'])
            ->postJson("/api/v1/products/{$product->id}/reviews", $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', Review::PENDING)
            ->assertJsonPath('review.verifiedPurchase', true);

        $this->assertDatabaseHas('reviews', [
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'order_id' => $order['order']->id,
            'rating' => 5,
            'status' => Review::PENDING,
            'variant_purchased' => 'نسخة 1446',
        ]);

        // The state change and its event committed together.
        $review = Review::query()->firstOrFail();
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'review.submitted',
            'idempotency_key' => 'review.submitted:'.$review->id,
        ]);
    }

    public function test_someone_who_never_bought_the_product_cannot_review_it(): void
    {
        $product = $this->makeProduct();
        $otherProduct = $this->makeProduct('TD-R2');
        $stranger = $this->makeStranger($otherProduct);

        // A REAL order token — just not for this product.
        $this->withHeader('X-Order-Token', $stranger['token'])
            ->postJson("/api/v1/products/{$product->id}/reviews", $this->payload())
            ->assertForbidden()
            ->assertJsonPath('reason', ReviewDenied::NOT_PURCHASED)
            ->assertJsonPath('message', 'التقييم متاح لمن اشترى المنتج فقط');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_an_unpaid_order_is_not_a_purchase(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makePaidOrder($customer, $product, 'AWAITING_PAYMENT');

        $this->withHeader('X-Order-Token', $order['token'])
            ->postJson("/api/v1/products/{$product->id}/reviews", $this->payload())
            ->assertForbidden()
            ->assertJsonPath('reason', ReviewDenied::NOT_PURCHASED);

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_a_missing_or_tampered_token_is_rejected_before_any_lookup(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $this->makePaidOrder($customer, $product);

        $this->postJson("/api/v1/products/{$product->id}/reviews", $this->payload())
            ->assertUnauthorized();

        $this->withHeader('X-Order-Token', 'not-a-real-token')
            ->postJson("/api/v1/products/{$product->id}/reviews", $this->payload())
            ->assertUnauthorized();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_one_review_per_customer_per_product_even_across_two_orders(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $first = $this->makePaidOrder($customer, $product);
        $second = $this->makePaidOrder($customer, $product);

        $this->withHeader('X-Order-Token', $first['token'])
            ->postJson("/api/v1/products/{$product->id}/reviews", $this->payload())
            ->assertCreated();

        // The old unique key (product, customer, order) would have allowed
        // this second review through on a different order id.
        $this->withHeader('X-Order-Token', $second['token'])
            ->postJson("/api/v1/products/{$product->id}/reviews", $this->payload(['rating' => 1]))
            ->assertStatus(409)
            ->assertJsonPath('reason', ReviewDenied::ALREADY_REVIEWED);

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_an_active_entitlement_is_accepted_as_proof_and_a_revoked_one_is_not(): void
    {
        $product = $this->makeProduct();
        $verifier = app(PurchaseVerifier::class);

        // Entitlement path with an order that is NOT in a paid state: access
        // is real, so the review is allowed — but no badge is claimed.
        $customer = $this->makeCustomer('sara@example.com', 'سارة القحطاني');
        $order = $this->makePaidOrder($customer, $product, 'REFUNDED');
        $this->grantEntitlement($customer, $product, $order);

        $proof = $verifier->verify((int) $customer->id, (int) $product->id);
        $this->assertNotNull($proof);
        $this->assertNull($proof['orderId'], 'A non-paid order must not earn the verified badge.');

        // Revoked entitlement + no paid order ⇒ no standing at all.
        $revokedCustomer = $this->makeCustomer('mona@example.com', 'منى الشمري');
        $revokedOrder = $this->makePaidOrder($revokedCustomer, $product, 'REFUNDED');
        $this->grantEntitlement($revokedCustomer, $product, $revokedOrder, 'REVOKED');

        $this->assertNull($verifier->verify((int) $revokedCustomer->id, (int) $product->id));
    }

    public function test_the_review_body_and_rating_are_required(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makePaidOrder($customer, $product);

        $this->withHeader('X-Order-Token', $order['token'])
            ->postJson("/api/v1/products/{$product->id}/reviews", ['title' => 'بدون نص'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rating', 'body']);
    }

    public function test_category_scores_are_stored_only_for_the_six_sanctioned_criteria(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makePaidOrder($customer, $product);

        app(ReviewService::class)->submit($customer, (int) $product->id, [
            'rating' => 4,
            'body' => 'جيد جدًا',
            'categoryScores' => [
                'content_quality' => 5,
                'download_ease' => 4,
                'invented_criterion' => 5, // silently dropped
            ],
        ]);

        $this->assertDatabaseCount('review_category_scores', 2);
        $this->assertDatabaseHas('review_category_scores', ['criterion' => 'content_quality', 'score' => 5]);
        $this->assertDatabaseMissing('review_category_scores', ['criterion' => 'invented_criterion']);
    }
}
