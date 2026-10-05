<?php

namespace App\Domain\Operations\Reconcilers;

use App\Domain\Assets\AssetSourceResolver;
use App\Domain\Assets\Exceptions\SourceAccessDenied;
use App\Domain\Assets\Exceptions\SourceNotConfigured;
use App\Domain\Assets\Exceptions\SourceUnavailable;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Operations\Contracts\OperationReconciler;
use App\Domain\Operations\Models\OperationRecord;
use Illuminate\Support\Facades\DB;

/**
 * هل تحقّقنا من المصدر فعلًا قبل أن يموت العامل؟
 *
 * ─── لماذا السؤال يمرّ بـ`inspect` لا بـ`refresh` ───
 *
 * `refresh` **يُحدث أثرًا**: يستورد، ويكتب مراجعة، ويسجّل مزامنة. واستعمالُه
 * للسؤال يعني أنّ كلّ تعافٍ ينفّذ العمليّة من جديد — أي أنّ نظام منع
 * التنفيذ المزدوج يصير هو نفسه سببَه.
 *
 * `inspect` قراءةٌ خفيفة عرّفها عقد المصدر لهذا الغرض بالضبط: هل المعرّف
 * يشير إلى شيء، وما وصفه المعلَن؟ لا تنزيل ولا استيراد.
 *
 * ─── ترتيب الأدلّة ───
 *
 * ١) سطر مزامنةٍ كُتب بعد بدء العمليّة ⇦ الأثر وقع محلّيًّا ويقينًا.
 * ٢) وإلّا يُسأل المزوّد: إن أجاب فالمصدر حيّ ولم نسجّل شيئًا ⇦ غائب.
 * ٣) وإن لم يُجب ⇦ مجهول، ولا تُعاد المحاولة.
 *
 * عطلُ المزوّد لا يُقرأ «غيابًا»: الخلط بينهما هو ما يجعل إعادةً عمياء
 * تُستورد مرّتين حين يعود المزوّد.
 */
class AssetVerifyReconciler implements OperationReconciler
{
    public function __construct(private readonly AssetSourceResolver $resolver) {}

    public function kind(): string
    {
        return 'asset_verify';
    }

    public function reconcile(OperationRecord $record): array
    {
        $source = AssetSource::query()->find($record->subject_id);

        if ($source === null) {
            return ['state' => 'ABSENT'];
        }

        $startedAt = $record->started_at;

        if ($startedAt !== null) {
            $sync = DB::table('asset_source_syncs')
                ->where('asset_source_id', $source->id)
                ->where('created_at', '>=', $startedAt)
                ->orderByDesc('id')
                ->first();

            if ($sync !== null) {
                return [
                    'state' => 'CONFIRMED',
                    'reference' => (string) $sync->id,
                    'result' => [
                        'sourceId' => $source->id,
                        'syncState' => $source->sync_state,
                        'lastError' => $source->last_error,
                        'deliverable' => $source->isDeliverable(),
                        'reconciled' => true,
                    ],
                ];
            }
        }

        try {
            $adapter = $this->resolver->for((string) $source->provider);

            // قراءةٌ فقط. لا تستورد ولا تكتب مراجعة ولا تسجّل مزامنة.
            $adapter->inspect((string) $source->external_id);

            /*
             * المزوّد حيّ والمعرّف قائم، ولا سطر مزامنةٍ عندنا: لم نُنجز
             * شيئًا قبل الموت. الإعادة آمنة.
             */
            return ['state' => 'ABSENT'];
        } catch (\InvalidArgumentException) {
            // مزوّدٌ بلا محوّل: لا سبيل إلى السؤال، فلا يُدّعى جواب.
            return ['state' => 'UNKNOWN'];
        } catch (SourceNotConfigured|SourceAccessDenied) {
            /*
             * عجزٌ عن السؤال لا جوابٌ عن الأثر. إعلانُه «غيابًا» كان سيسمح
             * بإعادةٍ قد تكون تنفيذًا ثانيًا.
             */
            return ['state' => 'UNKNOWN'];
        } catch (SourceUnavailable) {
            return ['state' => 'UNKNOWN'];
        }
    }
}
