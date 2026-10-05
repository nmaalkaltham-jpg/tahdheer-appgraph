<?php

namespace Tests\Feature\Search;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Arabic relevance evaluation corpus (MIP §6.6) — permanent regression suite:
 * exactness, typo suggestion honesty, mixed-script extraction, synonyms and
 * zero-result recovery, all over real-vocabulary fixtures.
 */
class RelevanceTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
        $this->seedCorpus();
    }

    public function test_exact_sku_query_returns_that_product_first(): void
    {
        $this->getJson('/api/v1/search?q=TD-3002')
            ->assertOk()
            ->assertJsonPath('results.items.0.sku', 'TD-3002')
            ->assertJsonPath('zeroResult', false);
    }

    public function test_exact_title_beats_partial_title(): void
    {
        $this->getJson('/api/v1/search?q='.rawurlencode('ملزمة مراجعة نهائية'))
            ->assertOk()
            ->assertJsonPath('results.total', 2)
            ->assertJsonPath('results.items.0.sku', 'TD-3001')
            ->assertJsonPath('results.items.1.sku', 'TD-3002');
    }

    /**
     * التصحيح الإملائيّ يُنقذ ويُعلن — ولا يتنكّر مطابقةً.
     *
     * كان هذا الاختبار يثبّت «اقتراحٌ بلا استبدال»: صفرُ نتائج وسطرُ «هل
     * تقصد». وقد صحّ يوم كان التصحيح خارج سلسلة التعافي. والمواصفة الجديدة
     * تجعل TYPO صنفًا من التعافي له نتائج، فتغيّر العقد عمدًا — لا ضعفًا:
     * الضمانات الأربع الباقية تُختبر كما كانت، ويُضاف إليها إعلانُ التصحيح
     * في `meta.relaxed` وعَلَمُ `applied`، فلا يرى الزائر نتائج كلمةٍ لم
     * يكتبها بلا سببٍ معلن.
     */
    public function test_typo_recovers_and_declares_the_correction(): void
    {
        $response = $this->getJson('/api/v1/search?q='.rawurlencode('تحظير'))->assertOk();

        // نصُّ الزائر يبقى كما كتبه — لا يُعاد كتابته في الاستجابة.
        $response->assertJsonPath('query.raw', 'تحظير')
            ->assertJsonPath('query.normalized', 'تحظير');

        // والنتائج تأتي من التصحيح، وتُعلَن تصحيحًا.
        $this->assertGreaterThan(0, $response->json('results.total'));
        $response->assertJsonPath('zeroResult', false);
        $this->assertContains('typo', $response->json('meta.relaxed'));
        $this->assertSame('تحضير', $response->json('query.correction.suggestion'));
        $this->assertTrue($response->json('query.correction.applied'));
    }

    /** كلمةٌ صحيحةٌ في الكتالوج لا تُصحَّح، ولا يُعلَن تصحيحٌ لم يقع (§٩). */
    public function test_a_valid_token_is_never_corrected(): void
    {
        $response = $this->getJson('/api/v1/search?q='.rawurlencode('تحضير'))->assertOk();

        $this->assertGreaterThan(0, $response->json('results.total'));
        $this->assertNotContains('typo', $response->json('meta.relaxed'));
        $this->assertSame([], $response->json('meta.relaxed'));
    }

    /** خطأٌ إملائيٌّ لا يقرب من أيّ مفردة يبقى صفرًا — لا تصحيحَ بالقوّة. */
    public function test_an_unrecoverable_typo_stays_zero(): void
    {
        $response = $this->getJson('/api/v1/search?q='.rawurlencode('زقنبوطية'))->assertOk();

        $response->assertJsonPath('results.total', 0)
            ->assertJsonPath('zeroResult', true);
        $this->assertNotContains('typo', $response->json('meta.relaxed'));
    }

    public function test_mixed_script_query_normalizes_and_extracts_the_grade(): void
    {
        $this->getJson('/api/v1/search?q='.rawurlencode('ملزمة math اول ثانوي'))
            ->assertOk()
            ->assertJsonPath('filtersExtracted.grade', 'أول-ثانوي')
            ->assertJsonPath('filtersExtracted.stage', 'ثانوي');
    }

    public function test_zero_result_logs_flag_and_returns_honest_recovery(): void
    {
        $response = $this->getJson('/api/v1/search?q='.rawurlencode('سيمفونية الكواكب'))->assertOk();

        $response->assertJsonPath('zeroResult', true);

        // popular is REAL aggregate data — empty before any successful search.
        $this->assertSame([], $response->json('recovery.popular'));

        // browse links come from the seeded stages/subjects, labels verbatim.
        $browse = $response->json('recovery.browse');
        $this->assertNotEmpty($browse);
        $this->assertContains(['label' => 'ابتدائي', 'url' => '/stages/ابتدائي'], $browse);

        $this->assertDatabaseHas('search_queries', [
            'raw_query' => 'سيمفونية الكواكب',
            'zero_result' => true,
        ]);
    }

    public function test_popular_recovery_uses_real_prior_queries_once_they_exist(): void
    {
        $this->getJson('/api/v1/search?q='.rawurlencode('ملزمة مراجعة نهائية'))->assertOk();

        $response = $this->getJson('/api/v1/search?q='.rawurlencode('سيمفونية الكواكب'))->assertOk();

        $this->assertSame(
            [['query' => 'ملزمه مراجعه نهاييه', 'count' => 1]],
            $response->json('recovery.popular'),
        );
    }

    public function test_synonym_pair_tahdeer_groups_both_spellings(): void
    {
        // «تحضير» and «تحاضير» are seeded vocabulary of the same kind: both
        // queries must reach both classified fixtures.
        foreach (['تحضير', 'تحاضير'] as $spelling) {
            $response = $this->getJson('/api/v1/search?q='.rawurlencode($spelling))->assertOk();
            $skus = collect($response->json('results.items'))->pluck('sku')->sort()->values()->all();
            $this->assertSame(['TD-1001', 'TD-1002'], $skus, "query: {$spelling}");
        }
    }

    public function test_synonym_engine_expands_lexical_tokens(): void
    {
        // Seeded legacy synonym عرض→بوربوينت: a query that stays lexical
        // («عرض» alone is no kind alias) must still reach «بوربوينت …» titles
        // whose words share nothing with the raw query but the synonym.
        $response = $this->getJson('/api/v1/search?q='.rawurlencode('عرض التجويد'))
            ->assertOk()
            ->assertJsonPath('zeroResult', false);

        $this->assertContains('TD-4001', collect($response->json('results.items'))->pluck('sku')->all());
    }

    public function test_filter_extraction_golden_query_matches_the_fully_specified_product(): void
    {
        $response = $this->getJson('/api/v1/search?q='.rawurlencode('عرض بوربوينت علوم سادس الفصل الأول قابل للتعديل'))
            ->assertOk();

        $response->assertJsonPath('filtersExtracted.kind', 'عرض بوربوينت')
            ->assertJsonPath('filtersExtracted.format', 'PPTX')
            ->assertJsonPath('filtersExtracted.subject', 'العلوم')
            ->assertJsonPath('filtersExtracted.grade', 'سادس-ابتدائي')
            ->assertJsonPath('filtersExtracted.semester', 'الفصل-الأول')
            ->assertJsonPath('filtersExtracted.stage', 'ابتدائي')
            ->assertJsonPath('results.total', 1)
            ->assertJsonPath('results.items.0.sku', 'TD-2001');
    }
}
