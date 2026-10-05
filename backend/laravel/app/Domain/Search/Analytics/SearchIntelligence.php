<?php

declare(strict_types=1);

namespace App\Domain\Search\Analytics;

use App\Domain\Orders\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ذكاءُ البحث — ما يبحث عنه الناس، وما يفشل، وأين يضيع الإيراد.
 *
 * كلُّ نسبةٍ تُعاد مع بسطها ومقامها (`rate()`)، ولا تُعاد نسبةٌ مقامُها صفر (null،
 * لا ٠٪). وثلاثة مقامات مختلفة عمدًا:
 *
 *   searches            كلُّ حدث بحث في النافذة (المصدر المرشَّح)
 *   tracked             ما سُجّل له ظهورٌ واحد على الأقلّ — مقامُ CTR: لا نقرة بلا رؤية
 *   consented           ما رُبطت به جلسةٌ (موافقة القياس) — مقامُ القمع والإيراد:
 *                       السلّة والطلب لا يُنسبان إلّا بموافقة، فمقامُهما الموافقون وحدهم
 *
 * الدفعُ والاسترداد من حالة الطلب الآن (`OrderStatus`)، لا نسخةٌ تنحرف.
 */
final class SearchIntelligence
{
    public const TOP = 25;

    /**
     * @param  array{source?: ?string, device?: ?string, provider?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        [$where, $bind] = $this->scope($from, $to, $filters);

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'filters' => array_filter($filters)],
            'overview' => $this->overview($where, $bind),
            'funnel' => $this->funnel($where, $bind),
            'rankCtr' => $this->rankCtr($where, $bind),
            'queries' => $this->queries($where, $bind),
            'products' => $this->products($where, $bind),
            'filtersUsed' => $this->filtersUsed($where, $bind),
            'recovery' => $this->recovery($where, $bind),
            'engines' => $this->engines($where, $bind),
            'latency' => $this->latency($where, $bind),
        ];
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function scope(CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $where = 'q.created_at >= ? AND q.created_at < ?';
        $bind = [$from->toDateTimeString(), $to->toDateTimeString()];
        foreach (['source' => 'q.source', 'device' => 'q.device', 'provider' => 'q.provider'] as $key => $col) {
            if (($filters[$key] ?? null) !== null && $filters[$key] !== '') {
                $where .= " AND {$col} = ?";
                $bind[] = $filters[$key];
            }
        }

        return [$where, $bind];
    }

    /** @return array{numerator: int, denominator: int, rate: ?float} */
    private function rate(int|float $num, int|float $den): array
    {
        return ['numerator' => (int) $num, 'denominator' => (int) $den, 'rate' => $den > 0 ? round($num / $den, 4) : null];
    }

