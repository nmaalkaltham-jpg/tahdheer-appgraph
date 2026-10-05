<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\AdminValidation;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ai\AiControlService;
use App\Domain\Ai\AiHealthService;
use App\Domain\Ai\AiPolicy;
use App\Domain\Ai\AiRequestLedger;
use App\Domain\Ai\AiTask;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Jobs\ProductAiEnrichmentJob;
use App\Domain\Ai\Models\AiRun;
use App\Domain\Ai\Models\AiSuggestion;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * مركز تحكّم الذكاء — الحالة والأفعال.
 *
 * ─── لماذا القراءة والكتابة بصلاحيّتين ───
 *
 * قراءة الحال تكشف بنية التشغيل فتُحرَس بـ`audit.read` كما `‎/admin/system‎`.
 * والإيقاف والاستئناف ومسح المخزون أفعالٌ ذات أثر، فتُحرَس بـ`system.manage`
 * — الصلاحية نفسها التي تحرس إحياء صندوق الصادر. ولا صلاحيّة جديدة: إضافةُ
 * واحدةٍ تعني ترحيل كلّ دورٍ قائم، وقد سبق أن أُسقط اقتراحٌ كهذا للسبب نفسه.
 *
 * ─── ولماذا لا نقطة نهايةٍ تستدلّ ───
 *
 * لا مسارَ هنا ينادي النموذج. الاستدلال يمرّ بالبوّابة من داخل الميزات
 * وطوابيرها؛ ونقطةٌ إداريّة تستدلّ مباشرةً كانت تصير بابًا خلفيًّا يتجاوز
 * الرايات وحدود التوازي.
 */
class AiController extends Controller
{
    public function __construct(
        private readonly AiControlService $control,
        private readonly AiHealthService $health,
        private readonly AdminAuditLogger $audit,
        private readonly AiRequestLedger $ledger,
        private readonly AiPolicy $policy,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json([
            'generatedAt' => now()->toIso8601String(),
            'ai' => $this->control->status(),
        ]);
    }

