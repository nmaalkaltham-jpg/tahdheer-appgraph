<?php

namespace App\Domain\Reviews;

use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewAggregate;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the materialized per-product rating summary from APPROVED rows
 * only. Idempotent by construction — it reads the current truth and overwrites
 * the row, so an outbox retry produces the identical result.
 *
 * Percentages use the largest-remainder (Hare quota) method (MIP §4.7): floor
 * every share, then hand the leftover points to the largest fractional parts.
 * Naive per-bucket rounding produces 89+8+2+0+0 = 99 or 101 and the bars
 * visibly lie; largest remainder sums to exactly 100, always.
 */
class AggregateRebuilder
{
    /** Stars, high to low — the display order of the distribution bars. */
    public const STARS = [5, 4, 3, 2, 1];

    /** «يوصي به» = 4 stars and up, over ALL approved reviews. */
    public const RECOMMEND_MIN_STARS = 4;

    /**
     * Rebuild one product's aggregate. Returns the stored row, or null when
     * the product has no approved reviews — absence is the honest state, so
     * the row is deleted rather than written as zeros.
     */
    public function rebuild(int $productId): ?ReviewAggregate
    {
        $counts = $this->approvedCounts($productId);
        $total = array_sum($counts);

        if ($total === 0) {
            ReviewAggregate::query()->where('product_id', $productId)->delete();

            return null;
        }

        $weighted = 0;
        $recommended = 0;
        foreach ($counts as $star => $n) {
            $weighted += $star * $n;
            if ($star >= self::RECOMMEND_MIN_STARS) {
                $recommended += $n;
            }
        }

        $aggregate = ReviewAggregate::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'avg_rating' => round($weighted / $total, 2),
                'count' => $total,
                'distribution' => self::bars($counts),
                'recommend_pct' => (int) round($recommended * 100 / $total),
            ],
        );

        return $aggregate->refresh();
    }

    /** Rebuild every product that has at least one review. @return int rows touched */
    public function rebuildAll(): int
    {
        $productIds = Review::query()->distinct()->pluck('product_id');

        foreach ($productIds as $productId) {
            $this->rebuild((int) $productId);
        }

        return $productIds->count();
    }

    /**
     * The stored bar set: raw count AND percentage per star, so the section
     * renders «١٩٠ تقييمًا» and a 90% bar from ONE recomputation — the label
     * and the bar can never disagree.
     *
     * @param  array<int, int>  $counts  star => count
     * @return array<int, array{count: int, pct: int}>
     */
    public static function bars(array $counts): array
    {
        $pct = self::distribution($counts);

        $bars = [];
        foreach (self::STARS as $star) {
            $bars[$star] = ['count' => $counts[$star] ?? 0, 'pct' => $pct[$star]];
        }

        return $bars;
    }

    /**
     * Largest-remainder percentages that sum to EXACTLY 100.
     *
     * Ties on the fractional part are broken by star value descending, so the
     * output is deterministic for identical input — a rebuild never reshuffles
     * bars that no reviewer moved.
     *
     * @param  array<int, int>  $counts  star => count
     * @return array<int, int> star => percent (every star present, 5→1)
     */
    public static function distribution(array $counts): array
    {
        $total = array_sum($counts);
        $percent = [];

        if ($total === 0) {
            foreach (self::STARS as $star) {
                $percent[$star] = 0;
            }

            return $percent;
        }

        $remainders = [];
        $allocated = 0;

        foreach (self::STARS as $star) {
            $exact = ($counts[$star] ?? 0) * 100 / $total;
            $floor = (int) floor($exact);
            $percent[$star] = $floor;
            $remainders[$star] = $exact - $floor;
            $allocated += $floor;
        }

        // Hand out the leftover points, largest fraction first.
        $order = self::STARS;
        usort($order, fn (int $a, int $b) => $remainders[$b] <=> $remainders[$a] ?: $b <=> $a);

        for ($i = 0; $i < 100 - $allocated; $i++) {
            $percent[$order[$i % count($order)]]++;
        }

        return $percent;
    }

    /**
     * Approved counts per star, every bucket present (missing star = 0).
     *
     * @return array<int, int>
     */
    private function approvedCounts(int $productId): array
    {
        $rows = DB::table('reviews')
            ->where('product_id', $productId)
            ->where('status', Review::APPROVED)
            ->groupBy('rating')
            ->pluck(DB::raw('count(*) as c'), 'rating');

        $counts = [];
        foreach (self::STARS as $star) {
            $counts[$star] = (int) ($rows[$star] ?? 0);
        }

        return $counts;
    }
}
