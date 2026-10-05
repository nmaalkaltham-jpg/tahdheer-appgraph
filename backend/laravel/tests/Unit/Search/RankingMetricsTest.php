<?php

declare(strict_types=1);

namespace Tests\Unit\Search;

use App\Domain\Search\Evaluation\RankingMetrics as M;
use App\Domain\Search\Evaluation\RelevanceJudge;
use PHPUnit\Framework\TestCase;

class RankingMetricsTest extends TestCase
{
    public function test_precision_recall_and_reciprocal_rank_use_grade_two_as_relevant(): void
    {
        $grades = [0, 3, 1, 2, 0];

        $this->assertSame(0.0, M::precisionAt($grades, 1));
        $this->assertSame(2 / 5, M::precisionAt($grades, 5));
        $this->assertSame(1.0, M::recallAt($grades, 5, 2));
        $this->assertNull(M::recallAt($grades, 5, 0));
        $this->assertSame(0.5, M::reciprocalRankAt($grades, 10));
        $this->assertSame(0.0, M::reciprocalRankAt([1, 1], 10));
    }

    public function test_ndcg_is_one_for_the_ideal_order_and_null_without_positives(): void
    {
        $this->assertEqualsWithDelta(1.0, M::ndcgAt([3, 2, 1], [1, 3, 2], 10), 1e-9);
        $this->assertLessThan(1.0, M::ndcgAt([1, 2, 3], [1, 2, 3], 10));
        $this->assertNull(M::ndcgAt([0, 0], [], 10));
    }

    public function test_best_in_top_and_percentile(): void
    {
        $this->assertTrue(M::bestInTop([2, 3], 2, 3));
        $this->assertFalse(M::bestInTop([2, 3], 1, 3));
        $this->assertFalse(M::bestInTop([0], 1, 0));
        $this->assertSame(5.5, M::percentile([1, 10], 50));
        $this->assertNull(M::percentile([], 95));
    }

    public function test_the_judge_grades_from_intent_not_from_text(): void
    {
        $p = ['id' => 7, 'subject_id' => 4, 'grade_id' => 6, 'stage_id' => 1, 'semester_id' => 1, 'kind' => 'تحضير', 'format' => 'DOCX'];

        $this->assertSame(3, RelevanceJudge::grade(['subject_id' => 4, 'grade_id' => 6], $p));
        $this->assertSame(3, RelevanceJudge::grade(['product_id' => 7, 'subject_id' => 99], $p), 'the named product wins');
        $this->assertSame(2, RelevanceJudge::grade(['subject_id' => 4, 'grade_id' => 6, 'kind' => 'حلول'], $p), 'right subject+grade, other kind');
        $this->assertSame(1, RelevanceJudge::grade(['subject_id' => 4, 'grade_id' => 5], $p), 'right subject, wrong grade');
        $this->assertSame(1, RelevanceJudge::grade(['grade_id' => 6, 'kind' => 'حلول'], $p), 'right grade, other kind, no subject');
        $this->assertSame(0, RelevanceJudge::grade(['subject_id' => 5, 'grade_id' => 6], $p), 'wrong subject');
        $this->assertSame(0, RelevanceJudge::grade(['subject_id' => 4, 'stage_id' => 2], $p), 'wrong stage');
        $this->assertSame(0, RelevanceJudge::grade([], $p), 'nonsense has no relevant product');
    }
}
