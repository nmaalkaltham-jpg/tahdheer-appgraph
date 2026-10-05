<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Search\Providers\PgFallbackProvider;
use App\Domain\Search\SearchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * الدقّة — سؤالٌ لا يخصّ المتجر لا يُجاب بمنتجاتٍ لمجرّد تشابهٍ حرفيّ.
 *
 * ثلاث حالاتٍ مقيسة على `search:eval` (فئة nonsense، ٣٠٪ إيجابًا كاذبًا):
 * كلمةٌ واحدة من ثلاث تطابق بادئةَ عنوان، وجزءٌ رقميّ قصير يطابق SKU،
 * وكلمةٌ عامّة تُصحَّح إلى كيانٍ بلا سياقٍ منهجيّ.
 */
class PrecisionGuardTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
        $this->seedCorpus();
        $this->makeProduct('TD-5001', 'أوراق عمل الرياضيات', 1500, ['format' => 'PDF']);
        $this->makeProduct('TD-1701', 'بطاقات مراجعة', 900, ['format' => 'PDF']);
    }

    private function search(string $q): TestResponse
    {
        return $this->getJson('/api/v1/search?q='.rawurlencode($q))->assertOk();
    }

    public function test_one_matching_word_out_of_three_is_not_a_match(): void
    {
        // «الرياض» is a prefix of «الرياضيات»; the other two words match nothing.
        $this->search('تذاكر طيران الرياض')->assertJsonPath('zeroResult', true);
    }

    public function test_a_short_number_left_after_recovery_does_not_match_a_sku(): void
    {
        // «17» sits inside TD-1701. Two characters are a fragment, not a SKU search.
        $this->search('17')->assertJsonPath('zeroResult', true);
        // Three or more still find it: people do type the numeric part of a SKU.
        $this->search('1701')->assertJsonPath('results.items.0.sku', 'TD-1701');
    }

    public function test_a_common_word_is_not_corrected_into_a_subject_without_curriculum_context(): void
    {
        // «رياضية» (sporty) is one edit from the PE alias «رياضة».
        $this->search('سيارات رياضية للبيع')
            ->assertJsonPath('zeroResult', true)
            ->assertJsonPath('filtersExtracted.subject', null);
    }

    public function test_real_multi_word_queries_still_match(): void
    {
        $skus = $this->search('ملزمة مراجعة')->json('results.items.*.sku');
        $this->assertContains('TD-3001', $skus);
        $this->assertContains('TD-3002', $skus);
        $this->search('مراجعة نهائية الوحدة')->assertJsonPath('results.items.0.sku', 'TD-3002');
        $this->search('TD-1001')->assertJsonPath('results.items.0.sku', 'TD-1001');
    }

    public function test_a_typo_next_to_a_curriculum_word_is_still_corrected(): void
    {
        $this->search('ريضايات اول ابتدائي')->assertJsonPath('filtersExtracted.subject', 'الرياضيات');
    }

    public function test_a_lenient_dimension_admits_the_unclassified_never_the_contradicting(): void
    {
        // TD-2001: classified sixth grade. TD-4001: no grade at all. Both say «بوربوينت».
        $request = new SearchRequest(
            query: 'بوربوينت',
            rawQuery: 'بوربوينت',
            filters: ['stage' => null, 'grade' => 'أول-ابتدائي', 'subject' => null, 'semester' => null, 'kind' => null, 'format' => null],
            lenient: ['grade'],
        );

        $skus = app(PgFallbackProvider::class)->search($request)->items->pluck('sku')->all();

        $this->assertContains('TD-4001', $skus);
        $this->assertNotContains('TD-2001', $skus);
    }

    public function test_matching_every_word_outranks_matching_half_in_a_stronger_field(): void
    {
        // Meilisearch's first ranking rule is «words»: more query words matched wins.
        $this->makeProduct('CV-1', 'ملزمة الأخلاق الإسلامية', 1000);
        $this->makeProduct('CV-2', 'ملزمة فنون', 1000, ['description' => 'تشرح الزخرفة الإسلامية خطوةً خطوة']);

        $this->search('الزخرفة الإسلامية')->assertJsonPath('results.items.0.sku', 'CV-2');
    }

    public function test_a_partial_match_must_keep_the_leading_word(): void
    {
        // Meilisearch drops words from the END ('last' strategy): a partial hit keeps the query's head.
        // «62 شريحة» must not return every product that merely says «شريحة» (search:parity).
        $this->makeProduct('LW-62', 'عرض 62 شريحة', 1000);
        $this->makeProduct('LW-40', 'عرض 40 شريحة', 1000);

        $skus = $this->search('62 شريحة')->json('results.items.*.sku');

        $this->assertSame(['LW-62'], $skus);
    }
}
