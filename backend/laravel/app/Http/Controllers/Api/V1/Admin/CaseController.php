<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Cases\CaseRemediation;
use App\Domain\Cases\CaseService;
use App\Domain\Cases\CaseTransitionRefused;
use App\Domain\Cases\Models\CaseEvent;
use App\Domain\Cases\Models\WorkCase;
use App\Domain\Cases\RemediationRefused;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * القضايا: احتيالٌ وحوادثُ ودعم في سطحٍ واحد.
 *
 * ─── لماذا وحدة تحكّمٍ واحدة ───
 *
 * النطاق واحد وآلة الحالات واحدة. ووحدةُ تحكّمٍ لكلّ نوع تُنتج ثلاث نسخٍ من
 * الترشيح والعرض والانتقال تتباعد بأوّل تعديل.
 *
 * والترشيح بالنوع لا فصلُ المسارات: «أرِني كلّ ما هو مفتوح» سؤالٌ مشروع لا
 * يُجاب حين تكون المسارات ثلاثة.
 */
class CaseController extends Controller
{
    public function __construct(
        private readonly CaseService $cases,
        private readonly CaseRemediation $remediation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'kind' => 'sometimes|string|in:FRAUD,INCIDENT,SUPPORT',
            'state' => 'sometimes|string|in:NEW,ASSIGNED,INVESTIGATING,WAITING,RESOLVED,CLOSED',
            'severity' => 'sometimes|string|in:INFO,WARNING,HIGH,CRITICAL',
            'openOnly' => 'sometimes|boolean',
        ]);

        $query = WorkCase::query()
            ->when($filters['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($filters['state'] ?? null, fn ($q, $s) => $q->where('state', $s))
            ->when($filters['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($filters['openOnly'] ?? false, fn ($q) => $q->whereNotIn('state', WorkCase::TERMINAL));

        /*
         * الأشدّ أوّلًا ثمّ الأقدم: الشاشة تُفتح لتقرّر ما يُعمل الآن، لا
         * لتتصفّح. والترتيب الزمنيّ وحده يدفن حادثةً حرجة تحت ضجيج.
         */
        $cases = $query
            ->orderByRaw("case severity when 'CRITICAL' then 0 when 'HIGH' then 1 when 'WARNING' then 2 else 3 end")
            ->orderBy('opened_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $cases->map($this->present(...))->values(),
            'open' => WorkCase::query()->whereNotIn('state', WorkCase::TERMINAL)->count(),
            // الخرق عدٌّ مستقلّ: «مفتوحة» لا تقول متى كان يجب أن يُردّ عليها.
            'breached' => WorkCase::query()->whereNotIn('state', WorkCase::SETTLED)
                ->whereNotNull('breached_at')->count(),
            'critical' => WorkCase::query()->whereNotIn('state', WorkCase::TERMINAL)
                ->where('severity', 'CRITICAL')->count(),
            'unassigned' => WorkCase::query()->whereNotIn('state', WorkCase::TERMINAL)
                ->whereNull('assigned_to_admin_user_id')->count(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $case = WorkCase::query()->find($id);

        if ($case === null) {
            return response()->json(['message' => 'لا قضيّة بهذا المعرّف.'], 404);
        }

        return response()->json([
            'data' => $this->present($case) + [
                'timeline' => $case->events->map(fn (CaseEvent $e) => [
                    'id' => $e->id,
                    'action' => $e->action,
                    'fromState' => $e->from_state,
                    'toState' => $e->to_state,
                    'note' => $e->note,
                    'evidence' => $e->evidence,
                    'actorId' => $e->actor_admin_user_id,
                    'at' => $e->created_at?->toIso8601String(),
                ])->values(),
                // القائمة المغلقة تُرسَل مع القضيّة: الواجهة تعرض ولا تُعرّف.
                'resolutionCodes' => CaseService::RESOLUTION_CODES,
            ],
        ]);
    }

    public function transition(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $data = $request->validate([
            'state' => 'required|string|in:ASSIGNED,INVESTIGATING,WAITING,RESOLVED,CLOSED',
            'note' => 'sometimes|nullable|string|max:2000',
            'resolutionCode' => 'sometimes|nullable|string|max:64',
            'rootCause' => 'sometimes|nullable|string|max:1000',
            'preventiveAction' => 'sometimes|nullable|string|max:1000',
        ]);

        if (WorkCase::query()->find($id) === null) {
            return response()->json(['message' => 'لا قضيّة بهذا المعرّف.'], 404);
        }

        try {
            $case = $this->cases->transition(
                $id, $data['state'], $actor, $data['note'] ?? null, $data['resolutionCode'] ?? null,
                $data['rootCause'] ?? null, $data['preventiveAction'] ?? null,
            );
        } catch (CaseTransitionRefused $e) {
            // ما كان مسموحًا يُعاد مع الرفض: المشغّل يقرأ ما يستطيع فعله.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'CASE_TRANSITION_REFUSED',
                'allowedNext' => $e->allowed,
            ], 422);
        }

        return response()->json(['data' => $this->present($case)]);
    }

    public function assign(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $data = $request->validate(['adminUserId' => 'present|nullable|integer|exists:admin_users,id']);

        if (WorkCase::query()->find($id) === null) {
            return response()->json(['message' => 'لا قضيّة بهذا المعرّف.'], 404);
        }

        return response()->json([
            'data' => $this->present($this->cases->assign($id, $data['adminUserId'], $actor)),
        ]);
    }

    public function note(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $data = $request->validate(['note' => 'required|string|min:1|max:2000']);

        if (WorkCase::query()->find($id) === null) {
            return response()->json(['message' => 'لا قضيّة بهذا المعرّف.'], 404);
        }

        $event = $this->cases->note($id, $data['note'], $actor);

        return response()->json(['id' => $event->id], 201);
    }

    /**
     * ينفّذ فعل إصلاحٍ على القضيّة.
     *
     * الفعل يمرّ بسجلّ العمليّات: إعادة تشغيل خطّافٍ أثرٌ خارجيّ، والزرّ
     * يُنقر مرّتين. فالخمول هنا هو الفرق بين إصلاحٍ ومضاعفةِ عطل.
     */
    public function remediate(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|string|max:64',
            'idempotencyKey' => 'required|string|min:8|max:120',
            'reason' => 'sometimes|nullable|string|max:2000',
        ]);

        $case = WorkCase::query()->find($id);

        if ($case === null) {
            return response()->json(['message' => 'لا قضيّة بهذا المعرّف.'], 404);
        }

        try {
            $result = $this->remediation->execute(
                $case, $data['action'], $data['idempotencyKey'], $actor, $data['reason'] ?? null,
            );
        } catch (RemediationRefused $e) {
            // السبب يُعاد كما هو: «غير متاح» و«إرشاديّ» و«مغلقة» ثلاثة معانٍ.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'REMEDIATION_REFUSED',
                'action' => $e->action,
                'available' => $this->remediation->availableFor($case),
            ], 422);
        }

        return response()->json($result, $result['duplicate'] ? 200 : 202);
    }

    /** @return array<string, mixed> */
    private function present(WorkCase $case): array
    {
        return [
            'id' => $case->id,
            'kind' => $case->kind,
            // المهل والتصعيد يُقرآن مع القضيّة: التأخّر يجب أن يُرى لا يُحسب.
            'firstResponseDueAt' => $case->first_response_due_at?->toIso8601String(),
            'resolutionDueAt' => $case->resolution_due_at?->toIso8601String(),
            'firstRespondedAt' => $case->first_responded_at?->toIso8601String(),
            'breachedAt' => $case->breached_at?->toIso8601String(),
            'escalationLevel' => (int) $case->escalation_level,
            'state' => $case->state,
            'severity' => $case->severity,
            'title' => $case->title,
            'subject' => $case->subject_type === null ? null : $case->subject_type.'#'.$case->subject_id,
            'customerId' => $case->customer_id,
            'assignedTo' => $case->assigned_to_admin_user_id,
            'reasonCodes' => (array) ($case->reason_codes ?? []),
            'resolutionCode' => $case->resolution_code,
            'openedAt' => $case->opened_at?->toIso8601String(),
            'resolvedAt' => $case->resolved_at?->toIso8601String(),
            // الحالات الممكنة تُرسل مع القضيّة: الواجهة لا تُعيد بناء الخريطة.
            'allowedNext' => CaseService::allowedFrom((string) $case->state),
            /*
             * الأفعال المتاحة من الخادم: حسابُها في الواجهة يُظهر زرًّا
             * لقضيّةٍ لا يصلح لها، فيُنقر فيُردّ ٤٢٢ — ويُقرأ عطلًا لا منعًا.
             */
            'remediations' => $case->isTerminal() ? [] : $this->remediation->availableFor($case),
            'rootCause' => $case->root_cause,
            'preventiveAction' => $case->preventive_action,
        ];
    }
}
