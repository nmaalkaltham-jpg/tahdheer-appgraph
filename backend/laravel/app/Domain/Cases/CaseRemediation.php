<?php

declare(strict_types=1);

namespace App\Domain\Cases;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Cases\Models\CaseEvent;
use App\Domain\Cases\Models\WorkCase;
use App\Domain\Finance\Reconciliation;
use App\Domain\Operations\Jobs\ReplayWebhookDeliveryJob;
use App\Domain\Operations\Jobs\RetryFulfillmentUnitJob;
use App\Domain\Operations\OperationLedger;
use App\Domain\Operations\RequestFingerprint;
use App\Domain\Payments\Models\WebhookDelivery;
use Illuminate\Support\Facades\DB;

/**
 * أفعال الإصلاح المضبوطة على القضيّة.
 *
 * ─── العيب الذي يصلحه ───
 *
 * الفرق كان يحمل `repairAction` **اسمًا** لا أكثر: «أعِد تشغيل الخطّاف».
 * والمشغّل يقرأها ثمّ يذهب إلى شاشةٍ أخرى ليبحث عن التسليم بنفسه — إن
 * عرف أين. فيُفتح تذكرةٌ إلى مهندس، وهو ما وُجدت القضيّة لتمنعه.
 *
 * ─── لماذا يمرّ الفعل بسجلّ العمليّات ───
 *
 * إعادة تشغيل خطّافٍ أثرٌ خارجيّ. والزرّ يُنقر مرّتين، والشبكة تنقطع بعد
 * وصول الطلب. فالخمول ليس تحسينًا هنا — هو الفرق بين إصلاحٍ ومضاعفةِ عطل.
 * ولا محرّك خمولٍ ثانٍ: `OperationLedger` قائمٌ ومختبَر.
 *
 * ─── لماذا الأفعال المتاحة تُحسب في الخادم ───
 *
 * «ما الذي أستطيع فعله بهذه القضيّة؟» سؤالٌ جوابُه من نوع الموضوع ورموز
 * السبب. وحسابُه في الواجهة يعني زرًّا يظهر لقضيّةٍ لا يصلح لها، فيُنقر
 * فيُردّ ٤٢٢ — ويُقرأ عطلًا لا منعًا.
 *
 * ─── وما لا يُدّعى ───
 *
 * `REVIEW_WITH_PROVIDER` و`FETCH_FROM_PROVIDER` ليسا فعلين آليّين: الأوّل
 * محادثةٌ مع مزوّد، والثاني يحتاج تكاملًا غير مربوط. يُعلنان **غير
 * قابلين للتنفيذ** ولا يُعرض لهما زرٌّ يكذب.
 */
final class CaseRemediation
{
    /** ما يُنفَّذ فعلًا — وما عداه يُعلَن غير متاح. */
    public const EXECUTABLE = [
        'REPLAY_WEBHOOK' => 'إعادة تشغيل الخطّاف',
        'RETRY_FULFILLMENT' => 'إعادة محاولة التنفيذ',
        'RECONCILE' => 'إعادة التسوية',
    ];

