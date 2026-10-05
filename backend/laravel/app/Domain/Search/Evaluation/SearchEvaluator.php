<?php

declare(strict_types=1);

namespace App\Domain\Search\Evaluation;

use App\Domain\Search\QueryPipeline;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * يشغّل المجموعة الذهبية على `QueryPipeline` الحقيقيّ ويُخرج المقاييس.
 *
 * ─── بلا أثرٍ جانبيّ ───
 *
 * `QueryPipeline::run` يسجّل كلّ سؤالٍ في `search_queries`. فكلّ سؤالٍ هنا
 * يجري داخل معاملةٍ تُلغى: ألفُ سؤالٍ تقييميّ لا يُزوّر «الأكثر بحثًا» ولا
 * نسبة الصفر في لوحة الإدارة. والفهمُ بالذكاء يُطفأ للتقييم كي يبقى القياس
 * حتميًّا ويقيس المسار الذي يُملكه الكود.
 */
final class SearchEvaluator
{
    /** الفئات التي لا تدخل مقاييس الصلة — لها مقياسها الخاصّ. */
    private const SPECIAL = ['nonsense', 'missing_inventory'];

    public function __construct(private readonly QueryPipeline $pipeline) {}

    /**
     * @param  list<array{query: string, type: string, intent: array<string, mixed>}>  $cases
     * @return array{summary: array<string, mixed>, byType: array<string, array<string, mixed>>, cases: list<array<string, mixed>>}
     */
    public function evaluate(array $cases): array
    {
        config(['ai.features.search_assist' => false]);

        $catalog = $this->catalog();

        $rows = [];
        foreach ($cases as $case) {
            $judged = $catalog->map(fn (array $p): int => RelevanceJudge::grade($case['intent'], $p));
            $positive = $judged->filter(fn (int $g): bool => $g > 0)->values()->all();

            DB::beginTransaction();
            try {
                $t0 = hrtime(true);
                $out = $this->pipeline->run($case['query']);
                $ms = (hrtime(true) - $t0) / 1e6;
            } finally {
                DB::rollBack();
            }

            $ids = $out['result']->items->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $grades = array_map(fn (int $id): int => $judged[$id] ?? 0, $ids);

            /* الصفحة كما يراها الزائر: المطابقة ثمّ الطبقة الثانية بترتيبها. */
            $pageIds = $ids;
            foreach ($out['related'] ?? [] as $group) {
                foreach ($group['items'] as $p) {
                    $pageIds[] = (int) $p->id;
                }
            }
            $pageGrades = array_map(fn (int $id): int => $judged[$id] ?? 0, $pageIds);

            $rows[] = [
                'query' => $case['query'],
                'type' => $case['type'],
                'total' => $out['result']->total,
                'ids' => array_slice($ids, 0, 10),
                'grades' => array_slice($grades, 0, 20),
                'page_grades' => array_slice($pageGrades, 0, 20),
                'ideal' => $positive,
                'relevant' => count(array_filter($positive, fn (int $g): bool => $g >= RankingMetrics::RELEVANT)),
                'best' => $positive === [] ? 0 : max($positive),
                'filters' => array_filter($out['filters']),
                'recovery' => $out['recoveryMeta']['mode'] ?? null,
                'ms' => round($ms, 2),
                'top' => array_map(fn (int $id): string => (string) ($catalog[$id]['title'] ?? $id), array_slice($ids, 0, 3)),
            ];
        }

        $byType = [];
        foreach (collect($rows)->groupBy('type') as $type => $group) {
            $byType[$type] = $this->aggregate($group->all(), (string) $type);
        }
        ksort($byType);

        $scored = array_values(array_filter($rows, fn (array $r): bool => ! in_array($r['type'], self::SPECIAL, true)));

        return [
            'summary' => $this->aggregate($scored, 'all') + [
                'cases' => count($rows),
                'nonsense_false_positive' => $byType['nonsense']['false_positive'] ?? null,
                'missing_inventory_useful@5' => $byType['missing_inventory']['useful@5'] ?? null,
                'latency_p50' => RankingMetrics::percentile(array_column($rows, 'ms'), 50),
                'latency_p95' => RankingMetrics::percentile(array_column($rows, 'ms'), 95),
                'latency_p99' => RankingMetrics::percentile(array_column($rows, 'ms'), 99),
            ],
            'byType' => $byType,
            'cases' => $rows,
        ];
    }

