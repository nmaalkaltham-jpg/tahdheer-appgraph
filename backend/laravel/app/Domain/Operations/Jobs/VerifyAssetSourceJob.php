<?php

namespace App\Domain\Operations\Jobs;

use App\Domain\Assets\Exceptions\SourceAccessDenied;
use App\Domain\Assets\Exceptions\SourceNotConfigured;
use App\Domain\Assets\Exceptions\SourceOutcomeUnknown;
use App\Domain\Assets\Exceptions\SourceUnavailable;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Assets\SyncGovernor;
use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Operations\OperationLedger;
use App\Domain\Outbox\OutboxWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * إعادة التحقّق من مصدر أصل — خارج دورة الطلب (§١٦).
 *
 * كان يُنفَّذ داخل وحدة التحكّم، أي أنّ نقرةَ مشغّلٍ تفتح نداءً إلى Google
 * Drive وتُبقي طلب HTTP معلّقًا حتّى يجيب طرفٌ ثالث. وإن تأخّر انتهت مهلة
 * الوكيل، فأعاد المشغّل النقر، فانطلق نداءان متوازيان على المصدر نفسه.
 *
 * ─── تسلسل التحقّق على المصدر الواحد ───
 *
 * المفتاح `asset_verify:{source}` بلا طابعٍ زمنيّ عمدًا: طلبان متزامنان
 * على المصدر نفسه يتصادمان على مفتاحٍ واحد فينهار أحدهما إلى الآخر. هذا هو
 * «التجميع» الذي يطلبه العقد — لا صفٌّ من التحقّقات المتطابقة.
 *
 * ─── التصنيف يحفظ الدلالة ───
 *
 * `SyncGovernor` هو من يفرّق بين «الملفّ ذهب» و«الإذن ذهب» و«رمزُنا انتهى».
 * هذه المهمّة لا تعيد تفسير شيء — تنقل التصنيف كما هو. وتحديدًا: ٤٠١ لا
 * تُخفَّض أبدًا إلى «ملفّ مفقود».
 */
class VerifyAssetSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 150; // > مهلة تنزيل Drive (١٢٠ ث) وإلّا قُتلت المهمّة وسط استيرادٍ سليم

    public function backoff(): array
    {
        return [10 + random_int(0, 5), 60 + random_int(0, 30), 300 + random_int(0, 120)];
    }

    public function __construct(
        public readonly int $operationId,
        public readonly int $sourceId,
    ) {}

    public function handle(OperationLedger $ledger, SyncGovernor $sync, OutboxWriter $outbox): void
    {
        $record = OperationRecord::query()->find($this->operationId);

        if ($record === null || $record->isFinished()) {
            return;
        }

        // مجهولةُ النتيجة تُترك لـ`operations:recover` — يسأل قبل أن يُعاد شيء.
        if ($record->isUncertain()) {
            Log::info('asset_source.verify.deferred_to_reconciliation', ['operationId' => $record->id]);

            return;
        }

        $owner = 'job:'.Str::uuid();

        if ($ledger->acquire($record, $owner) === null) {
            Log::info('asset_source.verify.lease_held', ['operationId' => $record->id]);

            return;
        }

        $ledger->markProcessing($record);

        $source = AssetSource::query()->find($this->sourceId);

        if ($source === null) {
            $ledger->fail($record, 'NOT_FOUND', 'مصدر الأصل لم يعد موجودًا.');

            return;
        }

        try {
            /*
             * النداء الخارجيّ **خارج** أيّ معاملة. `SyncGovernor` يكتب حالته
             * في معاملاتٍ قصيرة خاصّة به.
             */
            $refreshed = $sync->refresh($source, 'manual');

            DB::transaction(function () use ($outbox, $refreshed, $record) {
                $outbox->write(
                    aggregateType: 'asset_source',
                    aggregateId: (string) $refreshed->id,
                    eventType: $refreshed->isDeliverable() ? 'asset_source.verified' : 'asset_source.failed',
                    payload: [
                        'sourceId' => $refreshed->id,
                        'provider' => $refreshed->provider,
                        'syncState' => $refreshed->sync_state,
                        'lastError' => $refreshed->last_error,
                    ],
                    idempotencyKey: "asset_source:{$refreshed->id}:verify:{$record->id}",
                    correlationId: $record->correlation_id,
                );
            });

            $ledger->succeed($record, [
                'sourceId' => $refreshed->id,
                'syncState' => $refreshed->sync_state,
                'lastError' => $refreshed->last_error,
                'deliverable' => $refreshed->isDeliverable(),
            ]);
        } catch (SourceOutcomeUnknown $e) {
            // ضاع الجواب لا الطلب: تُترك مجهولةً ولا تُعاد قبل التوفيق.
            $ledger->markUncertain($record, $e->getMessage(), $e->reference);
        } catch (ConnectionException $e) {
            $ledger->markUncertain($record, 'انقطع الاتّصال قبل معرفة النتيجة: '.$e->getMessage());
        } catch (SourceNotConfigured $e) {
            /*
             * غيابُ اعتمادٍ عندنا. **لا يُقرأ غيابَ ملفّ** — وهي الحالة التي
             * كانت ستطلب استبدال مصادر سليمة لأنّ أحدًا لم يضبط رمزًا.
             */
            $ledger->fail($record, 'AUTHENTICATION', $e->getMessage());
        } catch (SourceAccessDenied $e) {
            $ledger->fail($record, 'AUTHORIZATION', $e->getMessage());
        } catch (SourceUnavailable $e) {
            $ledger->fail($record, 'PROVIDER_UNAVAILABLE', $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            Log::error('asset_source.verify.failed', [
                'operationId' => $record->id,
                'sourceId' => $this->sourceId,
                'correlationId' => $record->correlation_id,
                'error' => $e->getMessage(),
            ]);

            $ledger->fail($record, 'TEMPORARY', $e->getMessage());
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $record = OperationRecord::query()->find($this->operationId);

        // المجهولة تبقى مجهولة: قلبُها فشلًا يسمح بإعادةٍ لم تُسأل بعد.
        if ($record !== null && ! $record->isFinished() && ! $record->isUncertain()) {
            app(OperationLedger::class)->fail($record, 'TEMPORARY', $e->getMessage());
        }
    }
}
