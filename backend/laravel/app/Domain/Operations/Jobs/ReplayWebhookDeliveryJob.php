<?php

namespace App\Domain\Operations\Jobs;

use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Operations\OperationLedger;
use App\Domain\Payments\Models\WebhookDelivery;
use App\Domain\Payments\WebhookIntake;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * إعادة تشغيل تسليمٍ محفوظ — خارج دورة الطلب.
 *
 * ─── لماذا هذا آمن ───
 *
 * الأمان ليس من هذه المهمّة بل من `OrderFinalizer`: مفتاحه
 * `provider:chargeId` فريدٌ في قاعدة البيانات، فإعادةُ حدثٍ عولج سلفًا لا
 * تُنشئ دفعةً ثانية ولا استحقاقًا ثانيًا — تُعيد «مكرّر» وتنتهي.
 *
 * ولهذا يُسمح بإعادة تشغيل حدثٍ **ناجحٍ** أيضًا: هي الطريقة الوحيدة للتحقّق
 * من أنّ الأثر ما يزال قائمًا بعد إصلاحٍ يدويّ، وهي بلا خطر.
 *
 * ─── لماذا لا يُعاد بناء الحدث من الحمولة بالتحليل ───
 *
 * الحمولة تُحفظ كما وصلت، والحقول المعنويّة (الرسوم، الطلب، النجاح) تُحفظ
 * أعمدةً مستقلّة. إعادةُ تحليل الحمولة بمحلّل اليوم تعني أنّ حدثًا وصل قبل
 * سنة يُفهم بقواعد تغيّرت — فيُعالَج غيرُ الذي وصل.
 */
class ReplayWebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function backoff(): array
    {
        return [10 + random_int(0, 5), 60 + random_int(0, 30), 300 + random_int(0, 120)];
    }

    public function __construct(
        public readonly int $operationId,
        public readonly int $deliveryId,
    ) {}

    public function handle(OperationLedger $ledger, WebhookIntake $intake): void
    {
        $record = OperationRecord::query()->find($this->operationId);

        if ($record === null || $record->isFinished()) {
            return;
        }

        if ($record->isUncertain()) {
            return; // ينتظر التوفيق لا الإعادة
        }

        $owner = 'job:'.Str::uuid();

        if ($ledger->acquire($record, $owner) === null) {
            return;
        }

        $ledger->markProcessing($record);

        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null) {
            $ledger->fail($record, 'NOT_FOUND', 'التسليم لم يعد موجودًا.');

            return;
        }

        try {
            $processed = $intake->process($delivery, $delivery->toEvent());
            $delivery->increment('replay_count');

            $ledger->succeed($record, [
                'deliveryId' => $delivery->id,
                'status' => $processed->status,
                // «مكرّر» هنا نتيجةٌ سليمة لا فشل: الأثر كان قائمًا سلفًا.
                'applied' => $processed->getAttribute('applied') === true,
            ], (string) $delivery->id);
        } catch (Throwable $e) {
            Log::error('payments.webhook.replay_failed', [
                'operationId' => $record->id,
                'deliveryId' => $this->deliveryId,
                'error' => $e->getMessage(),
            ]);

            $ledger->fail($record, 'TEMPORARY', $e->getMessage());

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $record = OperationRecord::query()->find($this->operationId);

        if ($record !== null && ! $record->isFinished() && ! $record->isUncertain()) {
            app(OperationLedger::class)->fail($record, 'TEMPORARY', $e->getMessage());
        }
    }
}
