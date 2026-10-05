<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Search\Evaluation\GoldenQuerySet;
use App\Domain\Search\Evaluation\SearchEvaluator;
use App\Domain\Search\Evaluation\SyntheticCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بوابةُ جودة البحث — المجموعة الذهبية كاملةً على كتالوجٍ اصطناعيّ.
 *
 * لا تعتمد على منتجات المتجر: الكتالوج يُبنى من التصنيف، والأسئلة تُولَّد
 * منه بصيغ الناس (إملاء، عامّيّة، إنجليزيّة، اختصار، مؤنّث، أرقام هنديّة،
 * سؤالٌ مفرط التحديد، مخزونٌ ناقص، هراء). والحدود أدنى قليلًا ممّا قيس
 * عند كتابتها، فالبوابة تسقط بالانحدار لا بالضجيج.
 *
 * للتشخيص: `php artisan search:eval --synthetic`.
 */
class SearchQualityGateTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    public function test_search_quality_holds_on_the_generated_golden_set(): void
    {
        $this->seedSearchWorld();
        app(SyntheticCatalog::class)->build();

        $cases = app(GoldenQuerySet::class)->build();
        $report = app(SearchEvaluator::class)->evaluate($cases);
        $s = $report['summary'];

        $this->assertGreaterThanOrEqual(1000, $s['cases'], 'the golden set must stay large');

        // Measured at introduction (2026-09-25): top1 0.993 · top3 0.996 · ndcg@10 0.917 · zero 0 · wrong@5 0.003.
        $this->assertGreaterThanOrEqual(0.97, $s['top1'], 'Top-1 accuracy');
        $this->assertGreaterThanOrEqual(0.98, $s['top3'], 'Top-3 success');
        $this->assertGreaterThanOrEqual(0.88, $s['ndcg@10'], 'NDCG@10');
        $this->assertLessThanOrEqual(0.01, $s['zero'], 'zero-result rate on answerable queries');
        $this->assertLessThanOrEqual(0.01, $s['wrong@5'], 'wrong-subject/stage share of the top five');
        $this->assertSame(0.0, (float) $s['nonsense_false_positive'], 'nonsense must return nothing');
        $this->assertGreaterThanOrEqual(0.95, $s['missing_inventory_useful@5'], 'missing inventory still shows the subject');

        // The whole page (exact results, then «قريبٌ ممّا تبحث عنه»): the second tier must add, never contradict.
        $this->assertGreaterThan($s['ndcg@10'], $s['page_ndcg@10'], 'the related tier must raise page NDCG');
        $this->assertLessThanOrEqual(0.01, $s['page_wrong@10'], 'the related tier must not add wrong-subject or wrong-stage products');

        // The fragile families get their own floor, so a strong average cannot hide one collapsing.
        // Autocomplete understands what search understands (measured at introduction: partial 0.97 · full 1.00 on the dev catalog).
        $suggest = app(SearchEvaluator::class)->evaluateSuggest($cases)['summary'];
        $this->assertGreaterThanOrEqual(0.90, $suggest['partial@3'], 'autocomplete: product in top 3 while the last word is half typed');
        $this->assertGreaterThanOrEqual(0.98, $suggest['full@3'], 'autocomplete: product in top 3 for the whole query');
        $this->assertLessThanOrEqual(0.02, $suggest['partial_empty'], 'autocomplete: empty dropdowns');

        foreach (['content_only' => 0.95, 'typo' => 0.95, 'typo_holdout' => 0.95, 'english' => 0.95, 'mixed_language' => 0.95, 'colloquial_subject' => 0.95, 'abbreviation' => 0.95, 'over_specified' => 0.95] as $type => $floor) {
            $this->assertGreaterThanOrEqual($floor, $report['byType'][$type]['top3'] ?? 0, "top3 for {$type}");
        }
    }
}
