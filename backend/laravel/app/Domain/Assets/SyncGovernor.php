<?php

namespace App\Domain\Assets;

use App\Domain\Assets\Exceptions\SourceNotConfigured;
use App\Domain\Assets\Exceptions\SourceUnavailable;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Assets\Models\AssetSourceSync;
use Illuminate\Support\Facades\DB;

/**
 * حوكمة المزامنة (§١٦) — الجهة الوحيدة التي تكتب `sync_state`.
 *
 * الحالة **مشتقّة من فحصٍ فعليّ** لا مُعلنة يدويًّا. ولذلك يميّز هذا الصنف
 * بين ثلاث حالات فشلٍ كانت ستنهار إلى واحدة لو استُعمل `catch` واحد:
 *
 *   الملفّ غير موجود   → SOURCE_MISSING   (حُذف أو نُقل خارج نطاقنا)
 *   الاعتماد لا يقرؤه → ACCESS_REVOKED   (الملفّ هناك، والإذن ذهب)
 *   تعذّر البلوغ       → SYNC_FAILED      (شبكة أو مزوّد — يُعاد لاحقًا)
 *
 * الفرق عمليّ لا تصنيفيّ: الأوّل يوجب استبدال المصدر، والثاني يوجب إصلاح
 * الاعتماد، والثالث يوجب الانتظار وحده. طيُّها في «فشل» يُنتج لوحةً تقول
 * «أصلح شيئًا ما».
 */
class SyncGovernor
{
    public function __construct(private readonly AssetSourceResolver $resolver) {}

    /**
     * يفحص المصدر ويُحدّث حالته. لا يجلب بايتات — الفحص رخيص والجلب ليس.
     *
     * @param  string  $trigger  manual|scheduled|purchase_check|webhook
     */
    public function refresh(AssetSource $source, string $trigger = 'scheduled'): AssetSource
    {
        $startedAt = hrtime(true);
        $adapter = $this->resolver->for($source->provider);
        $from = $source->imported_revision;

        try {
            /*
             * الإذن يُفحص أوّلًا. لو بدأنا بالبيانات الوصفيّة لَعاد مزوّدٌ
             * مسحوبُ الصلاحية بـ«غير موجود»، فنُسجّل SOURCE_MISSING ونطلب
             * استبدال ملفٍّ سليم لم يُمسّ.
             */
            if (! $adapter->verifyAccess($source->external_id)) {
                return $this->settle($source, 'ACCESS_REVOKED', $trigger, $from, null,
                    'الاعتماد لا يقرأ هذا الملفّ. أصلح الإذن — الملفّ نفسه قد يكون سليمًا.', $startedAt);
            }

            $meta = $adapter->inspect($source->external_id);

            if (! $meta->exists) {
                return $this->settle($source, 'SOURCE_MISSING', $trigger, $from, null,
                    'الملفّ لم يعد عند المزوّد بهذا المعرّف.', $startedAt);
            }

            $source->fill(array_filter([
                'file_name' => $meta->fileName,
                'mime_type' => $meta->mimeType,
                'size_bytes' => $meta->sizeBytes,
                'source_revision' => $meta->revision,
                'source_modified_at' => $meta->modifiedAt,
                'external_parent_id' => $meta->parentId,
            ], static fn ($v) => $v !== null));

            $changed = $meta->revision !== null && $meta->revision !== $source->imported_revision;

            if ($changed && $source->version_frozen) {
                return $this->settle($source, 'SOURCE_CHANGED', $trigger, $from, $meta->revision,
                    'المصدر تغيّر والنسخة مجمّدة — يُسلَّم المشترى لا الأحدث.', $startedAt, 'SKIPPED_FROZEN');
            }

            if ($changed) {
                return $this->settle($source, 'SOURCE_CHANGED', $trigger, $from, $meta->revision,
                    'المصدر تغيّر ويحتاج مزامنة.', $startedAt);
            }

            return $this->settle($source, 'CURRENT', $trigger, $from, $meta->revision, null, $startedAt, 'NO_CHANGE');
        } catch (SourceNotConfigured $e) {
            // غياب الإعداد ليس عطلًا في الملفّ — لا يُسجَّل SOURCE_MISSING.
            return $this->settle($source, 'SYNC_FAILED', $trigger, $from, null, $e->getMessage(), $startedAt);
        } catch (SourceUnavailable $e) {
            return $this->settle($source, 'SYNC_FAILED', $trigger, $from, null, $e->getMessage(), $startedAt);
        }
    }

    private function settle(
        AssetSource $source,
        string $state,
        string $trigger,
        ?string $from,
        ?string $to,
        ?string $message,
        int $startedAt,
        ?string $outcome = null,
    ): AssetSource {
        $failed = in_array($state, AssetSource::BLOCKING_STATES, true);

        return DB::transaction(function () use ($source, $state, $trigger, $from, $to, $message, $startedAt, $outcome, $failed) {
            // الحالة وسجلُّها يلتزمان معًا، وتحت قفل الصفّ: فحصان متزامنان لا يتداخلان.
            $locked = AssetSource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $source->failed_attempts = $locked->failed_attempts;

            $source->sync_state = $state;
            $source->last_verified_at = now();
            $source->last_error = $failed || $state === 'SOURCE_CHANGED' ? $message : null;
            $source->failed_attempts = $failed ? $source->failed_attempts + 1 : 0;

            /*
             * `imported_revision` لا يتحرّك هنا. الفحص يقرأ ولا يجلب، ورفعُها بلا
             * جلبٍ يجعل CURRENT تكذب: مراجعتان متطابقتان وبايتاتٌ قديمة.
             * يرفعها الاستيراد وحده.
             */
            if ($state === 'CURRENT') {
                $source->last_sync_at = now();
            }

            $source->save();

            AssetSourceSync::create([
                'asset_source_id' => $source->id,
                'trigger' => $trigger,
                'outcome' => $outcome ?? ($failed ? 'FAILED' : 'SUCCESS'),
                'from_revision' => $from,
                'to_revision' => $to,
                'message' => $message,
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ]);

            return $source;
        });
    }
}
