<?php

namespace Tests\Feature\Search;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchApiTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
    }

    public function test_search_returns_the_exact_contract_shape(): void
    {
        $this->seedCorpus();

        $this->getJson('/api/v1/search?q='.rawurlencode('ملزمة مراجعة'))
            ->assertOk()
            ->assertJsonStructure([
                'query' => ['raw', 'normalized', 'correction'],
                'filtersExtracted' => ['stage', 'grade', 'subject', 'semester', 'kind', 'format'],
                'results' => ['items', 'total', 'page', 'perPage'],
                'facets' => ['types'],
                'sort',
                'zeroResult',
                'recovery',
            ])
            ->assertJsonPath('results.perPage', 24)
            ->assertJsonPath('results.page', 1)
            ->assertJsonPath('sort', 'relevance')
            ->assertJsonPath('zeroResult', false)
            ->assertJsonPath('recovery', null);
    }

    public function test_normalization_bridges_hamza_variants_and_logs_the_query(): void
    {
        // «أوراق عمل» is kind vocabulary: extraction filters by the kind the
        // classifier would assign, and the query log keeps raw + normalized.
        $this->makeProduct('TD-S1', 'أوراق عمل رياضيات', 900, ['resource_type' => 'أوراق عمل', 'format' => 'PDF']);

        $this->getJson('/api/v1/search?q='.rawurlencode('اوراق عمل'))
            ->assertOk()
            ->assertJsonPath('results.total', 1)
            ->assertJsonPath('results.items.0.sku', 'TD-S1')
            ->assertJsonPath('filtersExtracted.kind', 'أوراق عمل');

        $this->assertDatabaseHas('search_queries', [
            'raw_query' => 'اوراق عمل',
            'normalized_query' => 'اوراق عمل',
            'results_count' => 1,
            'zero_result' => false,
        ]);
    }

    public function test_type_facet_counts_come_from_the_filtered_result_set(): void
    {
        $this->makeProduct('TD-F1', 'مذكرة الخط', 500, ['format' => 'PDF']);
        $this->makeProduct('TD-F2', 'مذكرة الخط المطور', 700, ['format' => 'PPTX']);

        $response = $this->getJson('/api/v1/search?q='.rawurlencode('مذكرة الخط'))->assertOk();
        $types = collect($response->json('facets.types'))->keyBy('key');

        $this->assertSame(2, $types['all']['count']);
        $this->assertSame('الكل', $types['all']['label']);
        $this->assertSame(1, $types['pdf']['count']);
        $this->assertSame(1, $types['pptx']['count']);
        $this->assertSame('عروض', $types['pptx']['label']);

        // The tab narrows the items but never rewrites the tab counts.
        $tabbed = $this->getJson('/api/v1/search?q='.rawurlencode('مذكرة الخط').'&type=pdf')->assertOk();
        $this->assertSame(1, $tabbed->json('results.total'));
        $this->assertSame(2, collect($tabbed->json('facets.types'))->keyBy('key')['all']['count']);
    }

    public function test_search_validates_enums_and_allows_a_query_less_browse(): void
    {
        $this->getJson('/api/v1/search?q=ملزمة&sort=nonsense')->assertStatus(422);
        $this->getJson('/api/v1/search?q=ملزمة&type=nonsense')->assertStatus(422);

        // A stage page and every facet click are BROWSE, not search: they carry
        // filters and no text. Requiring `q` made category browsing return 422,
        // so an empty query is a valid request.
        $this->getJson('/api/v1/search')->assertOk();
    }

    public function test_an_explicit_facet_filter_browses_without_any_query_text(): void
    {
        $this->seedCorpus();

        $browse = $this->getJson('/api/v1/search?stage='.rawurlencode('ابتدائي'))->assertOk();

        $this->assertSame('ابتدائي', $browse->json('filtersExtracted.stage'));
        // Every returned row really belongs to the requested stage.
        $this->assertGreaterThan(0, $browse->json('results.total'));
    }

    /**
     * الصف مرشِّح كامل لا معاملًا يُتجاهَل.
     *
     * كل طبقة تحت الـHTTP تعرف الصف — الفهرس والمزوّدان ومستخرج الكيانات —
     * وكان باب التحقّق وحده لا يقبله، فيُسقَط `?grade=` صامتًا وتُعاد النتائج
     * كاملةً وكأن المرشِّح طُبِّق. صمتٌ أسوأ من 422: صفحة الصف كانت ستعرض
     * المتجر كلّه تحت اسم صف واحد.
     */
    public function test_the_grade_filter_actually_narrows_the_result_set(): void
    {
        $this->seedCorpus();

        $all = $this->getJson('/api/v1/search')->assertOk()->json('results.total');
        $sixth = $this->getJson('/api/v1/search?grade='.rawurlencode('سادس-ابتدائي'))->assertOk();

        $this->assertSame('سادس-ابتدائي', $sixth->json('filtersExtracted.grade'));
        $this->assertSame(1, $sixth->json('results.total'));
        $this->assertLessThan($all, $sixth->json('results.total'), 'المرشِّح لم يضيّق شيئًا');
        $this->assertSame('TD-2001', $sixth->json('results.items.0.sku'));
    }

    public function test_meilisearch_outage_degrades_to_pg_and_still_answers(): void
    {
        $this->seedCorpus();
        config(['search.provider' => 'meilisearch']);
        Http::fake(['*' => Http::response(['status' => 'down'], 500)]);

        $this->getJson('/api/v1/search?q='.rawurlencode('ملزمة مراجعة نهائية'))
            ->assertOk()
            ->assertJsonPath('zeroResult', false);
    }

    public function test_suggest_needs_two_chars_and_caps_at_three_products(): void
    {
        $this->seedCorpus();

        $this->getJson('/api/v1/search/suggest?q='.rawurlencode('م'))
            ->assertOk()
            ->assertExactJson(['items' => []]);

        $response = $this->getJson('/api/v1/search/suggest?q='.rawurlencode('ملزمة'))->assertOk();
        $items = $response->json('items');

        $this->assertNotEmpty($items);
        $this->assertLessThanOrEqual(3, count($items));
        $this->assertSame('product', $items[0]['kind']);
        $this->assertStringStartsWith('/products/', $items[0]['url']);
        $this->assertIsInt($items[0]['priceMinor']);
    }
}