    private function overview(string $where, array $bind): array
    {
        $r = DB::selectOne("
            WITH s AS (
              SELECT q.id, q.session_hash, q.zero_result, q.latency_ms, q.recovery_mode, q.results_count, q.normalized_query, q.created_at,
                     EXISTS (SELECT 1 FROM search_interactions i WHERE i.search_query_id = q.id AND i.kind = 'impression') AS tracked,
                     EXISTS (SELECT 1 FROM search_interactions i WHERE i.search_query_id = q.id AND i.kind = 'click') AS clicked,
                     EXISTS (SELECT 1 FROM search_interactions i WHERE i.search_query_id = q.id AND i.kind = 'click' AND i.destination = 'pdp') AS to_pdp
              FROM search_queries q WHERE {$where}
            ), seq AS (
              SELECT s.*, LEAD(s.created_at) OVER w AS next_at, LEAD(s.normalized_query) OVER w AS next_query
              FROM s WHERE s.session_hash IS NOT NULL
              WINDOW w AS (PARTITION BY s.session_hash ORDER BY s.created_at, s.id)
            )
            SELECT
              (SELECT count(*) FROM s) AS searches,
              (SELECT count(DISTINCT session_hash) FROM s WHERE session_hash IS NOT NULL) AS sessions,
              (SELECT count(*) FROM s WHERE session_hash IS NOT NULL) AS consented,
              (SELECT count(*) FROM s WHERE zero_result) AS zero,
              (SELECT count(*) FROM s WHERE tracked) AS tracked,
              (SELECT count(*) FROM s WHERE tracked AND clicked) AS tracked_clicked,
              (SELECT count(*) FROM s WHERE tracked AND to_pdp) AS tracked_pdp,
              (SELECT count(*) FROM s WHERE tracked AND NOT clicked) AS no_click,
              (SELECT count(*) FROM s WHERE recovery_mode IS NOT NULL) AS recovered,
              (SELECT count(*) FROM s WHERE zero_result = false AND recovery_mode IS NOT NULL) AS recovered_with_results,
              (SELECT round(avg(latency_ms)::numeric, 1) FROM s) AS avg_ms,
              (SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY latency_ms) FROM s WHERE latency_ms IS NOT NULL) AS p50,
              (SELECT percentile_cont(0.95) WITHIN GROUP (ORDER BY latency_ms) FROM s WHERE latency_ms IS NOT NULL) AS p95,
              (SELECT percentile_cont(0.99) WITHIN GROUP (ORDER BY latency_ms) FROM s WHERE latency_ms IS NOT NULL) AS p99,
              (SELECT count(*) FROM seq WHERE next_at IS NOT NULL AND next_at <= created_at + interval '2 minutes' AND next_query <> normalized_query AND NOT clicked) AS reformulated,
              (SELECT count(*) FROM seq WHERE tracked AND NOT clicked AND (next_at IS NULL OR next_at > created_at + interval '30 minutes')) AS abandoned,
              (SELECT count(*) FROM seq WHERE tracked) AS consented_tracked
        ", $bind);

        return [
            'searches' => (int) $r->searches,
            'uniqueSessions' => (int) $r->sessions,
            'consentedShare' => $this->rate($r->consented, $r->searches),
            'zeroResult' => $this->rate($r->zero, $r->searches),
            'impressionTracked' => $this->rate($r->tracked, $r->searches),
            'ctr' => $this->rate($r->tracked_clicked, $r->tracked),
            'searchToPdp' => $this->rate($r->tracked_pdp, $r->tracked),
            'noClickSearches' => $this->rate($r->no_click, $r->tracked),
            'recoveryUsage' => $this->rate($r->recovered, $r->searches),
            'recoveredZeroResults' => $this->rate($r->recovered_with_results, $r->recovered),
            /* الجلسة شرطُهما: إعادةُ صياغةٍ خلال دقيقتين بلا نقرة، وتركٌ بلا نقرةٍ ولا بحثٍ تالٍ خلال ٣٠ دقيقة. */
            'reformulation' => $this->rate($r->reformulated, $r->consented),
            'abandonment' => $this->rate($r->abandoned, $r->consented_tracked),
            'latencyMs' => ['avg' => $r->avg_ms === null ? null : (float) $r->avg_ms, 'p50' => $this->ms($r->p50), 'p95' => $this->ms($r->p95), 'p99' => $this->ms($r->p99)],
        ];
    }

