<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Assets\Models\Asset;
use App\Domain\ContentIntelligence\AssetExtractionService;
use App\Domain\ContentIntelligence\Jobs\ExtractAssetJob;
use Illuminate\Console\Command;

/**
 * تشغيل الاستخراج على الأصول — إرسالٌ إلى الطابور افتراضًا.
 *
 * الافتراض إرسالٌ لا تنفيذ (البند ٤٥): القراءة وفكّ الضغط والتحليل عملٌ
 * ثقيل، والأمر قد يُجدوَل. و`--sync` للتشخيص وللاختبار وحدهما — تُنفَّذ في
 * هذه العمليّة فتُرى الحصيلة كاملةً في المخرَج.
 *
 * ولا شيء هنا يعرض النصّ المستخرَج لزائر: الطبقة داخليّة (البندان ٥٨ و١٢٦).
 *
 * ─── ولماذا يسكن هنا لا في نطاقه ───
 *
 * كُتب أوّلًا في `app/Domain/ContentIntelligence/Console/`، ولارافيل لا
 * يكتشف الأوامر إلّا في `app/Console/Commands` — فكان الصنف موجودًا
 * و`php artisan content:extract` يردّ «لا أوامر في النطاق content».
 * أي خطُّ استخراجٍ كامل لا سبيل إلى تشغيله. وكلُّ أوامر المستودع
 * الأخرى هنا، فالاتّباع أسلم من تسجيلٍ يدويٍّ يُنسى.
 */
class AssetsExtractCommand extends Command
{
    protected $signature = 'content:extract
        {--asset=* : معرّفات أصولٍ بعينها (الافتراض: الكلّ)}
        {--mime= : اقتصر على نوعٍ واحد}
        {--limit=0 : أقصى عدد يُعالَج (٠ = الكلّ)}
        {--force : أعد الاستخراج رغم وجود صفٍّ نهائيّ لنفس البصمة والنسخة}
        {--sync : نفّذ في هذه العمليّة بدل الطابور}
        {--json : أخرج الحصيلة JSON}';

    protected $description = 'يستخرج نصّ الأصول الأصليّ (SVG · نصّ · PDF · pptx · docx · xlsx) ويسجّل ما عجز عنه باسمه';

    public function handle(AssetExtractionService $service): int
    {
        $query = Asset::query()->orderBy('id');

        if ($ids = array_filter(array_map('intval', (array) $this->option('asset')))) {
            $query->whereIn('id', $ids);
        }

        if ($mime = (string) $this->option('mime')) {
            $query->where('mime', $mime);
        }

        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }

        $force = (bool) $this->option('force');
        $tally = ['considered' => 0, 'dispatched' => 0, 'extracted' => 0, 'skipped' => 0, 'by_status' => []];

        foreach ($query->cursor() as $asset) {
            $tally['considered']++;

            if (! $this->option('sync')) {
                ExtractAssetJob::dispatch((int) $asset->id, $force);
                $tally['dispatched']++;

                continue;
            }

            $attempt = $service->extract($asset, $force);
            $status = (string) $attempt->row->status;

            $tally[$attempt->wasSkipped() ? 'skipped' : 'extracted']++;
            $tally['by_status'][$status] = ($tally['by_status'][$status] ?? 0) + 1;
        }

        ksort($tally['by_status']);

        if ($this->option('json')) {
            $this->line(json_encode($tally, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if (! $this->option('sync')) {
            $this->line("أُرسل {$tally['dispatched']} من {$tally['considered']} إلى الطابور.");

            return self::SUCCESS;
        }

        $this->line("فُحص {$tally['considered']} · استُخرج {$tally['extracted']} · تُخطّي {$tally['skipped']}");

        foreach ($tally['by_status'] as $status => $count) {
            $this->line("  {$status}: {$count}");
        }

        return self::SUCCESS;
    }
}
