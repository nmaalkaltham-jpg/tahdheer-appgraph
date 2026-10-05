<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\ReviewDenied;
use App\Domain\Reviews\ReviewerIdentity;
use App\Domain\Reviews\ReviewService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «هل كان هذا التقييم مفيدًا؟» — one vote per account per review, enforced by a
 * unique index (not by the UI), and rate-limited at the route.
 *
 * The response returns the server's authoritative count so an optimistic
 * client increment is corrected rather than trusted.
 */
class ReviewVoteController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function store(Request $request, int $reviewId): JsonResponse
    {
        $customer = ReviewerIdentity::resolve($request);

        if ($customer === null) {
            return response()->json(['message' => 'سجّل الدخول برمز الطلب لتتمكن من التصويت'], 401);
        }

        $review = Review::query()->find($reviewId);

        // A pending or rejected review is not publicly addressable — the same
        // 404 as a missing one, so voting cannot probe the moderation queue.
        if ($review === null) {
            return response()->json(['message' => 'هذا التقييم غير متاح'], 404);
        }

        try {
            $review = $this->reviews->voteHelpful($review, $customer);
        } catch (ReviewDenied $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }

        return response()->json([
            'reviewId' => $review->id,
            'helpfulCount' => $review->helpful_count,
            'voted' => true,
        ], 201);
    }
}
