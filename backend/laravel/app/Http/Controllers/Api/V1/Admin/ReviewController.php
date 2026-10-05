<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\ReviewService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * إدارة المراجعات — الطبقة التي كانت مكتوبة ولم تُوصَل.
 *
 * `ReviewService::approve/reject/reply` مبنيّة ومُختبَرة منذ بناء النطاق،
 * وملفّ المسارات يقول بنصّه لماذا لم تُنشر: «لا يوجد حارس إدارة بعد، واختراع
 * مصادقة هنا أسوأ من غياب النقطة — مسارٌ بلا حارس ينشر محتوى عامًّا بلا هويّة».
 * الحارس موجود اليوم، فهذه هي النقطة التي انتظرها.
 *
 * الأثر المقيس قبل هذا: كل مراجعة يكتبها عميل تدخل `pending` ولا يملك أحد
 * سبيلًا إلى اعتمادها — أي أن باب المراجعات كان مفتوحًا للكتابة ومغلقًا
 * للنشر.
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewService $reviews,
        private readonly AdminAuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:all,pending,approved,rejected',
        ]);

        $status = $validated['status'] ?? Review::PENDING;

        $query = Review::query()->with(['product:id,title,slug', 'customer:id,name', 'reply']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // الأقدم أوّلًا في الانتظار: المراجعة التي طال انتظارها أولى بالقرار.
        $rows = $query
            ->orderBy($status === Review::PENDING ? 'created_at' : 'id', $status === Review::PENDING ? 'asc' : 'desc')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Review $review) => $this->present($review))->all(),
            'counts' => $this->counts(),
        ]);
    }

    public function approve(Request $request, int $reviewId): JsonResponse
    {
        $review = Review::query()->findOrFail($reviewId);
        $before = $review->status;

        $review = $this->reviews->approve($review);

        $this->audit->log(
            $this->actor(), 'review.approved', 'review', $review->id,
            ['status' => $before], ['status' => $review->status], null, $request,
        );

        return response()->json(['data' => $this->present($review->fresh(['product', 'customer', 'reply']))]);
    }

    public function reject(Request $request, int $reviewId): JsonResponse
    {
        $validated = $request->validate(['note' => 'nullable|string|max:1000']);

        $review = Review::query()->findOrFail($reviewId);
        $before = $review->status;

        $review = $this->reviews->reject($review, $validated['note'] ?? null);

        $this->audit->log(
            $this->actor(), 'review.rejected', 'review', $review->id,
            ['status' => $before], ['status' => $review->status], null, $request,
        );

        return response()->json(['data' => $this->present($review->fresh(['product', 'customer', 'reply']))]);
    }

    /**
     * ردّ المتجر — يُنشر باسم المتجر لا باسم مشرف.
     *
     * الردّ لا يُقبل إلا على مراجعة معتمَدة: الردّ يُنشر مع المراجعة في الصفحة
     * العامّة، فردٌّ تحت مراجعة لم تُنشر إمّا يظهر وحده — نصفَ حوار بلا سؤال —
     * وإمّا يبقى مخبوءًا فيظنّ المشرف أنه ردّ ولم يردّ. الشرط هنا لا في
     * الخدمة لأنه شرط هذا العقد: الخدمة تُستدعى من الطابور والمزامنة أيضًا.
     */
    public function reply(Request $request, int $reviewId): JsonResponse
    {
        $validated = $request->validate(['body' => 'required|string|min:2|max:2000']);

        $review = Review::query()->findOrFail($reviewId);

        if ($review->status !== Review::APPROVED) {
            return response()->json([
                'message' => 'لا يُردّ إلا على مراجعة معتمَدة — اعتمدها أوّلًا ثم ردّ.',
                'error' => ['code' => 'REVIEW_NOT_APPROVED'],
            ], 422);
        }

        $this->reviews->reply($review, $validated['body']);

        $this->audit->log(
            $this->actor(), 'review.replied', 'review', $review->id, null, null, null, $request,
        );

        return response()->json(['data' => $this->present($review->fresh(['product', 'customer', 'reply']))]);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = Review::query()
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(static fn ($n) => (int) $n)
            ->all();

        return [
            'all' => array_sum($counts),
            Review::PENDING => $counts[Review::PENDING] ?? 0,
            Review::APPROVED => $counts[Review::APPROVED] ?? 0,
            Review::REJECTED => $counts[Review::REJECTED] ?? 0,
        ];
    }

    /** @return array<string, mixed> */
    private function present(Review $review): array
    {
        return [
            'id' => $review->id,
            'rating' => $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'status' => $review->status,
            // شارة الشراء الموثّق: سطر طلب حقيقي، لا ادّعاء.
            'verified' => $review->order_id !== null,
            'product' => $review->product?->title,
            'productSlug' => $review->product?->slug,
            'customer' => $review->customer?->name,
            'helpfulCount' => $review->helpful_count,
            'reply' => $review->reply?->body,
            'createdAt' => $review->created_at?->toIso8601String(),
            'moderatedAt' => $review->moderated_at?->toIso8601String(),
        ];
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
