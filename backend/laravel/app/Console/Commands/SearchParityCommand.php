<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Search\Evaluation\EngineParity;
use App\Domain\Search\Evaluation\GoldenQuerySet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * تكافؤُ المحرّكين وانحرافُ الفهرس — مقيسان.
 *
 *   php artisan search:parity                 # انحرافٌ + تكافؤٌ على المجموعة الذهبيّة
 *   php artisan search:parity --drift-only
 *   php artisan search:parity --sample=300    # عيّنةٌ حتميّة من الأسئلة
 *
 * لا يكتب في القاعدة (كلُّ سؤالٍ تشغيلٌ جافّ داخل معاملةٍ تُلغى)، ويكتب
 * التقرير في storage/app/search-eval/parity.json.
 */
class SearchParityCommand extends Command
{
    protected $signature = 'search:parity
        {--drift-only : انحرافُ الفهرس وحده}
        {--sample=0 : عددُ الأسئلة (٠ = كلّها)}
        {--show=15 : أمثلةُ اختلافٍ تُطبع}';

    protected $description = 'Measure Meilisearch index drift against the database and PostgreSQL vs Meilisearch answer parity';

    public function handle(EngineParity $parity, GoldenQuerySet $set): int
    {
        $drift = $parity->drift();
        $this->line(sprintf('  الفهرس %s · منشور %d · مفهرس %d · ناقص %d · يتيم %d', $drift['index'], $drift['published'], $drift['indexed'], count($drift['missing']), count($drift['orphans'])));
        foreach ($drift['stale'] as $field => $ids) {
            $this->line(sprintf('    قديم «%s»: %d منتجًا (%s)', $field, count($ids), implode(', ', array_slice($ids, 0, 8))));
        }
        $report = ['drift' => $drift];

        if (! $this->option('drift-only')) {
            $cases = $set->build();
            if (($n = (int) $this->option('sample')) > 0 && $n < count($cases)) {
                $step = count($cases) / $n;
                $cases = array_values(array_map(fn (int $i) => $cases[(int) floor($i * $step)], range(0, $n - 1)));
            }

            DB::beginTransaction();
            try {
                $result = $parity->parity($cases);
            } finally {
                DB::rollBack();
                config(['search.provider' => env('SEARCH_PROVIDER', 'pg')]);
            }

            $s = $result['summary'];
            if (isset($s['error_meilisearch_unavailable'])) {
                $this->error('  Meilisearch لا يُجيب — لا تكافؤ يُقاس.');

                return self::FAILURE;
            }
            $this->line(sprintf('  تكافؤ: %d سؤالًا · اختلاف المجموع %d · الأوّل %d · الثلاثة %d · العشرة %d · الأوجه %d', $s['queries'], $s['total'], $s['top1'], $s['top3'], $s['top10'], $s['facets']));
            foreach (array_slice($result['mismatches'], 0, (int) $this->option('show')) as $m) {
                $this->line(sprintf('    ✗ [%s] %s · %s · pg %d %s · meili %d %s%s', $m['type'], $m['query'], implode('+', $m['diff']), $m['pg']['total'], json_encode($m['pg']['top3']), $m['meili']['total'], json_encode($m['meili']['top3']), $m['facetDiff'] === [] ? '' : ' · أوجه: '.implode(',', array_keys($m['facetDiff']))));
            }
            $report['parity'] = $result;
        }

        Storage::disk('local')->put('search-eval/parity.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
