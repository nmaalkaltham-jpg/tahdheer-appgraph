<?php

namespace App\Domain\Operations\Reconcilers;

use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Operations\Contracts\OperationReconciler;
use App\Domain\Operations\Models\OperationRecord;

/**
 * هل غيّرت إعادةُ المحاولة حال وحدة التنفيذ؟
 *
 * ─── لماذا المقارنة بزمن البدء ───
 *
 * الوحدة قد تكون منجزةً من محاولةٍ سابقة تمامًا، فحالتُها وحدها لا تدلّ.
 * الدليل هو أن تكون قد **تغيّرت بعد** أن بدأت هذه العمليّة: عندئذٍ الأثر
 * أثرُنا لا أثرُ غيرنا.
 *
 * ─── ولماذا `ABSENT` لا تعني الفشل ───
 *
 * تعني أنّ شيئًا لم يقع، فإعادة المحاولة آمنة. وهذا هو كلّ ما يُطلب من
 * التوفيق: أن يقرّر هل الإعادة آمنة، لا أن يُعيد بنفسه.
 */
class FulfillmentRetryReconciler implements OperationReconciler
{
    public function kind(): string
    {
        return 'fulfillment_retry';
    }

    public function reconcile(OperationRecord $record): array
    {
        $unit = FulfillmentUnit::query()->find($record->subject_id);

        if ($unit === null) {
            // اختفت الوحدة: لا أثر لنا فيها، ولا إعادةَ تنفع.
            return ['state' => 'ABSENT'];
        }

        $startedAt = $record->started_at;

        if ($startedAt === null || $unit->updated_at === null || $unit->updated_at->lt($startedAt)) {
            return ['state' => 'ABSENT'];
        }

        return [
            'state' => 'CONFIRMED',
            'reference' => (string) $unit->id,
            'result' => [
                'unitId' => $unit->id,
                'status' => $unit->status,
                'reason' => $unit->pending_reason ?? $unit->last_error,
                'reconciled' => true,
            ],
        ];
    }
}