    private function funnel(string $where, array $bind): array
    {
        $collected = "'".implode("','", OrderStatus::COLLECTED)."'";
        $r = DB::selectOne("
            WITH s AS (SELECT q.id FROM search_queries q WHERE {$where} AND q.session_hash IS NOT NULL)
            SELECT
              (SELECT count(*) FROM s) AS consented,
              (SELECT count(DISTINCT i.search_query_id) FROM search_interactions i JOIN s ON s.id = i.search_query_id WHERE i.kind = 'click') AS clicked,
              (SELECT count(DISTINCT a.search_query_id) FROM search_attributions a JOIN s ON s.id = a.search_query_id WHERE a.stage = 'add_to_cart') AS carted,
              (SELECT count(DISTINCT a.search_query_id) FROM search_attributions a JOIN s ON s.id = a.search_query_id WHERE a.stage = 'checkout') AS checkout,
              (SELECT count(DISTINCT a.search_query_id) FROM search_attributions a JOIN s ON s.id = a.search_query_id JOIN orders o ON o.id = a.order_id
                 WHERE a.stage = 'checkout' AND o.status IN ({$collected})) AS purchased,
              (SELECT coalesce(sum(a.value_minor), 0) FROM search_attributions a JOIN s ON s.id = a.search_query_id JOIN orders o ON o.id = a.order_id
                 WHERE a.stage = 'checkout' AND o.status IN ({$collected})) AS revenue,
              (SELECT coalesce(sum(a.value_minor), 0) FROM search_attributions a JOIN s ON s.id = a.search_query_id JOIN orders o ON o.id = a.order_id
                 WHERE a.stage = 'checkout' AND o.status IN ('REFUNDED', 'PARTIALLY_REFUNDED')) AS refunded,
              (SELECT count(*) FROM search_attributions a JOIN s ON s.id = a.search_query_id WHERE a.stage = 'remove_from_cart') AS removed
        ", $bind);

        return [
            'consentedSearches' => (int) $r->consented,
            'searchToClick' => $this->rate($r->clicked, $r->consented),
            'searchToCart' => $this->rate($r->carted, $r->consented),
            'searchToCheckout' => $this->rate($r->checkout, $r->consented),
            'searchToPurchase' => $this->rate($r->purchased, $r->consented),
            'cartRemovals' => (int) $r->removed,
            'revenueInfluencedMinor' => (int) $r->revenue,
            'revenueRefundedMinor' => (int) $r->refunded,
            'revenuePerConsentedSearchMinor' => (int) $r->consented > 0 ? (int) round($r->revenue / $r->consented) : null,
        ];
    }

    private function rankCtr(string $where, array $bind): array
    {
        $rows = DB::select("
            SELECT i.rank,
                   count(*) FILTER (WHERE i.kind = 'impression') AS impressions,
                   count(*) FILTER (WHERE i.kind = 'click') AS clicks
            FROM search_interactions i JOIN search_queries q ON q.id = i.search_query_id
            WHERE {$where} AND i.rank <= 10
            GROUP BY i.rank ORDER BY i.rank
        ", $bind);

        return array_map(fn ($r) => ['rank' => (int) $r->rank] + $this->rate($r->clicks, $r->impressions), $rows);
    }

    private function queries(string $where, array $bind): array
    {
        $collected = "'".implode("','", OrderStatus::COLLECTED)."'";
        $rows = DB::select("
            WITH s AS (SELECT q.* FROM search_queries q WHERE {$where} AND q.normalized_query <> ''),
                 rev AS (SELECT a.search_query_id, sum(a.value_minor) AS v FROM search_attributions a JOIN orders o ON o.id = a.order_id
                         WHERE a.stage = 'checkout' AND o.status IN ({$collected}) GROUP BY a.search_query_id)
            SELECT s.normalized_query AS query,
                   count(*) AS searches,
                   count(*) FILTER (WHERE s.zero_result) AS zero,
                   count(*) FILTER (WHERE s.recovery_mode IS NOT NULL) AS recovered,
                   count(*) FILTER (WHERE s.session_hash IS NOT NULL) AS consented,
                   count(*) FILTER (WHERE EXISTS (SELECT 1 FROM search_interactions i WHERE i.search_query_id = s.id AND i.kind = 'impression')) AS tracked,
                   count(*) FILTER (WHERE EXISTS (SELECT 1 FROM search_interactions i WHERE i.search_query_id = s.id AND i.kind = 'click')) AS clicked,
                   count(*) FILTER (WHERE EXISTS (SELECT 1 FROM search_attributions a WHERE a.search_query_id = s.id AND a.stage = 'add_to_cart')) AS carted,
                   count(*) FILTER (WHERE EXISTS (SELECT 1 FROM search_attributions a JOIN orders o ON o.id = a.order_id
                                                  WHERE a.search_query_id = s.id AND a.stage = 'checkout' AND o.status IN ({$collected}))) AS purchased,
                   coalesce(sum(rev.v), 0) AS revenue
            FROM s LEFT JOIN rev ON rev.search_query_id = s.id GROUP BY s.normalized_query
        ", $bind);

        $all = array_map(fn ($r) => [
            'query' => $r->query,
            'searches' => (int) $r->searches,
            'zeroResult' => $this->rate($r->zero, $r->searches),
            'recovered' => (int) $r->recovered,
            'ctr' => $this->rate($r->clicked, $r->tracked),
            'toCart' => $this->rate($r->carted, $r->consented),
            'toPurchase' => $this->rate($r->purchased, $r->consented),
            'revenueMinor' => (int) $r->revenue,
        ], $rows);

        $by = function (callable $filter, callable $sort) use ($all): array {
            $x = array_values(array_filter($all, $filter));
            usort($x, $sort);

            return array_slice($x, 0, self::TOP);
        };

        return [
            'top' => $by(fn () => true, fn ($a, $b) => $b['searches'] <=> $a['searches']),
            'zeroResult' => $by(fn ($q) => $q['zeroResult']['numerator'] > 0, fn ($a, $b) => $b['zeroResult']['numerator'] <=> $a['zeroResult']['numerator']),
            'noClick' => $by(fn ($q) => $q['ctr']['denominator'] > 0 && $q['ctr']['numerator'] === 0, fn ($a, $b) => $b['ctr']['denominator'] <=> $a['ctr']['denominator']),
            'topConverting' => $by(fn ($q) => $q['toPurchase']['numerator'] > 0, fn ($a, $b) => [$b['toPurchase']['rate'], $b['revenueMinor']] <=> [$a['toPurchase']['rate'], $a['revenueMinor']]),
            /* كثيرُ البحث قليلُ الشراء: في النصف الأعلى حجمًا، ولا شراء. */
            'highVolumeLowConversion' => $by(
                fn ($q) => $q['toPurchase']['denominator'] > 0 && $q['toPurchase']['numerator'] === 0 && $q['searches'] >= $this->median(array_column($all, 'searches')),
                fn ($a, $b) => $b['searches'] <=> $a['searches'],
            ),
        ];
    }

    private function products(string $where, array $bind): array
    {
        $collected = "'".implode("','", OrderStatus::COLLECTED)."'";
        $rows = DB::select("
            WITH s AS (SELECT q.id FROM search_queries q WHERE {$where})
            SELECT p.id, p.title,
                   count(*) FILTER (WHERE i.kind = 'impression') AS impressions,
                   count(*) FILTER (WHERE i.kind = 'click') AS clicks,
                   (SELECT count(*) FROM search_attributions a JOIN s s2 ON s2.id = a.search_query_id WHERE a.product_id = p.id AND a.stage = 'add_to_cart') AS carts,
                   (SELECT count(*) FROM search_attributions a JOIN s s2 ON s2.id = a.search_query_id JOIN orders o ON o.id = a.order_id
                      WHERE a.product_id = p.id AND a.stage = 'checkout' AND o.status IN ({$collected})) AS purchases,
                   avg(i.rank) FILTER (WHERE i.kind = 'impression') AS avg_rank
            FROM search_interactions i JOIN s ON s.id = i.search_query_id JOIN products p ON p.id = i.product_id
            GROUP BY p.id, p.title
        ", $bind);

        $all = array_map(fn ($r) => [
            'productId' => (int) $r->id,
            'title' => $r->title,
            'impressions' => (int) $r->impressions,
            'averageRank' => $r->avg_rank === null ? null : round((float) $r->avg_rank, 1),
            'ctr' => $this->rate($r->clicks, $r->impressions),
            'clickToCart' => $this->rate($r->carts, $r->clicks),
            'clickToPurchase' => $this->rate($r->purchases, $r->clicks),
        ], $rows);
        $medianImpressions = $this->median(array_column($all, 'impressions'));
        $medianClicks = $this->median(array_map(fn ($p) => $p['ctr']['numerator'], $all));

        $lowCtr = array_values(array_filter($all, fn ($p) => $p['impressions'] >= max(1, $medianImpressions)));
        usort($lowCtr, fn ($a, $b) => [$a['ctr']['rate'] ?? 0, -$a['impressions']] <=> [$b['ctr']['rate'] ?? 0, -$b['impressions']]);
        $lowPurchase = array_values(array_filter($all, fn ($p) => $p['ctr']['numerator'] >= max(1, $medianClicks) && $p['clickToPurchase']['numerator'] === 0));
        usort($lowPurchase, fn ($a, $b) => $b['ctr']['numerator'] <=> $a['ctr']['numerator']);

        return [
            'highImpressionLowCtr' => array_slice($lowCtr, 0, self::TOP),
            'highClickLowPurchase' => array_slice($lowPurchase, 0, self::TOP),
        ];
    }

    private function filtersUsed(string $where, array $bind): array
    {
        $rows = DB::select("
            SELECT f.key AS dimension, count(*) AS searches
            FROM search_queries q, jsonb_each_text(coalesce(q.filters, '{}'::jsonb)) AS f
            WHERE {$where} AND f.key NOT IN ('page', 'perPage')
            GROUP BY f.key ORDER BY count(*) DESC
        ", $bind);
        $total = (int) DB::selectOne("SELECT count(*) AS n FROM search_queries q WHERE {$where}", $bind)->n;

        return array_map(fn ($r) => ['dimension' => $r->dimension] + $this->rate($r->searches, $total), $rows);
    }

    private function recovery(string $where, array $bind): array
    {
        $rows = DB::select("
            SELECT coalesce(q.recovery_mode, 'exact') AS mode, count(*) AS searches,
                   count(*) FILTER (WHERE q.zero_result) AS zero,
                   count(*) FILTER (WHERE EXISTS (SELECT 1 FROM search_interactions i WHERE i.search_query_id = q.id AND i.kind = 'impression')) AS tracked,
                   count(*) FILTER (WHERE EXISTS (SELECT 1 FROM search_interactions i WHERE i.search_query_id = q.id AND i.kind = 'click')) AS clicked
            FROM search_queries q WHERE {$where} GROUP BY 1 ORDER BY 2 DESC
        ", $bind);

        return array_map(fn ($r) => ['mode' => $r->mode, 'searches' => (int) $r->searches, 'zeroResult' => $this->rate($r->zero, $r->searches), 'ctr' => $this->rate($r->clicked, $r->tracked)], $rows);
    }

    private function engines(string $where, array $bind): array
    {
        $rows = DB::select("
            SELECT q.provider, count(*) AS searches, count(*) FILTER (WHERE q.degraded) AS degraded, count(*) FILTER (WHERE q.zero_result) AS zero,
                   percentile_cont(0.5) WITHIN GROUP (ORDER BY q.latency_ms) AS p50,
                   percentile_cont(0.95) WITHIN GROUP (ORDER BY q.latency_ms) AS p95
            FROM search_queries q WHERE {$where} GROUP BY q.provider ORDER BY 2 DESC
        ", $bind);

        return array_map(fn ($r) => [
            'provider' => $r->provider, 'searches' => (int) $r->searches,
            'degraded' => $this->rate($r->degraded, $r->searches), 'zeroResult' => $this->rate($r->zero, $r->searches),
            'p50' => $this->ms($r->p50), 'p95' => $this->ms($r->p95),
        ], $rows);
    }

    private function latency(string $where, array $bind): array
    {
        $rows = DB::select("
            SELECT CASE WHEN q.latency_ms < 5 THEN '<5' WHEN q.latency_ms < 10 THEN '5–10' WHEN q.latency_ms < 20 THEN '10–20'
                        WHEN q.latency_ms < 50 THEN '20–50' WHEN q.latency_ms < 100 THEN '50–100' ELSE '≥100' END AS bucket,
                   min(q.latency_ms) AS lo, count(*) AS searches
            FROM search_queries q WHERE {$where} AND q.latency_ms IS NOT NULL GROUP BY 1 ORDER BY 2
        ", $bind);

        return array_map(fn ($r) => ['bucketMs' => $r->bucket, 'searches' => (int) $r->searches], $rows);
    }

    private function ms(mixed $v): ?int
    {
        return $v === null ? null : (int) round((float) $v);
    }

    /** @param list<int> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