    /**
     * حقائقُ كلّ منتجٍ منشور كما يحتاجها الحكم.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function catalog(): Collection
    {
        return Product::query()->published()->with('grade:id,stage_id')->get()->map(fn (Product $p): array => [
            'id' => (int) $p->id,
            'title' => (string) $p->title,
            'subject_id' => $p->subject_id,
            'grade_id' => $p->grade_id,
            'stage_id' => $p->stage_id ?? $p->grade?->stage_id,
            'semester_id' => $p->semester_id,
            'kind' => $p->resource_type,
            'format' => $p->format !== null ? strtoupper((string) $p->format) : null,
        ])->keyBy('id');
    }

    /**
     * الإكمال التلقائيّ: السؤال مكتوبًا حتّى منتصف كلمته الأخيرة — كما يراه
     * الزائر وهو يكتب — ثمّ كاملًا. النجاح أن يظهر أفضلُ منتجٍ متاح في
     * الاقتراحات الثلاثة. لا كتابة: `suggest` لا يسجّل شيئًا.
     *
     * @param  list<array{query: string, type: string, intent: array<string, mixed>}>  $cases
     * @return array{summary: array<string, mixed>, byType: array<string, array<string, mixed>>}
     */
    public function evaluateSuggest(array $cases, int $limit = 3): array
    {
        config(['ai.features.search_assist' => false]);
        $catalog = $this->catalog();

        $rows = [];
        foreach ($cases as $case) {
            $judged = $catalog->map(fn (array $p): int => RelevanceJudge::grade($case['intent'], $p));
            $best = (int) $judged->max();
            if ($best < RankingMetrics::RELEVANT) {
                continue;
            }
            $words = preg_split('/\s+/u', $case['query']) ?: [];
            $last = (string) array_pop($words);
            $keep = max(2, (int) ceil(mb_strlen($last) / 2));
            $partial = trim(implode(' ', $words).' '.mb_substr($last, 0, $keep));

            $hit = function (string $q) use ($judged, $best, $limit): array {
                $t0 = hrtime(true);
                $ids = $this->pipeline->suggest($q, $limit)->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $ms = (hrtime(true) - $t0) / 1e6;

                return [in_array($best, array_map(fn (int $id): int => $judged[$id] ?? 0, $ids), true), $ids === [], $ms];
            };
            [$partialHit, $partialEmpty, $ms] = $hit($partial);
            [$fullHit, $fullEmpty] = $hit($case['query']);

            $rows[] = ['type' => $case['type'], 'partial' => $partialHit, 'partial_empty' => $partialEmpty, 'full' => $fullHit, 'full_empty' => $fullEmpty, 'ms' => $ms];
        }

        $agg = fn (array $r): array => [
            'n' => count($r),
            'partial@3' => $this->mean(array_map(fn ($x): float => $x['partial'] ? 1.0 : 0.0, $r)),
            'partial_empty' => $this->mean(array_map(fn ($x): float => $x['partial_empty'] ? 1.0 : 0.0, $r)),
            'full@3' => $this->mean(array_map(fn ($x): float => $x['full'] ? 1.0 : 0.0, $r)),
            'full_empty' => $this->mean(array_map(fn ($x): float => $x['full_empty'] ? 1.0 : 0.0, $r)),
        ];

        $byType = [];
        foreach (collect($rows)->groupBy('type') as $type => $group) {
            $byType[(string) $type] = $agg($group->all());
        }
        ksort($byType);

        return ['summary' => $agg($rows) + ['latency_p95' => RankingMetrics::percentile(array_column($rows, 'ms'), 95)], 'byType' => $byType];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function aggregate(array $rows, string $type): array
    {
        $n = count($rows);
        if ($n === 0) {
            return ['n' => 0];
        }
        if ($type === 'nonsense') {
            return ['n' => $n, 'false_positive' => $this->mean(array_map(fn (array $r): float => $r['total'] > 0 ? 1.0 : 0.0, $rows))];
        }
        if ($type === 'missing_inventory') {
            return [
                'n' => $n,
                'zero' => $this->mean(array_map(fn (array $r): float => $r['total'] === 0 ? 1.0 : 0.0, $rows)),
                'useful@5' => $this->mean(array_map(fn (array $r): float => max(array_slice($r['grades'], 0, 5) ?: [0]) >= 1 ? 1.0 : 0.0, $rows)),
            ];
        }

        $m = fn (callable $f): ?float => $this->mean(array_values(array_filter(array_map($f, $rows), fn ($v): bool => $v !== null)));

        return [
            'n' => $n,
            'zero' => $m(fn (array $r): float => $r['total'] === 0 ? 1.0 : 0.0),
            'top1' => $m(fn (array $r): float => RankingMetrics::bestInTop($r['grades'], 1, $r['best']) ? 1.0 : 0.0),
            'top3' => $m(fn (array $r): float => RankingMetrics::bestInTop($r['grades'], 3, $r['best']) ? 1.0 : 0.0),
            'top5' => $m(fn (array $r): float => RankingMetrics::bestInTop($r['grades'], 5, $r['best']) ? 1.0 : 0.0),
            'p@1' => $m(fn (array $r): float => RankingMetrics::precisionAt($r['grades'], 1)),
            'p@5' => $m(fn (array $r): float => RankingMetrics::precisionAt($r['grades'], 5)),
            'r@10' => $m(fn (array $r): ?float => RankingMetrics::recallAt($r['grades'], 10, $r['relevant'])),
            'mrr@10' => $m(fn (array $r): float => RankingMetrics::reciprocalRankAt($r['grades'], 10)),
            'ndcg@10' => $m(fn (array $r): ?float => RankingMetrics::ndcgAt($r['grades'], $r['ideal'], 10)),
            'wrong@5' => $m(fn (array $r): float => count(array_filter(array_slice($r['grades'], 0, 5), fn (int $g): bool => $g === 0)) / 5),
            /*
             * الصفحة كاملة (المطابقة + «قريبٌ ممّا تبحث عنه»). الاسترجاع يُحسب
             * على كلّ حكمٍ موجب (≥١)، فالقريبُ الضعيف يُعدّ — وهو ما وُجدت الطبقة له.
             */
            'page_ndcg@10' => $m(fn (array $r): ?float => RankingMetrics::ndcgAt($r['page_grades'], $r['ideal'], 10)),
            'page_r@10' => $m(fn (array $r): ?float => $r['ideal'] === [] ? null : count(array_filter(array_slice($r['page_grades'], 0, 10), fn (int $g): bool => $g >= 1)) / count($r['ideal'])),
            'page_wrong@10' => $m(fn (array $r): float => count(array_filter(array_slice($r['page_grades'], 0, 10), fn (int $g): bool => $g === 0)) / max(1, min(10, count($r['page_grades'])))),
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 4);
    }
}
