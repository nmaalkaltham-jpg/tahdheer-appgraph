<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Grade;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * الطبقة الثانية من النتائج — «قريبٌ ممّا تبحث عنه».
 *
 * قيس على `search:eval`: الترتيب لم يُخطئ مرّةً في ١٤٠٤ أسئلة، وكلّ خسارة
 * NDCG كانت منتجاتٍ ذات صلة تُسقطها المرشِّحات الصلبة — «رياضيات سادس»
 * تُعيد منتجًا واحدًا والمادّة نفسها موجودة للأوّل والثالث. فتُعرض بعد
 * النتائج المطابقة، مجموعةً مُسمّاةً بسبب قربها، ولا تختلط بها.
 */
class RelatedResultsTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();

        $at = fn (string $subject, string $grade, string $kind): array => [
            'resource_type' => $kind,
            'format' => 'PDF',
            'subject_id' => Subject::query()->where('slug', $subject)->value('id'),
            'grade_id' => Grade::query()->where('slug', $grade)->value('id'),
            'stage_id' => Stage::query()->where('slug', 'ابتدائي')->value('id'),
        ];

        $this->makeProduct('R-M6', 'تحضير الرياضيات السادس', 1000, $at('الرياضيات', 'سادس-ابتدائي', 'تحضير'));
        $this->makeProduct('R-M6W', 'أوراق عمل الرياضيات السادس', 1000, $at('الرياضيات', 'سادس-ابتدائي', 'أوراق عمل'));
        $this->makeProduct('R-M1', 'تحضير الرياضيات الأول', 1000, $at('الرياضيات', 'أول-ابتدائي', 'تحضير'));
        $this->makeProduct('R-M3', 'حلول الرياضيات الثالث', 1000, $at('الرياضيات', 'ثالث-ابتدائي', 'حلول'));
        $this->makeProduct('R-S6', 'تحضير العلوم السادس', 1000, $at('العلوم', 'سادس-ابتدائي', 'تحضير'));
    }

    private function search(string $q): TestResponse
    {
        return $this->getJson('/api/v1/search?q='.rawurlencode($q))->assertOk();
    }

    /** @return array<string, list<string>> reason ⇒ skus */
    private function relatedGroups(TestResponse $r): array
    {
        return collect($r->json('related.groups') ?? [])
            ->mapWithKeys(fn (array $g): array => [$g['reason'] => array_column($g['items'], 'sku')])
            ->all();
    }

    public function test_exact_matches_stay_first_and_the_same_subject_follows_by_closeness(): void
    {
        $r = $this->search('تحضير رياضيات سادس ابتدائي');

        $this->assertSame(['R-M6'], $r->json('results.items.*.sku'));

        $groups = $this->relatedGroups($r);
        // Closest first: same subject and grade, another kind; then the same subject in other grades.
        $this->assertSame(['kind', 'grade'], array_keys($groups));
        $this->assertSame(['R-M6W'], $groups['kind']);
        $this->assertEqualsCanonicalizing(['R-M1', 'R-M3'], $groups['grade']);
    }

    public function test_related_never_repeats_a_result_nor_leaves_the_subject(): void
    {
        $r = $this->search('رياضيات سادس ابتدائي');
        $primary = $r->json('results.items.*.sku');
        $related = array_merge(...array_values($this->relatedGroups($r)) ?: [[]]);

        $this->assertSame([], array_intersect($primary, $related));
        $this->assertNotContains('R-S6', $related);
        $this->assertNotEmpty($r->json('related.groups.0.label'));
    }

    public function test_no_related_tier_without_a_structured_intent_or_on_later_pages(): void
    {
        $this->assertSame([], $this->search('تحضير')->json('related.groups'));
        $this->assertSame([], $this->getJson('/api/v1/search?q='.rawurlencode('تحضير رياضيات سادس ابتدائي').'&page=2')->json('related.groups'));
    }

    public function test_a_relaxed_answer_gets_no_second_tier(): void
    {
        // No fifth-grade math: recovery already widened the answer, so nothing is layered on top.
        $r = $this->search('تحضير رياضيات خامس ابتدائي');

        $this->assertNotSame([], $r->json('meta.relaxed'));
        $this->assertSame([], $r->json('related.groups'));
    }

    public function test_dropping_a_filler_word_does_not_suppress_the_second_tier(): void
    {
        // «ابي» is dropped as an unknown word; the structured intent is untouched.
        $r = $this->search('ابي تحضير رياضيات سادس ابتدائي');

        $this->assertSame(['R-M6'], $r->json('results.items.*.sku'));
        $this->assertArrayHasKey('grade', $this->relatedGroups($r));
    }

    public function test_the_second_tier_never_leaves_the_stage(): void
    {
        $this->makeProduct('R-MS', 'تحضير الرياضيات أول متوسط', 1000, [
            'resource_type' => 'تحضير', 'format' => 'PDF',
            'subject_id' => Subject::query()->where('slug', 'الرياضيات')->value('id'),
            'grade_id' => Grade::query()->where('slug', 'أول-متوسط')->value('id'),
            'stage_id' => Stage::query()->where('slug', 'متوسط')->value('id'),
        ]);

        $related = array_merge(...array_values($this->relatedGroups($this->search('تحضير رياضيات سادس ابتدائي'))));

        $this->assertNotContains('R-MS', $related);
    }
}