    /** أفعالٌ تُذكر في الفروق ولا تُنفَّذ آليًّا — تُعلَن ولا يُعرض لها زرّ. */
    public const ADVISORY = [
        'REVIEW_WITH_PROVIDER' => 'مراجعة مع المزوّد',
        'FETCH_FROM_PROVIDER' => 'جلب من المزوّد (تكامل غير مربوط)',
    ];

    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly OperationLedger $operations,
        private readonly Reconciliation $reconciliation,
    ) {}

    /**
     * الأفعال المتاحة لقضيّةٍ بعينها.
     *
     * @return list<array{action: string, label: string, executable: bool}>
     */
    public function availableFor(WorkCase $case): array
    {
        $out = [];
        $codes = (array) ($case->reason_codes ?? []);

        foreach ($codes as $code) {
            if (! str_starts_with((string) $code, 'REPAIR:')) {
                continue;
            }

            $action = substr((string) $code, 7);

            if (isset(self::EXECUTABLE[$action])) {
                $out[$action] = ['action' => $action, 'label' => self::EXECUTABLE[$action], 'executable' => true];
            } elseif (isset(self::ADVISORY[$action])) {
                $out[$action] = ['action' => $action, 'label' => self::ADVISORY[$action], 'executable' => false];
            }
        }

        // التسوية متاحةٌ دائمًا لقضايا الحوادث: قراءةٌ تُعيد فحص الشرط.
        if ($case->kind === 'INCIDENT' && ! isset($out['RECONCILE'])) {
            $out['RECONCILE'] = ['action' => 'RECONCILE', 'label' => self::EXECUTABLE['RECONCILE'], 'executable' => true];
        }

        return array_values($out);
    }

    /**
     * ينفّذ فعل إصلاحٍ مرّةً واحدة.
     *
     * @return array{outcome: string, operationId: ?int, duplicate: bool, detail: array<string,mixed>}
     *
     * @throws RemediationRefused
     */
    public function execute(
        WorkCase $case,
        string $action,
        string $idempotencyKey,
        ?AdminUser $actor = null,
        ?string $reason = null,
    ): array {
        if ($case->isTerminal()) {
            // المغلقة لا تُصلَح: الإصلاح بعد الشهادة يُبطل الشهادة.
            throw new RemediationRefused($action, 'القضيّة مغلقة — لا إصلاح بعد التحقّق.');
        }

        $available = collect($this->availableFor($case))->firstWhere('action', $action);

        if ($available === null) {
            throw new RemediationRefused($action, 'الفعل غير متاح لهذه القضيّة.');
        }

        if ($available['executable'] === false) {
            throw new RemediationRefused($action, 'هذا الفعل إرشاديّ ولا يُنفَّذ آليًّا: '.$available['label']);
        }

        $key = "case_action:{$case->id}:{$action}:{$idempotencyKey}";

        [$operation, $isNew] = $this->operations->reserve(
            $key, 'case_remediation', 'case', $case->id, $actor?->getKey(),
            RequestFingerprint::of(['caseId' => $case->id, 'action' => $action]),
        );

        if (! $isNew) {
            // نقرةٌ مكرّرة أو ردٌّ ضاع: يُعاد الجواب ولا يُنفَّذ شيء ثانيةً.
            return [
                'outcome' => (string) $operation->status,
                'operationId' => $operation->id,
                'duplicate' => true,
                'detail' => (array) ($operation->result ?? []),
            ];
        }

        $detail = match ($action) {
            'REPLAY_WEBHOOK' => $this->replayWebhook($case, $operation->id),
            'RETRY_FULFILLMENT' => $this->retryFulfillment($case, $operation->id),
            'RECONCILE' => $this->reconcile($case),
            default => throw new RemediationRefused($action, 'فعل غير معروف.'),
        };

        /*
         * الفعل المتزامن (التسوية) يُختم هنا. أمّا ما يُوضع في الطابور فيختمه
         * العامل: ختمُه هنا كان يجعل المهمّة تجد العمليّة «ناجحة» فتخرج بلا
         * عمل — ويقول السجلّ إنّ الخطّاف أُعيد والوحدة أُعيدت ولم يقع شيء.
         */
        $queued = isset($detail['queued']);

        if (! $queued) {
            $this->operations->succeed($operation, $detail);
        }

        DB::transaction(function () use ($case, $action, $actor, $reason, $detail, $operation) {
            CaseEvent::query()->create([
                'case_id' => $case->id,
                'actor_admin_user_id' => $actor?->getKey(),
                'action' => 'remediated',
                'note' => $reason,
                'evidence' => ['remediation' => $action, 'operationId' => $operation->id] + $detail,
                'created_at' => now(),
            ]);

            $this->audit->log(
                $actor, 'case.remediated', 'case', $case->id,
                null, ['action' => $action, 'operationId' => $operation->id], $reason, null,
            );
        });

        return [
            'outcome' => $queued ? (string) $operation->fresh()->status : 'SUCCEEDED',
            'operationId' => $operation->id,
            'duplicate' => false,
            'detail' => $detail,
        ];
    }

    /** @return array<string, mixed> */
    private function replayWebhook(WorkCase $case, int $operationId): array
    {
        $delivery = $case->subject_type === 'webhook_delivery'
            ? WebhookDelivery::query()->find($case->subject_id)
            : null;

        if ($delivery === null) {
            throw new RemediationRefused('REPLAY_WEBHOOK', 'لا تسليم مرتبط بهذه القضيّة.');
        }

        // المهمّة نفسها التي تستعملها شاشة المدفوعات — لا مسار ثانٍ.
        ReplayWebhookDeliveryJob::dispatch($operationId, $delivery->id);

        return ['queued' => 'ReplayWebhookDeliveryJob', 'deliveryId' => $delivery->id];
    }

    /** @return array<string, mixed> */
    private function retryFulfillment(WorkCase $case, int $operationId): array
    {
        if ($case->subject_type !== 'fulfillment_unit' || $case->subject_id === null) {
            throw new RemediationRefused('RETRY_FULFILLMENT', 'لا وحدة تنفيذٍ مرتبطة بهذه القضيّة.');
        }

        RetryFulfillmentUnitJob::dispatch($operationId, (int) $case->subject_id);

        return ['queued' => 'RetryFulfillmentUnitJob', 'unitId' => (int) $case->subject_id];
    }

    /**
     * إعادة الفحص: هل ما زال الشرط قائمًا؟
     *
     * قراءةٌ محضة لا تُصلح شيئًا — تُجيب سؤالًا يسبق كلّ قرار: «هل عولج؟».
     * وبدونها يُغلق المشغّل القضيّة على ظنّ.
     *
     * @return array<string, mixed>
     */
    private function reconcile(WorkCase $case): array
    {
        $result = $this->reconciliation->run();

        return [
            'clean' => $result['clean'],
            'counts' => $result['counts'],
            'settlementCoverage' => $result['settlement']['coverage'],
        ];
    }
}
