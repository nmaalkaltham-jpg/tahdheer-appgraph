<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reviews\ReviewDenied;
use App\Domain\Reviews\ReviewerIdentity;
use App\Domain\Reviews\ReviewFeed;
use App\Domain\Reviews\ReviewService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewAggregateResource;
use App\Http\Resources\ReviewResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public review endpoints (MIP §4.7 / §5.6).
 *
 * `store` identifies the reviewer through ReviewerIdentity (the interim
 * X-Order-Token scheme — see that class for the contract and migration path).
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewFeed $feed,
        private readonly ReviewService $reviews,
    ) {}

    /** Approved reviews only. There is no parameter that can widen this. */
    public function index(Request $request, int $productId): JsonResponse
    {
        if (! Product::query()->whereKey($productId)->exists()) {
            return response()->json(['message' => 'المنتج غير موجود'], 404);
        }

        $sort = $request->query('sort');
        $sort = in_array($sort, ReviewFeed::SORTS, true) ? $sort : ReviewFeed::DEFAULT_SORT;
        $stars = $request->filled('stars') ? (int) $request->query('stars') : null;

        $page = $this->feed->forProduct($productId, [
            'sort' => $sort,
            'stars' => $stars,
            'q' => $request->query('q'),
            'cursor' => $request->query('cursor'),
        ]);

        $aggregate = $this->feed->aggregate($productId);

        return response()->json([
            'items' => ReviewResource::collection($page->items())->resolve($request),
            // null ⇒ no approved reviews yet; the UI invites the first one
            // instead of rendering a zeroed summary.
            'aggregate' => $aggregate === null
                ? null
                : (new ReviewAggregateResource($aggregate, $this->feed->categoryAverages($productId)))->resolve($request),
            // Always the full approved set — never narrowed by ?stars/?q.
            'distribution' => ReviewAggregateResource::bars($aggregate?->distribution),
            'sort' => $sort,
            'pageInfo' => ['nextCursor' => $page->nextCursor()?->encode()],
        ]);
    }

    /** Submit a review: verified purchasers only, always pending moderation. */
    public function store(Request $request, int $productId): JsonResponse
    {
        if (! Product::query()->whereKey($productId)->exists()) {
            return response()->json(['message' => 'المنتج غير موجود'], 404);
        }

        $customer = ReviewerIdentity::resolve($request);

        if ($customer === null) {
            return response()->json(['message' => 'سجّل الدخول برمز الطلب لتتمكن من التقييم'], 401);
        }

        $data = $request->validate([
            'rating' => 'required|integer|between:1,5',
            'title' => 'nullable|string|max:120',
            'body' => 'required|string|min:3|max:2000',
            'categoryScores' => 'nullable|array',
            'categoryScores.*' => 'integer|between:1,5',
            /*
             * صفة المراجِع ومدينته — اختياريّتان، ويُنشران تحت اسمه. الحدّان
             * مطابقان لعمودَي التخزين فيُرفض الزائد برسالةٍ لا ببترٍ صامت.
             */
            'authorRole' => 'nullable|string|max:120',
            'authorCity' => 'nullable|string|max:80',
        ], [
            'rating.required' => 'اختر عدد النجوم',
            'rating.between' => 'التقييم من نجمة إلى خمس نجوم',
            'body.required' => 'اكتب رأيك في المنتج',
            'body.min' => 'اكتب رأيك في المنتج',
            'body.max' => 'النص أطول من الحد المسموح',
        ]);

        try {
            $review = $this->reviews->submit($customer, $productId, [
                'rating' => (int) $data['rating'],
                'title' => $data['title'] ?? null,
                'body' => $data['body'],
                'categoryScores' => $data['categoryScores'] ?? [],
                'authorRole' => $data['authorRole'] ?? null,
                'authorCity' => $data['authorCity'] ?? null,
            ]);
        } catch (ReviewDenied $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }

        return response()->json([
            'status' => $review->status,
            'message' => 'وصلنا تقييمك، وسيُنشر بعد المراجعة',
            'review' => [
                'id' => $review->id,
                'rating' => $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'verifiedPurchase' => $review->order_id !== null,
                'createdAt' => $review->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}
