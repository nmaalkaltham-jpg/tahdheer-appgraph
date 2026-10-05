<?php

namespace App\Domain\Operations;

use App\Domain\Operations\Models\OperationRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * يستعيد العمليّات التي فقدت عاملها — بالسؤال لا بالإعادة.
 *
 * ─── السيناريو الذي وُجد له ───
 *
 *   ١) العامل يحجز ويستحوذ على الإيجار.
 *   ٢) ينادي المزوّد. المزوّد **ينجح**.
 *   ٣) العامل يُقتل قبل أن يكتب النتيجة محلّيًّا.
 *
 * الأثر واقع والسجلّ لا يعرف. وثلاثة تصرّفاتٍ ممكنة هنا، اثنان منها خطأ:
 * إعادةُ الفعل تُنفّذه مرّتين، وإعلانُه فشلًا يترك أثرًا يتيمًا لا يُحاسب
 * عليه أحد. الصحيح أن **يُسأل المزوّد** ثمّ يُوفَّق السجلّ مع الواقع.
 *
 * ─── لماذا يُستحوذ على الإيجار قبل التوفيق ───
 *
 * التعافي نفسه يُشغَّل مجدولًا وقد يُشغَّل يدويًّا في اللحظة نفسها. وموفّقان
 * على السجلّ الواحد يسألان مرّتين ويكتبان نتيجتين. الإيجار يجعل واحدًا
 * يفوز والآخر يمضي.
 *
 * ─── لماذا لا يُعاد التنفيذ من هنا أبدًا ───
 *
 * التعافي يقرّر **هل الإعادة آمنة** ولا يُعيد. فصلُ القرار عن الفعل هو ما
 * يجعل هذا الملفّ قابلًا للتشغيل كلّ دقيقة بلا خوف.
 */
class StaleOperationRecovery
{
    public function __construct(
        private readonly OperationLedger $ledger,
        private readonly ReconcilerRegistry $reconcilers,
    ) {}

    /**
     * @return array{scanned: int, confirmed: int, absent: int, unknown: int, skipped: int}
     */
    public function run(int $limit = 100): array
    {
        $owner = 'recovery:'.Str::uuid();
        $tally = ['scanned' => 0, 'confirmed' => 0, 'absent' => 0, 'unknown' => 0, 'skipped' => 0];

        foreach ($this->ledger->stale($limit) as $record) {
            $tally['scanned']++;

            $reconciler = $this->reconcilers->for((string) $record->kind);

            if ($reconciler === null) {
                // نوعٌ بلا موفّق: يُترك مجهولًا ويُعلَن. لا يُخمَّن له جواب.
                $tally['skipped']++;

                continue;
            }

            if ($this->ledger->acquire($record, $owner, 60) === null) {
                $tally['skipped']++;

                continue;
            }

            /*
             * «قيد التنفيذ» بإيجارٍ منتهٍ تعني عاملًا مات. تُنقل إلى
             * «مجهولة» أوّلًا فتُقرأ حالتُها صادقةً حتّى لو فشل السؤال بعدها.
             */
            if ($record->status === 'PROCESSING') {
                $record = $this->ledger->markUncertain(
                    $record,
                    'انتهى إيجار العامل قبل تسجيل النتيجة — النتيجة مجهولة حتّى التوفيق.',
                );
            }

            try {
                $verdict = $reconciler->reconcile($record);
            } catch (Throwable $e) {
                /*
                 * فشلُ السؤال ليس جوابًا. تبقى مجهولةً ولا تُعاد، ويُسجَّل
                 * السبب ليُقرأ حين يُسأل «لماذا لم تُحسم؟».
                 */
                Log::warning('operations.reconcile.failed', [
                    'operationId' => $record->id,
                    'kind' => $record->kind,
                    'error' => $e->getMessage(),
                ]);

                $this->ledger->recordProviderState($record, 'UNKNOWN');
                $tally['unknown']++;

                continue;
            }

            match ($verdict['state']) {
                'CONFIRMED' => $this->confirm($record, $verdict, $tally),
                'ABSENT' => $this->absent($record, $tally),
                default => $this->unknown($record, $tally),
            };
        }

        return $tally;
    }

    /** @param array{state: string, result?: array<string,mixed>, reference?: string} $verdict */
    private function confirm(OperationRecord $record, array $verdict, array &$tally): void
    {
        $this->ledger->recordProviderState($record, 'CONFIRMED');
        $this->ledger->succeed($record, $verdict['result'] ?? ['reconciled' => true], $verdict['reference'] ?? null);
        $tally['confirmed']++;
    }

    private function absent(OperationRecord $record, array &$tally): void
    {
        $this->ledger->recordProviderState($record, 'ABSENT');

        /*
         * لم يقع شيء ⇦ الإعادة آمنة. تُسجَّل `TEMPORARY` لأنّها الصنف الوحيد
         * الذي يسمح بإعادةٍ لاحقة، ولا تُعاد من هنا: القرار غير الفعل.
         */
        $this->ledger->fail(
            $record,
            'TEMPORARY',
            'توفيقٌ بعد انقطاع: لم يقع أثرٌ عند المزوّد — إعادة المحاولة آمنة.',
        );
        $tally['absent']++;
    }

    private function unknown(OperationRecord $record, array &$tally): void
    {
        $this->ledger->recordProviderState($record, 'UNKNOWN');
        $tally['unknown']++;
    }
}
