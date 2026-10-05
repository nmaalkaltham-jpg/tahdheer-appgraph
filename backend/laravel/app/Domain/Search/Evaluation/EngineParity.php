<?php

declare(strict_types=1);

namespace App\Domain\Search\Evaluation;

use App\Domain\Search\Indexing\IndexDrift;
use App\Domain\Search\QueryPipeline;
use Illuminate\Support\Facades\Cache;

/**
 * هل يُجيب المحرّكان بالشيء نفسه؟ وهل الفهرس مرآةُ القاعدة؟
 *
 *   drift()   كلُّ منتجٍ منشور: وثيقتُه المتوقَّعة (`documentFor`) مقابل ما في
 *             الفهرس — ناقص، يتيم (في الفهرس لا في القاعدة)، أو قديمٌ حقلًا حقلًا.
 *   parity()  السؤال نفسه على المسار كاملًا (`QueryPipeline::dryRun`) مرّةً بـ
 *             PostgreSQL ومرّةً بـ Meilisearch: المجموع، والأوّل، والثلاثة،
 *             والعشرة، وأعداد كلّ وجه.
 *
 * قياسٌ لا ادّعاء: «متكافئان» لا تُقال بلا هذا الجدول.
 */
final class EngineParity
{
    public function __construct(
        private readonly IndexDrift $index,
        private readonly QueryPipeline $pipeline,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function drift(): array
    {
        return $this->index->measure();
    }

    /**
     * @param  list<array{query: string, type: string}>  $cases
     * @return array{summary: array<string, int|float>, mismatches: list<array<string, mixed>>}
     */
    public function parity(array $cases): array
    {
        $keys = ['total', 'top1', 'top3', 'top10', 'facets'];
        $count = array_fill_keys($keys, 0);
        $mismatches = [];

        foreach ($cases as $case) {
            $pg = $this->run($case['query'], 'pg');
            $me = $this->run($case['query'], 'meilisearch');
            if ($me['served'] !== 'meilisearch') {
                return ['summary' => ['error_meilisearch_unavailable' => 1], 'mismatches' => []];
            }

            $diff = [
                'total' => $pg['total'] !== $me['total'],
                'top1' => array_slice($pg['ids'], 0, 1) !== array_slice($me['ids'], 0, 1),
                'top3' => $this->set($pg['ids'], 3) !== $this->set($me['ids'], 3),
                'top10' => $this->set($pg['ids'], 10) !== $this->set($me['ids'], 10),
                'facets' => $pg['facets'] !== $me['facets'],
            ];
            foreach ($diff as $k => $bad) {
                $count[$k] += $bad ? 1 : 0;
            }
            if (in_array(true, $diff, true)) {
                $mismatches[] = ['query' => $case['query'], 'type' => $case['type'], 'diff' => array_keys(array_filter($diff)),
                    'pg' => ['total' => $pg['total'], 'top3' => array_slice($pg['ids'], 0, 3)],
                    'meili' => ['total' => $me['total'], 'top3' => array_slice($me['ids'], 0, 3)],
                    'facetDiff' => $this->facetDiff($pg['facets'], $me['facets'])];
            }
        }

        return ['summary' => ['queries' => count($cases)] + $count, 'mismatches' => $mismatches];
    }

    /**
     * @return array{total: int, ids: list<int>, facets: array<string, array<string, int>>, served: ?string}
     */
    private function run(string $query, string $provider): array
    {
        config(['search.provider' => $provider, 'ai.features.search_assist' => false]);
        Cache::forget('search.meili.healthy');
        $out = $this->pipeline->dryRun($query);
        $facets = $out['result']->facetCounts;
        unset($facets['nature']);
        foreach ($facets as &$values) {
            ksort($values);
        }
        ksort($facets);

        return [
            'total' => $out['result']->total,
            'ids' => $out['result']->items->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'facets' => $facets,
            'served' => $out['trace']['provider'] ?? null,
        ];
    }

    /** @param list<int> $ids */
    private function set(array $ids, int $k): array
    {
        $top = array_slice($ids, 0, $k);
        sort($top);

        return $top;
    }

    /**
     * @param  array<string, array<string, int>>  $a
     * @param  array<string, array<string, int>>  $b
     * @return array<string, array{pg: array<string, int>, meili: array<string, int>}>
     */
    private function facetDiff(array $a, array $b): array
    {
        $out = [];
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $dim) {
            if (($a[$dim] ?? []) !== ($b[$dim] ?? [])) {
                $out[$dim] = ['pg' => $a[$dim] ?? [], 'meili' => $b[$dim] ?? []];
            }
        }

        return $out;
    }
}