    /**
     * دفتر النداءات — الصفحة الواحدة بمن طلبها وما كلّفت ولماذا فشلت.
     *
     * ─── الحالة تُعرض بمفردات الواجهة ───
     *
     * الجدول يحمل ستّ حالات، والواجهة تُرشِّح بـ`ok|failed`. والترجمة هنا لا
     * في الواجهة: نافذتان تُترجمان تفترقان، وأوّل حالةٍ تُضاف تُنسى في إحداهما.
     */
    public function requests(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['all', 'ok', 'failed', 'queued', 'cancelled'])],
            'task' => 'sometimes|string|max:40',
            'entityType' => 'sometimes|string|max:60',
            'entityId' => 'sometimes|integer',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = AiRun::query()->orderByDesc('id');

        match ($filters['status'] ?? 'all') {
            'ok' => $query->where('status', 'SUCCEEDED'),
            // المرفوض خرجُه فشلٌ في عين المشغّل: نداءٌ لم يُنتج شيئًا صالحًا.
            'failed' => $query->whereIn('status', ['FAILED', 'REJECTED']),
            'queued' => $query->whereIn('status', ['QUEUED', 'RUNNING']),
            'cancelled' => $query->where('status', 'CANCELLED'),
            default => null,
        };

        foreach (['task' => 'task', 'entityType' => 'entity_type', 'entityId' => 'entity_id'] as $input => $column) {
            if (isset($filters[$input])) {
                $query->where($column, $filters[$input]);
            }
        }

        $paginator = $query->paginate(
            perPage: (int) ($filters['perPage'] ?? 25),
            page: (int) ($filters['page'] ?? 1),
        );

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (AiRun $run): array => $this->requestRow($run))->values(),
            'meta' => AdminValidation::meta($paginator),
        ]);
    }

    public function showRequest(int $id): JsonResponse
    {
        $run = AiRun::query()->find($id);

        if ($run === null) {
            return response()->json(['message' => 'لا نداء بهذا المعرّف.'], 404);
        }

        return response()->json([
            'data' => $this->requestRow($run) + [
                'promptId' => (string) $run->prompt_id,
                /*
                 * البصمة لا المدخل: السجلّ يقرؤه كلّ من يملك `audit.read`،
                 * ونصّ المنتج أو سؤال العميل ليس من شأنه. والبصمة تكفي
                 * لمطابقة نداءين ولكشف التكرار.
                 */
                'inputHash' => (string) $run->input_hash,
                'errorMessage' => $run->error_message,
                'startedAt' => $run->started_at?->toIso8601String(),
                'finishedAt' => $run->finished_at?->toIso8601String(),
                'cancelledAt' => $run->cancelled_at?->toIso8601String(),
                'retryOfId' => $run->retry_of_id === null ? null : (int) $run->retry_of_id,
                'retryBlocker' => $this->ledger->retryBlocker($run),
            ],
        ]);
    }

    /**
     * جدولة نداءٍ جديد.
     *
     * ─── لا استدلال هنا ───
     *
     * النقطة تحجز صفًّا وتُرسل مهمّة، والاستدلال يقع في العامل عبر البوّابة
     * بكلّ رقابتها. ونقطةٌ إداريّة تستدلّ مباشرةً كانت بابًا خلفيًّا يتجاوز
     * الرايات وحدّ التوازي وتحديد المعدّل.
     */
    public function storeRequest(Request $request, AdminUser $actor): JsonResponse
    {
        $data = $request->validate([
            'entityType' => ['required', Rule::in(array_keys(AiRequestLedger::SCHEDULABLE))],
            'entityId' => 'required|integer|min:1',
        ]);

        try {
            $this->policy->assertFeature('product_enrichment');
        } catch (AiUnavailable $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 503);
        }

        if (Product::query()->whereKey($data['entityId'])->doesntExist()) {
            return response()->json(['message' => 'لا منتج بهذا المعرّف.'], 404);
        }

        $run = $this->ledger->queue(
            AiTask::Classify,
            'product.classify',
            $data['entityType'],
            (int) $data['entityId'],
            (int) $actor->id,
        );

        ProductAiEnrichmentJob::dispatch((int) $data['entityId'], (int) $actor->id, (int) $run->id);

        $this->audit->log($actor, 'ai.request.queued', 'ai_run', (int) $run->id, null, ['entityId' => (int) $data['entityId']], $request->input('reason'), $request);

        return response()->json(['data' => $this->requestRow($run->fresh())], 202);
    }

    public function retryRequest(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $run = AiRun::query()->find($id);

        if ($run === null) {
            return response()->json(['message' => 'لا نداء بهذا المعرّف.'], 404);
        }

        $blocker = $this->ledger->retryBlocker($run);

        if ($blocker !== null) {
            // السبب يُقال: زرٌّ يفشل بصمت أسوأ من زرٍّ لا يظهر.
            throw ValidationException::withMessages(['request' => [$blocker]]);
        }

        $retry = $this->ledger->queue(
            AiTask::tryFrom((string) $run->task) ?? AiTask::Classify,
            (string) $run->prompt_id,
            (string) $run->entity_type,
            (int) $run->entity_id,
            (int) $actor->id,
            $run->field,
            retryOfId: (int) $run->id,
        );

        $retry->forceFill(['attempts' => (int) $run->attempts + 1])->save();

        ProductAiEnrichmentJob::dispatch((int) $run->entity_id, (int) $actor->id, (int) $retry->id);

        $this->audit->log($actor, 'ai.request.retried', 'ai_run', (int) $run->id, ['status' => $run->status], ['retryId' => (int) $retry->id], $request->input('reason'), $request);

        return response()->json(['data' => $this->requestRow($retry->fresh())], 202);
    }

    public function cancelRequest(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $run = AiRun::query()->find($id);

        if ($run === null) {
            return response()->json(['message' => 'لا نداء بهذا المعرّف.'], 404);
        }

        if (! $this->ledger->cancel($run)) {
            /*
             * الجاري لا يُلغى: الحساب وقع فعلًا، وقطعُه لا يوفّر شيئًا ويترك
             * المقعد محجوزًا. والمنتهي لا يُلغى بداهة.
             */
            throw ValidationException::withMessages([
                'request' => ['لا يُلغى إلّا نداءٌ ما زال في الطابور. حالته الآن: '.$run->status.'.'],
            ]);
        }

        $this->audit->log($actor, 'ai.request.cancelled', 'ai_run', (int) $run->id, ['status' => $run->status], ['status' => 'CANCELLED'], $request->input('reason'), $request);

        return response()->json(['data' => $this->requestRow($run->fresh())]);
    }

    /**
     * صفٌّ واحد بالعقد الذي كُتبت الواجهة عليه (`AiRequestRow`).
     *
     * @return array<string, mixed>
     */
    private function requestRow(AiRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'provider' => (string) $run->provider,
            'model' => $run->model,
            'task' => (string) $run->task,
            'field' => $run->field,
            'productId' => $run->entity_type === 'product' && $run->entity_id !== null ? (int) $run->entity_id : null,
            'actorEmail' => $run->actor_admin_user_id === null
                ? null
                : AdminUser::query()->whereKey($run->actor_admin_user_id)->value('email'),
            'promptVersion' => $run->prompt_version === 0 ? null : (string) $run->prompt_version,
            'status' => match ($run->status) {
                'SUCCEEDED' => 'ok',
                'QUEUED', 'RUNNING' => 'queued',
                'CANCELLED' => 'cancelled',
                default => 'failed',
            },
            'lifecycle' => (string) $run->status,
            'errorCode' => $run->error_code,
            'attempts' => (int) $run->attempts,
            'inputTokens' => (int) $run->input_units === 0 ? null : (int) $run->input_units,
            'outputTokens' => (int) $run->output_units === 0 ? null : (int) $run->output_units,
            /*
             * `null` لا صفر: النموذج محلّيّ بلا سعرٍ مُعلَن للرمز، وصفرٌ هنا
             * يُقرأ «مجّانيّ ومقيس» وهو ادّعاءٌ لا سند له.
             */
            'costMinor' => $run->cost_minor === null ? null : (int) $run->cost_minor,
            'latencyMs' => (int) $run->latency_ms === 0 ? null : (int) $run->latency_ms,
            'cached' => (bool) $run->cached,
            // عدد الاقتراحات التي نتجت — يُقرأ من جدولها لا يُخمَّن.
            'suggestions' => $run->entity_id === null ? null : AiSuggestion::query()
                ->where('entity_type', $run->entity_type)
                ->where('entity_id', $run->entity_id)
                ->count(),
            'createdAt' => $run->created_at?->toIso8601String(),
        ];
    }

    public function pause(Request $request, AdminUser $actor): JsonResponse
    {
        $this->control->pause();
        $this->audit->log($actor, 'ai.paused', 'ai', null, ['paused' => false], ['paused' => true], $request->input('reason'), $request);

        return response()->json(['paused' => true]);
    }

    public function resume(Request $request, AdminUser $actor): JsonResponse
    {
        $this->control->resume();
        $this->audit->log($actor, 'ai.resumed', 'ai', null, ['paused' => true], ['paused' => false], $request->input('reason'), $request);

        return response()->json(['paused' => false]);
    }

    /** فحصٌ حيّ يتجاوز التخزين القصير — زرّ «افحص الآن». */
    public function probe(): JsonResponse
    {
        return response()->json(['healthy' => $this->health->probe()]);
    }

    public function clearCache(Request $request, AdminUser $actor): JsonResponse
    {
        $result = $this->control->clearOutputCache();

        $this->audit->log($actor, 'ai.cache.cleared', 'ai', null, null, $result, $request->input('reason'), $request);

        return response()->json($result);
    }
}
