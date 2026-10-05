<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Search\SynonymLearner;
use Illuminate\Console\Command;

/**
 * مرادفاتٌ معلّقة من الأسئلة الفاشلة — تنتظر موافقة المشرف في جدول المرادفات.
 *
 *   php artisan search:learn-synonyms              # آخر ٣٠ يومًا، دليلٌ ≥ ٢
 *   php artisan search:learn-synonyms --dry-run    # ما سيُكتب، بلا كتابة
 */
class SearchLearnSynonymsCommand extends Command
{
    protected $signature = 'search:learn-synonyms
        {--days=30 : نافذةُ السجلّ بالأيّام}
        {--min=2 : أدنى عددٍ من البحث الفاشل لاعتبار الزوج نمطًا}
        {--dry-run : اعرض ما سيُكتب ولا تكتب}';

    protected $description = 'Propose pending synonyms (source=learned) from zero-result near misses, for admin approval';

    public function handle(SynonymLearner $learner): int
    {
        $s = $learner->learn((int) $this->option('days'), (int) $this->option('min'), (bool) $this->option('dry-run'));

        $this->line(sprintf(
            '  %sجديدة: %d · حُدّث دليلها: %d · قرارُ مشرفٍ محفوظ: %d',
            $this->option('dry-run') ? '(تجربة) ' : '',
            $s['created'], $s['updated'], $s['kept'],
        ));

        return self::SUCCESS;
    }
}
