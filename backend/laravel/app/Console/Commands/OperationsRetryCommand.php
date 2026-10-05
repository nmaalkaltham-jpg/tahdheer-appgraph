<?php

namespace App\Console\Commands;

use App\Domain\Operations\Jobs\ReplayWebhookDeliveryJob;
use App\Domain\Operations\Jobs\RetryFulfillmentUnitJob;
use App\Domain\Operations\Jobs\VerifyAssetSourceJob;
use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Operations\OperationLedger;
use Illuminate\Console\Command;

/**
 * يُعيد ما قرّر التوفيق أنّ إعادته آمنة.
 *
 * `operations:recover` يقرّر ولا يُعيد: العمليّة التي لم يقع أثرها تُعلَّم
 * `FAILED/TEMPORARY`، ثمّ لا يُعيدها شيء إلّا نقرةُ مشغّل. هذا الأمر هو
 * الفعل الذي يقابل ذلك القرار — بعمليّةٍ **جديدة** بمفتاحٍ جديد (النهائيّ
 * نهائيّ)، ولا يُعيد موضوعًا له عمليّةٌ حيّة أصلًا.
 */
class OperationsRetryCommand extends Command
{
    protected $signature = 'operations:retry
        {--kind= : fulfillment_retry|webhook_replay|asset_verify — كلّها إن غاب}
        {--limit=50 : أقصى عددٍ يُعاد في تشغيلٍ واحد}
        {--dry-run : اعرض ما سيُعاد بلا حجزٍ ولا إرسال}';

    protected $description = 'يُعيد العمليّات الفاشلة مؤقّتًا (توفيقٌ قال: لم يقع أثر) بعمليّاتٍ جديدة';

    private const KINDS = ['fulfillment_retry', 'webhook_replay', 'asset_verify'];

    public function handle(OperationLedger $ledger): int
    {
        $kinds = $this->option('kind') !== null ? [(string) $this->option('kind')] : self::KINDS;
        $dry = (bool) $this->option('dry-run');

        if (array_diff($kinds, self::KINDS) !== []) {
            $this->components->error('نوعٌ غير معروف. المتاح: '.implode(' · ', self::KINDS));

            return self::FAILURE;
        }

        $candidates = OperationRecord::query()
            ->whereIn('kind', $kinds)
            ->where('status', 'FAILED')
            ->where('failure_class', 'TEMPORARY')
            ->whereNotNull('subject_id')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $tally = ['candidates' => $candidates->count(), 'queued' => 0, 'skipped_live' => 0];

        foreach ($candidates as $record) {
            $live = OperationRecord::query()
                ->where('kind', $record->kind)
                ->where('subject_type', $record->subject_type)
                ->where('subject_id', $record->subject_id)
                ->whereIn('status', ['RESERVED', 'PROCESSING', 'UNCERTAIN'])
                ->exists();

            if ($live) {
                // موضوعٌ له عمليّةٌ حيّة لا يُعاد فوقها — إعادتان لأثرٍ واحد.
                $tally['skipped_live']++;

                continue;
            }

            $key = "{$record->idempotency_key}:retry:{$record->id}";
            $this->line(($dry ? '[dry-run] ' : '')."{$record->kind} #{$record->id} → {$record->subject_type}:{$record->subject_id}");

            if ($dry) {
                $tally['queued']++;

                continue;
            }

            [$operation, $isNew] = $ledger->reserve($key, (string) $record->kind, $record->subject_type, (int) $record->subject_id);

            if (! $isNew) {
                $tally['skipped_live']++;

                continue;
            }

            match ($record->kind) {
                'fulfillment_retry' => RetryFulfillmentUnitJob::dispatch($operation->id, (int) $record->subject_id),
                'webhook_replay' => ReplayWebhookDeliveryJob::dispatch($operation->id, (int) $record->subject_id),
                'asset_verify' => VerifyAssetSourceJob::dispatch($operation->id, (int) $record->subject_id),
            };

            $tally['queued']++;
        }

        $this->line(json_encode($tally + ['dryRun' => $dry], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
