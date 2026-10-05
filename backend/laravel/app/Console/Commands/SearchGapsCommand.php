<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Search\ZeroResultDiagnosis;
use App\Models\SearchQuery;
use Illuminate\Console\Command;

/**
 * فجوات البحث — أسئلةُ الزوّار التي لم تجد شيئًا، مصنّفةً بسببها وفعلها.
 *
 *   php artisan search:gaps              # آخر ٣٠ يومًا
 *   php artisan search:gaps --days=7 --limit=100
 *
 * يُعيد كلَّ سؤالٍ على البحث الحاليّ تشغيلًا جافًّا (`ZeroResultDiagnosis`)
 * ولا يكتب شيئًا. الأولويّة بمجموع البحث الفاشل والطلب الصريح للشيء نفسه.
 */
class SearchGapsCommand extends Command
{
    protected $signature = 'search:gaps
        {--days=30 : نافذةُ السجلّ بالأيّام}
        {--limit=50 : أكثرُ الأسئلة الفاشلة تكرارًا}';

    protected $description = 'Classify logged zero-result searches: resolved, missing inventory, missing synonym, off-catalog';

    private const ORDER = ['missing_inventory', 'unknown_term', 'off_catalog', 'resolved'];

    private const LABELS = [
        'missing_inventory' => 'منتجٌ ناقص',
        'unknown_term' => 'مرادفٌ ناقص',
        'off_catalog' => 'لا صلة بالمتجر',
        'resolved' => 'أُصلح منذ سُجّل',
    ];

    public function handle(ZeroResultDiagnosis $diagnosis): int
    {
        config(['ai.features.search_assist' => false]);

        $rows = SearchQuery::query()
            ->where('zero_result', true)
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->where('normalized_query', '!=', '')
            ->selectRaw('normalized_query, count(*) as hits')
            ->groupBy('normalized_query')
            ->orderByDesc('hits')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($rows->isEmpty()) {
            $this->info('  لا أسئلة فاشلة في النافذة.');

            return self::SUCCESS;
        }

        $groups = [];
        foreach ($rows as $row) {
            $d = $diagnosis->diagnose((string) $row->normalized_query);
            $groups[$d['reason']][] = [(int) $row->hits, (int) ($d['detail']['requested'] ?? 0), (string) $row->normalized_query, $d['action']];
        }

        foreach (self::ORDER as $reason) {
            $items = $groups[$reason] ?? [];
            if ($items === []) {
                continue;
            }
            /*
             * الطلبُ مجموعُ إشارتين: بحثٌ لم يجد، وطلبٌ صريح للشيء نفسه
             * (`material_requests`). المنتجُ الناقص الأعلى مجموعًا يُنتَج أوّلًا.
             */
            usort($items, fn (array $a, array $b): int => ($b[0] + $b[1]) <=> ($a[0] + $a[1]));
            $this->line(sprintf("\n  %s — %d سؤالًا · %d بحثًا · %d طلبًا", self::LABELS[$reason], count($items), array_sum(array_column($items, 0)), array_sum(array_column($items, 1))));
            $this->table(['بحث', 'طلب', 'السؤال', 'الفعل'], $items);
        }

        return self::SUCCESS;
    }
}
