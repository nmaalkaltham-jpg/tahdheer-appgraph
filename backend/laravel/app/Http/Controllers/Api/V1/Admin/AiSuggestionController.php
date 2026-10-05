<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ai\AiPolicy;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Jobs\ProductAiEnrichmentJob;
use App\Domain\Ai\Models\AiSuggestion;
use App\Domain\Ai\Product\SuggestionApplier;
use App\Domain\Queueing\QueueDepth;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * اقتراحات الذكاء — عرضٌ واعتمادٌ ورفض.
 *
 * ─── لماذا الاعتماد بـ`products.update` ───
 *
 * الاعتماد يكتب في المنتج فعلًا. فهو فعل تحريرِ منتجٍ بأداةٍ مختلفة، لا فعلُ
 * «ذكاء». ومن يملك تعديل المنتج يملك اعتماد اقتراحٍ عليه، ولا صلاحيّة جديدة
 * تُرحَّل على كلّ دور.
 *
 * ─── ولماذا ٢٠٢ للتحليل ───
 *
 * الاستدلال يتجاوز ميزانيّة الشاشة. الطلب يجدول مهمّةً ويعود فورًا — النمط
 * نفسه في `OperationsActionController::retryUnit`.
 */
class AiSuggestionController extends Controller
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly AiPolicy $policy,
        private readonly SuggestionApplier $applier,
    ) {}

    /** جدولة تحليل منتج. */
    public function enrichProduct(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $product = Product::query()->find($id);

        if ($product === null) {
            return response()->json(['message' => 'لا منتج بهذا المعرّف.'], 404);
        }

        try {
            /*
             * الرايات تُفحص عند الجدولة لا عند التنفيذ وحده: جدولةُ عملٍ
             * لميزةٍ مطفأة تملأ الطابور بما سيُرفض، وتُعطي المشرف ٢٠٢ كاذبة.
             */
            $this->policy->assertFeature('product_enrichment');
        } catch (AiUnavailable $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 503);
        }

        /*
         * سقفُ الطابور يُفحص هنا: «طوابير محدودة» قاعدةٌ صريحة، والرفض عند
         * الباب أرخص من تراكمٍ يلتهم الذاكرة ثمّ يُرفض واحدًا واحدًا.
         */
        if (($depth = $this->queueDepth()) !== null && $depth >= (int) config('ai.limits.max_queue_depth')) {
            return response()->json([
                'message' => "طابور الذكاء ممتلئ ({$depth}).",
                'code' => 'AI_QUEUE_FULL',
            ], 503);
        }

        ProductAiEnrichmentJob::dispatch($product->id, $actor->id);

        $this->audit->log($actor, 'ai.product.enrichment_requested', 'product', $product->id, null, null, $request->input('reason'), $request);

        return response()->json(['productId' => (int) $product->id, 'queued' => true], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'entityType' => 'sometimes|string|max:60',
            'entityId' => 'sometimes|integer',
            'status' => ['sometimes', Rule::in(['PENDING', 'APPROVED', 'REJECTED', 'SUPERSEDED'])],
            'perPage' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = AiSuggestion::query()->orderByDesc('id');

        foreach (['entityType' => 'entity_type', 'entityId' => 'entity_id', 'status' => 'status'] as $input => $column) {
            if (isset($filters[$input])) {
                $query->where($column, $filters[$input]);
            }
        }

        $paginator = $query->paginate((int) ($filters['perPage'] ?? 25));

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (AiSuggestion $s): array => $this->row($s))->values(),
            'meta' => [
                'total' => $paginator->total(),
                'perPage' => $paginator->perPage(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function approve(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $suggestion = AiSuggestion::query()->find($id);

        if ($suggestion === null) {
            return response()->json(['message' => 'لا اقتراح بهذا المعرّف.'], 404);
        }

        if (! $suggestion->isPending()) {
            throw ValidationException::withMessages([
                'suggestion' => ['بُتَّ في هذا الاقتراح سلفًا: '.$suggestion->status.'.'],
            ]);
        }

        try {
            $applied = DB::transaction(function () use ($suggestion, $actor): array {
                /*
                 * الاعتماد يُثبَّت قبل التطبيق داخل المعاملة نفسها: تطبيقٌ
                 * ينجح ثمّ يفشل تثبيتُه كان يترك المنتج مكتوبًا والاقتراح
                 * معلّقًا، فيُعتمد ثانيةً ويُكتب مرّتين.
                 */
                $suggestion->forceFill([
                    'status' => 'APPROVED',
                    'approved_by_admin_user_id' => $actor->id,
                    'approved_at' => now(),
                ])->save();

                return $this->applier->apply($suggestion);
            });
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'AI_APPLY_FAILED'], 422);
        }

        $this->audit->log(
            $actor,
            'ai.suggestion.approved',
            (string) $suggestion->entity_type,
            (int) $suggestion->entity_id,
            ['field' => $suggestion->field],
            ['suggestionId' => (int) $suggestion->id, 'applied' => $applied],
            $request->input('reason'),
            $request,
        );

        return response()->json(['suggestion' => $this->row($suggestion->fresh()), 'applied' => $applied]);
    }

    public function reject(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);

        $suggestion = AiSuggestion::query()->find($id);

        if ($suggestion === null) {
            return response()->json(['message' => 'لا اقتراح بهذا المعرّف.'], 404);
        }

        if (! $suggestion->isPending()) {
            throw ValidationException::withMessages([
                'suggestion' => ['بُتَّ في هذا الاقتراح سلفًا: '.$suggestion->status.'.'],
            ]);
        }

        /*
         * السبب إلزاميّ: رفضٌ بلا سبب لا يُحسّن الموجّه. وهذه الأسباب هي
         * مجموعة التقييم التالية.
         */
        $suggestion->forceFill(['status' => 'REJECTED', 'rejected_reason' => $data['reason']])->save();

        $this->audit->log($actor, 'ai.suggestion.rejected', (string) $suggestion->entity_type, (int) $suggestion->entity_id, ['field' => $suggestion->field], ['suggestionId' => (int) $suggestion->id], $data['reason'], $request);

        return response()->json(['suggestion' => $this->row($suggestion->fresh())]);
    }

    /**
     * القيمة القائمة للحقول التي سيمسّها هذا الاقتراح.
     *
     * `null` تعني «فارغ» — وهو ما يجعل الاعتماد يكتب. وقيمةٌ موجودة تعني أنّ
     * الاعتماد سيُبقيها ويُعيد «تُرك»، وهو ما يجب أن يراه المشرف قبل أن يضغط.
     *
     * @return array<string, string|null>
     */
    private function currentValue(AiSuggestion $s): array
    {
        if ($s->entity_type !== 'product') {
            return [];
        }

        return match ($s->field) {
            'classification' => $this->currentClassification((int) $s->entity_id),
            'seo' => $this->currentSeo((int) $s->entity_id),
            // الكلمات إضافيّة لا استبداليّة: لا «قبل» لها.
            default => [],
        };
    }

    /** @return array<string, string|null> */
    private function currentClassification(int $productId): array
    {
        $row = DB::table('products')
            ->leftJoin('stages', 'stages.id', '=', 'products.stage_id')
            ->leftJoin('grades', 'grades.id', '=', 'products.grade_id')
            ->leftJoin('subjects', 'subjects.id', '=', 'products.subject_id')
            ->leftJoin('semesters', 'semesters.id', '=', 'products.semester_id')
            ->where('products.id', $productId)
            ->first(['stages.name_ar as stage', 'grades.name_ar as grade', 'subjects.name_ar as subject', 'semesters.name_ar as semester']);

        return $row === null ? [] : [
            'stage' => $row->stage,
            'grade' => $row->grade,
            'subject' => $row->subject,
            'semester' => $row->semester,
        ];
    }

    /** @return array<string, string|null> */
    private function currentSeo(int $productId): array
    {
        $row = DB::table('product_seo')->where('product_id', $productId)->first(['seo_title', 'seo_desc']);

        return ['seo_title' => $row->seo_title ?? null, 'seo_desc' => $row->seo_desc ?? null];
    }

    /** عمق طابور الذكاء من القارئ المشترك — لا نسخة ثالثة من منطق العدّ. */
    private function queueDepth(): ?int
    {
        return QueueDepth::of((string) config('ai.queue'));
    }

    /** @return array<string, mixed> */
    private function row(AiSuggestion $s): array
    {
        return [
            'id' => (int) $s->id,
            'entityType' => (string) $s->entity_type,
            'entityId' => (int) $s->entity_id,
            'field' => (string) $s->field,
            'task' => (string) $s->task,
            'value' => (array) $s->value,
            'model' => (string) $s->model,
            'promptId' => (string) $s->prompt_id,
            'promptVersion' => (int) $s->prompt_version,
            'source' => (string) $s->source,
            'status' => (string) $s->status,
            // مئويّة أعلنها النموذج، أو `null` — لا صفرٌ يُقرأ «واثقٌ أنّه خاطئ».
            'confidencePct' => $s->confidence_pct === null ? null : (int) $s->confidence_pct,
            /*
             * القيمة الحاليّة بجوار المقترحة — «قبل» و«بعد» في نداءٍ واحد.
             *
             * تُحسب في الخادم لا في الواجهة: الواجهة كانت ستحتاج قراءة المنتج
             * وجداول المنهج والظهور لتبني المقارنة، فتصير ثلاثة نداءاتٍ وسطحًا
             * ثانيًا يعرف المخطَّط. والأهمّ أنّ «قبل» هنا هو ما سيقارنه
             * المُطبِّق نفسه عند الاعتماد — فما يراه المشرف هو ما سيقع.
             */
            'current' => $this->currentValue($s),
            'approvedAt' => $s->approved_at?->toIso8601String(),
            'rejectedReason' => $s->rejected_reason,
            'createdAt' => $s->created_at?->toIso8601String(),
        ];
    }
}
