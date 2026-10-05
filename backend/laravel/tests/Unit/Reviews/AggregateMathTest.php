<?php

namespace Tests\Unit\Reviews;

use App\Domain\Reviews\AggregateRebuilder;
use PHPUnit\Framework\TestCase;

/**
 * The distribution bars are the most-read numbers on a product page, so the
 * arithmetic is pinned here as pure math — no database, no framework.
 */
class AggregateMathTest extends TestCase
{
    /**
     * MIP §4.7 golden case: the fabricated 4.9/212 figure the legacy theme
     * hard-coded, recomputed honestly from a real 190/16/4/1/1 spread.
     *
     * Naive rounding gives 90+8+2+0+0 → but only because 3★ rounds UP from
     * 1.88; floor-then-remainder is what guarantees the sum, not luck.
     */
    public function test_the_212_review_golden_distribution_sums_to_exactly_100(): void
    {
        $counts = [5 => 190, 4 => 16, 3 => 4, 2 => 1, 1 => 1];

        $pct = AggregateRebuilder::distribution($counts);

        // Exact shares: 89.62 · 7.55 · 1.89 · 0.47 · 0.47 → floors 89+7+1+0+0
        // = 97, so 3 points go to the largest remainders: 3★, 5★, 4★.
        $this->assertSame([5 => 90, 4 => 8, 3 => 2, 2 => 0, 1 => 0], $pct);
        $this->assertSame(100, array_sum($pct));

        // The average behind that spread: 1029/212 = 4.853… → 4.85 stored,
        // 4.9 at the one decimal the UI shows.
        // المتوسّط خلف هذا التوزيع: 1029/212 = 4.853… → «4.9» في الواجهة.
        $this->assertSame('4.9', number_format(round(1029 / 212, 2), 1));

        // «يوصي به» = 4★ and up over ALL reviews: 206/212.
    }

    /**
     * The property that matters more than any single case: whatever the
     * spread, the bars sum to 100. Naive round() fails this constantly.
     */
    public function test_percentages_always_sum_to_100_across_awkward_spreads(): void
    {
        $spreads = [
            [5 => 1, 4 => 1, 3 => 1, 2 => 0, 1 => 0],   // thirds: 33.33 ×3
            [5 => 1, 4 => 1, 3 => 1, 2 => 1, 1 => 1],   // fifths: exact 20 ×5
            [5 => 1, 4 => 1, 3 => 1, 2 => 1, 1 => 1 + 1], // sixths
            [5 => 2, 4 => 1, 3 => 0, 2 => 0, 1 => 0],   // 66.67 / 33.33
            [5 => 7, 4 => 0, 3 => 0, 2 => 0, 1 => 0],   // single bucket
            [5 => 3, 4 => 3, 3 => 3, 2 => 3, 1 => 2],   // near-uniform 14
        ];

        foreach ($spreads as $counts) {
            $pct = AggregateRebuilder::distribution($counts);
            $this->assertSame(100, array_sum($pct), 'spread '.json_encode($counts).' did not sum to 100');

            // A star nobody gave must read 0% — never a rounded-up crumb.
            foreach ($counts as $star => $n) {
                if ($n === 0) {
                    $this->assertSame(0, $pct[$star]);
                }
            }
        }
    }

    /** No reviews ⇒ all zeros, and NOT a 100 that implies data exists. */
    public function test_an_empty_product_has_no_percentages_at_all(): void
    {
        $pct = AggregateRebuilder::distribution([5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]);

        $this->assertSame([5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0], $pct);
        $this->assertSame(0, array_sum($pct));
    }

    /** Identical input must give identical bars — ties break deterministically. */
    public function test_tied_remainders_break_deterministically(): void
    {
        $counts = [5 => 1, 4 => 1, 3 => 1, 2 => 0, 1 => 0];

        $first = AggregateRebuilder::distribution($counts);
        $second = AggregateRebuilder::distribution($counts);

        $this->assertSame($first, $second);
        // 33.33 each, floors 33+33+33 = 99; the single leftover point goes to
        // the highest star among the tied remainders.
        $this->assertSame([5 => 34, 4 => 33, 3 => 33, 2 => 0, 1 => 0], $first);
    }

    /** The stored shape carries count AND percent, computed once, together. */
    public function test_bars_carry_the_raw_count_beside_the_percentage(): void
    {
        $bars = AggregateRebuilder::bars([5 => 190, 4 => 16, 3 => 4, 2 => 1, 1 => 1]);

        $this->assertSame(['count' => 190, 'pct' => 90], $bars[5]);
        $this->assertSame(['count' => 4, 'pct' => 2], $bars[3]);
        $this->assertSame(['count' => 1, 'pct' => 0], $bars[1]);
        $this->assertSame(212, array_sum(array_column($bars, 'count')));
        $this->assertSame(100, array_sum(array_column($bars, 'pct')));
    }
}
