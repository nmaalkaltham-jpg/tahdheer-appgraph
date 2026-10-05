<?php

namespace App\Console\Commands;

use App\Domain\UrlPlatform\UrlPlatformError;
use App\Domain\UrlPlatform\UrlRegistry;
use App\Models\Grade;
use App\Models\Kind;
use App\Models\Product;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * تعبئة سجلّ العناوين بما سبقه — المنتجات والتصنيف (المراحل والصفوف والموادّ
 * والفصول والأنواع).
 *
 * خامل: ما له صفٌّ حيّ يُتخطّى، والتصادم يُقال باسمه ولا يُحلّ بلاحقةٍ خفيّة
 * — رابطٌ منشور لا يُغيَّر من أمرٍ في الخلفيّة.
 */
final class UrlBackfillCommand extends Command
{
    protected $signature = 'url:backfill {--dry-run : اعرض ما سيُسجَّل بلا كتابة}';

    protected $description = 'يعبّئ سجلّ العناوين بالمنتجات ومصطلحات التصنيف القائمة';

    public function handle(UrlRegistry $registry): int
    {
        $dry = (bool) $this->option('dry-run');
        $claimed = 0;
        $skipped = 0;
        $conflicts = [];

        $sources = [
            'product' => Product::query()->orderBy('id')->cursor(),
            'stage' => Stage::query()->orderBy('id')->cursor(),
            'grade' => Grade::query()->orderBy('id')->cursor(),
            'subject' => Subject::query()->orderBy('id')->cursor(),
            'semester' => Semester::query()->orderBy('id')->cursor(),
            'kind' => Kind::query()->orderBy('id')->cursor(),
        ];

        foreach ($sources as $type => $rows) {
            /** @var Model $row */
            foreach ($rows as $row) {
                $slug = (string) $row->getAttribute('slug');
                if ($slug === '') {
                    continue;
                }
                if ($registry->activeFor($type, (int) $row->getKey()) !== null) {
                    $skipped++;

                    continue;
                }
                // المنتج المنشور وحده عامّ؛ التصنيف عامّ دائمًا (لا حالة نشرٍ له).
                $public = $type !== 'product' || $row->getAttribute('status') === 'published';
                $retired = $type === 'product' && $row->getAttribute('status') === 'retired';

                if ($dry) {
                    $this->line(sprintf('%s #%d → %s (%s)', $type, $row->getKey(), $slug, $public ? 'published' : ($retired ? 'archived' : 'reserved')));
                    $claimed++;

                    continue;
                }

                try {
                    $entry = $registry->claim($type, (int) $row->getKey(), 'ar', $slug, ['source' => 'backfill', 'allowSuffix' => false]);
                    if ($public || $retired) {
                        $entry = $registry->publish($entry, ['source' => 'backfill']);
                    }
                    if ($retired) {
                        $registry->archive($entry, ['source' => 'backfill']);
                    }
                    $claimed++;
                } catch (UrlPlatformError $e) {
                    $conflicts[] = sprintf('%s #%d «%s»: %s', $type, $row->getKey(), $slug, $e->getMessage());
                }
            }
        }

        $this->info(sprintf('سُجّل %d · تُخطّي %d (مسجّل سلفًا) · تعارضات %d', $claimed, $skipped, count($conflicts)));
        foreach ($conflicts as $line) {
            $this->warn($line);
        }

        return $conflicts === [] ? self::SUCCESS : self::FAILURE;
    }
}
