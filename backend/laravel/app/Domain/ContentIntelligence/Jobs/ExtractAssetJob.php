<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Jobs;

use App\Domain\Assets\Models\Asset;
use App\Domain\ContentIntelligence\AssetExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * استخراج أصلٍ واحد في الطابور — العمل الثقيل خارج مسار الطلب (البند ٤٥).
 *
 * مهمّةٌ لكلّ أصلٍ لا مهمّةٌ للكتالوج كلّه: فشلُ ملفٍّ واحد لا يُسقط الدفعة،
 * وإعادةُ المحاولة تُعيد ملفًّا لا سبعةً وعشرين، والتوازي مجّانيّ.
 *
 * وخاملةٌ بطبعها: البصمة هي المفتاح، فمهمّةٌ نُفِّذت مرّتين تكتب الصفّ نفسه
 * — ولذلك لا مفتاح خمولٍ في دفتر العمليّات هنا: لا أثر خارجيّ يقع.
 */
class ExtractAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** أقلّ بكثيرٍ من `retry_after` (٩٦٠ ثانية) وإلّا نُفّذت المهمّة مرّتين. */
    public int $timeout = 120;

    /** تخزينٌ يُعاد تشغيله لا يعود في عشر ثوانٍ: التباعد يعطيه فرصة. */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function __construct(
        public readonly int $assetId,
        public readonly bool $force = false,
    ) {}

    public function handle(AssetExtractionService $service): void
    {
        $asset = Asset::query()->find($this->assetId);

        if ($asset === null) {
            /* حُذف بين الإرسال والتنفيذ — ليس عطلًا، ولا صفَّ يُكتب لأصلٍ زال. */
            Log::info('content_intelligence.extract.asset_gone', ['asset_id' => $this->assetId]);

            return;
        }

        $attempt = $service->extract($asset, $this->force);

        /*
         * يُسجَّل الفشل وحده: سبعةٌ وعشرون سطرًا ناجحًا في كلّ تشغيلٍ تُغرق
         * السجلّ فيُقرأ أقلّ — والحصيلة تُقرأ من الجدول لا من السجلّ.
         */
        if ($attempt->row->status === 'failed') {
            Log::warning('content_intelligence.extract.failed', [
                'asset_id' => $this->assetId,
                'extractor' => $attempt->row->extractor,
                'error_code' => $attempt->row->error_code,
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        /* آخر أثرٍ لأصلٍ لم يُستخرَج: لا صفَّ يُكتب حين تسقط المهمّة نفسها. */
        Log::error('content_intelligence.extract.job_failed', [
            'asset_id' => $this->assetId,
            'error' => $e->getMessage(),
        ]);
    }
}
