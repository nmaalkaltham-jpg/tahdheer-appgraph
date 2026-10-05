<?php

namespace App\Domain\Operations\Reconcilers;

use App\Domain\Operations\Contracts\OperationReconciler;
use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\WebhookDelivery;

/**
 * هل أُعيد تشغيل التسليم؟
 *
 * الدليل محلّيّ ويقينيّ كدفتر المخزون: `OrderFinalizer` يكتب مفتاح
 * `provider:chargeId` في المعاملة نفسها التي تُموّل الطلب، والتسليم يُعلَّم
 * «معالَجًا» بطابعٍ زمنيّ. فإن وُجد أحدهما بعد الحجز فقد وقع الأثر؛ وإلّا
 * فلم يقع — والإعادة آمنة لأنّ المُثبِّت خاملُ التكرار بمفتاحه.
 */
class WebhookReplayReconciler implements OperationReconciler
{
    public function kind(): string
    {
        return 'webhook_replay';
    }

    public function reconcile(OperationRecord $record): array
    {
        $delivery = $record->subject_type === 'webhook_delivery'
            ? WebhookDelivery::query()->find($record->subject_id)
            : null;

        if ($delivery === null) {
            return ['state' => 'UNKNOWN'];
        }

        $event = $delivery->toEvent();
        $paymentKey = "{$event->provider}:{$event->chargeId}";

        $captured = $event->chargeId !== '' && Payment::query()->where('idempotency_key', $paymentKey)->exists();
        $processedAfterReserve = $delivery->status === 'PROCESSED'
            && $delivery->processed_at !== null
            && $delivery->processed_at->greaterThanOrEqualTo($record->created_at);

        if ($captured || $processedAfterReserve) {
            return [
                'state' => 'CONFIRMED',
                'reference' => (string) $delivery->id,
                'result' => ['deliveryId' => $delivery->id, 'status' => $delivery->status, 'applied' => $captured, 'reconciled' => true],
            ];
        }

        // لا دفعة بالمفتاح ولا معالجة بعد الحجز: لم يقع شيء — الإعادة آمنة.
        return ['state' => 'ABSENT'];
    }
}
