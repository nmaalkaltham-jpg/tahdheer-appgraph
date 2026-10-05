<?php

namespace App\Domain\Reviews;

use App\Domain\Outbox\OutboxWriter;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewCategoryScore;
use App\Domain\Reviews\Models\ReviewReply;
use App\Domain\Reviews\Models\ReviewVote;
use App\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Write side of the Reviews domain (MIP §4.7).
 *
 * Three invariants hold here and nowhere else:
 *   · a review requires a verified purchase (PurchaseVerifier);
 *   · a review starts PENDING — nothing reaches the public feed without a
 *     human decision;
 *   · identity is the account. There is no author-name parameter on any
 *     method, so a display name can never be spoofed through this service.
 *
 * Every state change and its outbox row commit in ONE transaction: the
 * aggregate can never drift from the reviews that produced it.
 */
class ReviewService
{
    /** Store replies are signed with the brand, never with a staff name. */
    public const STORE_AUTHOR = 'التحاضير الحديثة';

    public function __construct(
        private readonly PurchaseVerifier $verifier,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * @param  array{rating: int, title?: ?string, body?: ?string, categoryScores?: array<string, int>}  $data
     *
     * @throws ReviewDenied when the customer did not buy the product, or already reviewed it
     */
    public function submit(Customer $customer, int $productId, array $data): Review
    {
        $proof = $this->verifier->verify((int) $customer->id, $productId);

        if ($proof === null) {
            throw new ReviewDenied(ReviewDenied::NOT_PURCHASED);
        }

        $exists = Review::query()
            ->where('product_id', $productId)
            ->where('customer_id', $customer->id)
            ->exists();

        if ($exists) {
            throw new ReviewDenied(ReviewDenied::ALREADY_REVIEWED);
        }

        try {
            return DB::transaction(function () use ($customer, $productId, $data, $proof) {
                $review = Review::query()->create([
                    'product_id' => $productId,
                    'customer_id' => $customer->id,
                    'order_id' => $proof['orderId'],
                    'variant_purchased' => $proof['variant'],
                    'rating' => $data['rating'],
                    'title' => $data['title'] ?? null,
                    'body' => $data['body'] ?? null,
                    /*
                     * صفة المراجِع ومدينته كما كتبهما لحظةَ التقييم. تمرّ
                     * على المراجعة البشريّة مع النصّ نفسه — فهي نصٌّ حرّ
                     * يُنشر، لا حقل تصنيفٍ داخليّ.
                     */
                    'author_role' => $data['authorRole'] ?? null,
                    'author_city' => $data['authorCity'] ?? null,
                    'status' => Review::PENDING,
                ]);

                foreach ($this->cleanScores($data['categoryScores'] ?? []) as $criterion => $score) {
                    ReviewCategoryScore::query()->create([
                        'review_id' => $review->id,
                        'criterion' => $criterion,
                        'score' => $score,
                    ]);
                }

                $this->outbox->write(
                    'review', (string) $review->id, 'review.submitted',
                    ['reviewId' => $review->id, 'productId' => $productId, 'rating' => $review->rating],
                    'review.submitted:'.$review->id,
                );

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the race against a concurrent submit — same honest answer.
            throw new ReviewDenied(ReviewDenied::ALREADY_REVIEWED);
        }
    }

    /**
     * Publish a review. Emits review.approved, whose outbox consumer rebuilds
     * the aggregate. Re-approving an already-approved review is a no-op.
     */
    /** الانتقالات المسموحة: المرفوض لا يُنشر ثانيةً بلا مراجعةٍ جديدة. */
    public const TRANSITIONS = [
        Review::APPROVED => [Review::PENDING],
        Review::REJECTED => [Review::PENDING, Review::APPROVED],
    ];

    public function approve(Review $review): Review
    {
        return DB::transaction(function () use ($review) {
            $review = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

            if ($review->status === Review::APPROVED) {
                return $review;
            }

            if (! in_array($review->status, self::TRANSITIONS[Review::APPROVED], true)) {
                throw new \DomainException("لا يُنشر تقييمٌ في الحالة «{$review->status}».");
            }

            $review->update(['status' => Review::APPROVED, 'moderated_at' => $review->moderated_at ?? now()]);

            $this->outbox->write(
                'review', (string) $review->id, 'review.approved',
                ['reviewId' => $review->id, 'productId' => $review->product_id, 'rating' => $review->rating],
                'review.approved:'.$review->id,
            );

            return $review;
        });
    }

    /**
     * Reject a review. A previously approved review that gets rejected must
     * leave the aggregate too, so this also emits review.approved — the
     * consumer recomputes from the approved set either way.
     */
    public function reject(Review $review, ?string $note = null): Review
    {
        return DB::transaction(function () use ($review, $note) {
            $review = Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

            if ($review->status === Review::REJECTED) {
                return $review;
            }

            $wasApproved = $review->status === Review::APPROVED;

            $review->update([
                'status' => Review::REJECTED,
                'moderated_at' => $review->moderated_at ?? now(),
                'moderation_note' => $note,
            ]);

            if ($wasApproved) {
                $this->outbox->write(
                    'review', (string) $review->id, 'review.approved',
                    ['reviewId' => $review->id, 'productId' => $review->product_id, 'rating' => $review->rating],
                    'review.rejected:'.$review->id,
                );
            }

            return $review;
        });
    }

    /** Store reply, signed «التحاضير الحديثة». Re-replying overwrites the text. */
    public function reply(Review $review, string $body): ReviewReply
    {
        return ReviewReply::query()->updateOrCreate(
            ['review_id' => $review->id],
            ['body' => $body, 'author_label' => self::STORE_AUTHOR, 'published_at' => now()],
        );
    }

    /**
     * Helpful vote. Unique per customer per review — the counter is a cached
     * COUNT of review_votes, recomputed inside the same transaction so it can
     * never drift from the ballots.
     *
     * @throws ReviewDenied when the review is not public, or already voted
     */
    public function voteHelpful(Review $review, Customer $customer): Review
    {
        if ($review->status !== Review::APPROVED) {
            throw new ReviewDenied(ReviewDenied::NOT_APPROVED);
        }

        try {
            return DB::transaction(function () use ($review, $customer) {
                /*
                 * القفل على صفّ التقييم قبل العدّ — وإلّا ضاع صوت.
                 *
                 * صوتان متزامنان يُدخلان صفَّين بمفتاحين مختلفين فينجحان معًا،
                 * ثمّ يعدّ كلٌّ منهما تحت `READ COMMITTED` فلا يرى صفّ الآخر
                 * غير المُودَع: كلاهما يحسب العدد نفسه ويكتبه، فيبقى العدّاد
                 * أقلّ من الأصوات بواحد. والقفل يجعل الثاني ينتظر إيداع
                 * الأوّل فيعدّ صفَّه.
                 */
                Review::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

                ReviewVote::query()->create([
                    'review_id' => $review->id,
                    'customer_id' => $customer->id,
                ]);

                $review->update([
                    'helpful_count' => ReviewVote::query()->where('review_id', $review->id)->count(),
                ]);

                return $review->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new ReviewDenied(ReviewDenied::ALREADY_VOTED);
        }
    }

    /**
     * Keep only the 6 sanctioned criteria with an in-range score. Unknown keys
     * are dropped silently rather than stored as a criterion nothing renders.
     *
     * @param  array<string, mixed>  $scores
     * @return array<string, int>
     */
    private function cleanScores(array $scores): array
    {
        $clean = [];

        foreach ($scores as $criterion => $score) {
            if (! array_key_exists($criterion, ReviewCategoryScore::CRITERIA)) {
                continue;
            }
            $score = (int) $score;
            if ($score >= 1 && $score <= 5) {
                $clean[$criterion] = $score;
            }
        }

        return $clean;
    }
}
