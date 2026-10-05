<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Search\Evaluation\GoldenQuerySet;
use App\Domain\Search\Evaluation\SearchEvaluator;
use App\Domain\Search\Evaluation\SyntheticCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * قياسُ البحث على المجموعة الذهبية المولَّدة من الكتالوج الحاليّ.
 *
 *   php artisan search:eval --label=before
 *   php artisan search:eval --label=after --compare=before
 *   php artisan search:eval --type=typo --failures=20
 *   php artisan search:eval --synthetic       # كتالوجٌ اصطناعيّ في معاملةٍ تُلغى
 *
 * يكتب `storage/app/search-eval/<label>.json` (المقاييس + كلّ سؤال)، ولا
 * يترك أثرًا في `search_queries`.
 */
class SearchEvalCommand extends Command
{
    protected $signature = 'search:eval
        {--label=latest : اسم ملفّ النتيجة}
        {--compare= : تسميةُ تشغيلٍ سابق للمقارنة}
        {--type= : فئةٌ واحدة فقط}
        {--failures=25 : عدد الإخفاقات المطبوعة}
        {--suggest : قِس الإكمال التلقائيّ (السؤال حتّى منتصف كلمته الأخيرة، ثمّ كاملًا)}
        {--synthetic : أضف كتالوجًا اصطناعيًّا من التصنيف داخل معاملةٍ تُلغى — يعمل على متجرٍ فارغ}';

    protected $description = 'Measure search relevance (P@k, Recall, MRR, NDCG, zero-result, latency) on the generated golden query set';

    private const KEYS = ['zero', 'top1', 'top3', 'p@1', 'r@10', 'mrr@10', 'ndcg@10', 'wrong@5', 'page_ndcg@10', 'page_r@10', 'page_wrong@10'];

    public function handle(GoldenQuerySet $set, SearchEvaluator $evaluator, SyntheticCatalog $synthetic): int
    {
        if (! $this->option('synthetic')) {
            return $this->measure($set, $evaluator);
        }

        DB::beginTransaction();
        try {
            $this->line(sprintf('  كتالوجٌ اصطناعيّ: %d منتجًا (يُلغى عند الانتهاء)', $synthetic->build()));

            return $this->measure($set, $evaluator);
        } finally {
            DB::rollBack();
        }
    }

    private function measure(GoldenQuerySet $set, SearchEvaluator $evaluator): int
    {
        $cases = $set->build();
        if (($type = $this->option('type')) !== null) {
            $cases = array_values(array_filter($cases, fn (array $c): bool => $c['type'] === $type));
        }
        if ($cases === []) {
            $this->error('لا أسئلة — الكتالوج بلا منتجاتٍ منشورة مصنّفة؟');

            return self::FAILURE;
        }

        if ($this->option('suggest')) {
            $report = $evaluator->evaluateSuggest($cases);
            Storage::disk('local')->put('search-eval/'.$this->option('label').'.suggest.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $keys = ['partial@3', 'partial_empty', 'full@3', 'full_empty'];
            $rows = [['ALL', $report['summary']['n'], ...array_map(fn ($k) => $this->fmt($report['summary'][$k]), $keys)]];
            foreach ($report['byType'] as $type => $m) {
                $rows[] = [$type, $m['n'], ...array_map(fn ($k) => $this->fmt($m[$k]), $keys)];
            }
            $this->line(sprintf('  الإكمال التلقائيّ · p95 %.1fms', $report['summary']['latency_p95'] ?? 0));
            $this->table(['type', 'n', ...$keys], $rows);

            return self::SUCCESS;
        }

        $report = $evaluator->evaluate($cases);
        if (($report['summary']['n'] ?? 0) === 0) {
            $this->warn('  لا منتجات منشورة مصنّفة — لا أسئلة ذات صلة تُقاس. شغّل: php artisan search:eval --synthetic');

            return self::FAILURE;
        }
        $label = (string) $this->option('label');
        $disk = Storage::disk('local');
        $disk->put("search-eval/{$label}.json", json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $before = null;
        if (($cmp = $this->option('compare')) !== null && $disk->exists("search-eval/{$cmp}.json")) {
            $before = json_decode((string) $disk->get("search-eval/{$cmp}.json"), true);
        }

        $s = $report['summary'];
        $this->line(sprintf('  %d أسئلة · p50 %.1fms · p95 %.1fms · p99 %.1fms', $s['cases'], $s['latency_p50'], $s['latency_p95'], $s['latency_p99']));
        $this->line(sprintf('  nonsense false-positive %s · missing-inventory useful@5 %s', $this->fmt($s['nonsense_false_positive']), $this->fmt($s['missing_inventory_useful@5'])));

        $rows = [['ALL', $s['n'], ...$this->cells($s, $before['summary'] ?? null)]];
        foreach ($report['byType'] as $type => $m) {
            if (isset($m['top1'])) {
                $rows[] = [$type, $m['n'], ...$this->cells($m, $before['byType'][$type] ?? null)];
            }
        }
        $this->table(['type', 'n', ...self::KEYS], $rows);

        $fails = array_filter($report['cases'], fn (array $r): bool => $r['best'] >= 2 && ! in_array($r['best'], array_slice($r['grades'], 0, 3), true));
        foreach (array_slice(array_values($fails), 0, (int) $this->option('failures')) as $f) {
            $this->line(sprintf('  ✗ [%s] %s → total %d · filters %s · recovery %s · top: %s', $f['type'], $f['query'], $f['total'], json_encode($f['filters'], JSON_UNESCAPED_UNICODE), $f['recovery'] ?? '-', implode(' | ', array_map(fn ($t) => mb_substr($t, 0, 40), $f['top']))));
        }
        $this->line(sprintf('  %d إخفاقًا في أوّل ثلاث · الملفّ: storage/app/search-eval/%s.json', count($fails), $label));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>|null  $b
     * @return list<string>
     */
    private function cells(array $m, ?array $b): array
    {
        return array_map(function (string $k) use ($m, $b): string {
            $v = $m[$k] ?? null;
            $cell = $this->fmt($v);
            if ($b !== null && isset($b[$k]) && $v !== null) {
                $d = $v - $b[$k];
                $cell .= abs($d) < 0.00005 ? '' : sprintf(' (%+.2f)', $d);
            }

            return $cell;
        }, self::KEYS);
    }

    private function fmt(mixed $v): string
    {
        return $v === null ? '—' : sprintf('%.2f', $v);
    }
}
