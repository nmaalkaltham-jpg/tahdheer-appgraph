<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\ReviewDenied;
use App\Domain\Reviews\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Helpful votes: one per account per review, counted from real ballots. */
class ReviewVoteTest extends TestCase
{
    use RefreshDatabase, ReviewsFixtures;

    /** @return array{review: Review, voterToken: string} */
    private function approvedReviewAndVoter(): array
    {
        $product = $this->makeProduct();

        $author = $this->makeCustomer('noura@example.com', 'نورة العتيبي');
        $authorOrder = $this->makePaidOrder($author, $product);
        $service = app(ReviewService::class);
        $review = $service->submit($author, (int) $product->id, ['rating' => 5, 'body' => 'ملف متكامل']);
        $service->approve($review);

        $voter = $this->makeCustomer('sara@example.com', 'سارة القحطاني');
        $voterOrder = $this->makePaidOrder($voter, $product);

        return ['review' => $review->refresh(), 'voterToken' => $voterOrder['token']];
    }

    public function test_a_helpful_vote_is_counted_once_per_account(): void
    {
        ['review' => $review, 'voterToken' => $token] = $this->approvedReviewAndVoter();

        $this->withHeader('X-Order-Token', $token)
            ->postJson("/api/v1/reviews/{$review->id}/votes")
            ->assertCreated()
            ->assertJsonPath('helpfulCount', 1)
            ->assertJsonPath('voted', true);

        // The same account pressing again changes nothing — the DB unique
        // index is the guarantee, not the button's disabled state.
        $this->withHeader('X-Order-Token', $token)
            ->postJson("/api/v1/reviews/{$review->id}/votes")
            ->assertStatus(409)
            ->assertJsonPath('reason', ReviewDenied::ALREADY_VOTED);

        $this->assertDatabaseCount('review_votes', 1);
        $this->assertSame(1, $review->refresh()->helpful_count);
    }

    public function test_the_counter_always_equals_the_number_of_ballots(): void
    {
        ['review' => $review, 'voterToken' => $first] = $this->approvedReviewAndVoter();

        $second = $this->makeCustomer('mona@example.com', 'منى الشمري');
        $secondOrder = $this->makePaidOrder($second, $review->product);

        $this->withHeader('X-Order-Token', $first)->postJson("/api/v1/reviews/{$review->id}/votes")->assertCreated();
        $this->withHeader('X-Order-Token', $secondOrder['token'])
            ->postJson("/api/v1/reviews/{$review->id}/votes")
            ->assertCreated()
            ->assertJsonPath('helpfulCount', 2);

        $this->assertSame(
            $review->refresh()->helpful_count,
            (int) $review->votes()->count(),
        );
    }

    public function test_a_pending_review_cannot_be_voted_on(): void
    {
        $product = $this->makeProduct();
        $author = $this->makeCustomer('noura@example.com', 'نورة العتيبي');
        $this->makePaidOrder($author, $product);
        $review = app(ReviewService::class)->submit($author, (int) $product->id, ['rating' => 5, 'body' => 'ملف متكامل']);

        $voter = $this->makeCustomer('sara@example.com', 'سارة القحطاني');
        $voterOrder = $this->makePaidOrder($voter, $product);

        $this->withHeader('X-Order-Token', $voterOrder['token'])
            ->postJson("/api/v1/reviews/{$review->id}/votes")
            ->assertNotFound();

        $this->assertDatabaseCount('review_votes', 0);
    }

    public function test_voting_requires_an_identified_account(): void
    {
        ['review' => $review] = $this->approvedReviewAndVoter();

        $this->postJson("/api/v1/reviews/{$review->id}/votes")->assertUnauthorized();
        $this->assertDatabaseCount('review_votes', 0);
    }
}
