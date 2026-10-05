<?php

namespace Tests\Feature\Search;

use App\Domain\Search\Models\SearchIndexState;
use App\Domain\Search\Providers\MeilisearchProvider;
use App\Domain\Search\SearchRequest;
use App\Models\Product;
use Database\Seeders\SynonymSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pure Http-facade client tests — no live Meilisearch is required or used;
 * every request is faked and its payload asserted.
 */
class MeilisearchProviderTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): MeilisearchProvider
    {
        return new MeilisearchProvider('http://meili.test', 'masterKey');
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => 'TD-9001', 'slug' => 'doc-product', 'title' => 'تحضير لغتي',
            'status' => 'published', 'published_at' => now(),
        ], $attributes));
    }

    public function test_settings_payload_follows_the_index_matrix(): void
    {
        $this->seed(SynonymSeeder::class);
        $settings = $this->provider()->settingsPayload();

        $this->assertSame('title', $settings['searchableAttributes'][0]);
        $this->assertSame('sku', $settings['searchableAttributes'][1]);
        $this->assertSame(['enabled' => true, 'disableOnAttributes' => ['sku']], $settings['typoTolerance']);
        $this->assertContains('conversion_score:desc', $settings['rankingRules']);
        $this->assertContains('format', $settings['filterableAttributes']);
        $this->assertContains('sales_count', $settings['sortableAttributes']);

        // An explicit sort (price, newest, bestselling) must BE the order, as in PostgreSQL:
        // placed after the relevance rules it only broke ties (measured by search:parity).
        $this->assertSame('sort', $settings['rankingRules'][0]);
        // «Newest» is when a product was published, not when it was last edited.
        $this->assertContains('published_at', $settings['sortableAttributes']);

        // Synonyms come from the seeded engine rows, legacy map verbatim.
        $this->assertSame(['تحاضير', 'اعداد', 'خطه'], $settings['synonyms']['تحضير']);
        $this->assertSame(['تحضير'], $settings['synonyms']['تحاضير']);
    }

    public function test_document_mapper_indexes_public_fields_only_and_never_invents_ratings(): void
    {
        $product = $this->makeProduct(['resource_type' => 'تحضير', 'format' => 'pdf', 'editable' => 'yes']);
        $product->price()->create(['regular_minor' => 1500, 'currency' => 'SAR']);

        $doc = $this->provider()->documentFor($product->fresh());

        $this->assertSame($product->id, $doc['id']);
        $this->assertSame('TD-9001', $doc['sku']);
        $this->assertSame('PDF', $doc['format']);
        $this->assertTrue($doc['editable']);
        $this->assertSame(1500, $doc['price_minor']);
        $this->assertFalse($doc['on_sale']);
        $this->assertSame(0, $doc['sales_count']);

        // No ratings without a real aggregate; no file references, ever.
        $this->assertArrayNotHasKey('rating_avg', $doc);
        $this->assertArrayNotHasKey('rating_count', $doc);
        $this->assertSame([], preg_grep('/file|url|key|license/i', array_keys($doc)) ?: []);

        DB::table('review_aggregates')->insert([
            'product_id' => $product->id, 'avg_rating' => 4.50, 'count' => 3,
            'distribution' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $withRatings = $this->provider()->documentFor($product->fresh());
        $this->assertSame(4.5, $withRatings['rating_avg']);
        $this->assertSame(3, $withRatings['rating_count']);
    }

    public function test_health_check_is_honest_about_engine_state(): void
    {
        Http::fake([
            'http://up.test/health' => Http::response(['status' => 'available']),
            'http://down.test/health' => Http::response([], 500),
        ]);

        $this->assertTrue((new MeilisearchProvider('http://up.test'))->healthy());
        $this->assertFalse((new MeilisearchProvider('http://down.test'))->healthy());
    }

    public function test_search_targets_the_generation_index_and_preserves_engine_order(): void
    {
        SearchIndexState::forEngine('meilisearch')->update(['index_name' => 'products_v2', 'generation' => 2]);

        $first = $this->makeProduct();
        $second = $this->makeProduct(['sku' => 'TD-9002', 'slug' => 'doc-2', 'title' => 'تحضير علوم']);

        Http::fake([
            'http://meili.test/indexes/products_v2/search' => Http::response([
                'hits' => [['id' => $second->id], ['id' => $first->id]],
                'totalHits' => 2,
                'facetDistribution' => ['format' => []],
            ]),
        ]);

        $request = new SearchRequest(
            query: 'تحضير',
            rawQuery: 'تحضير',
            filters: ['stage' => null, 'grade' => null, 'subject' => 'العلوم', 'semester' => null, 'kind' => null, 'format' => null],
            editable: true,
        );

        $result = $this->provider()->search($request);

        $this->assertSame([$second->id, $first->id], $result->items->pluck('id')->all());
        $this->assertSame(2, $result->total);

        Http::assertSent(function (Request $sent) {
            return str_contains($sent->url(), '/indexes/products_v2/search')
                && $sent['q'] === 'تحضير'
                && $sent['hitsPerPage'] === 24
                && in_array('subject = "العلوم"', $sent['filter'], true)
                && in_array('editable = true', $sent['filter'], true)
                && $sent->hasHeader('Authorization', 'Bearer masterKey');
        });
    }

    public function test_index_name_defaults_to_generation_zero_before_any_build(): void
    {
        $this->assertSame('products_v0', $this->provider()->indexName());
    }

    public function test_newest_sorts_by_publication_and_browsing_without_text_is_newest_first(): void
    {
        $product = $this->makeProduct(['published_at' => now()->subDays(3)]);
        $doc = $this->provider()->documentFor($product->fresh());
        $this->assertSame($product->published_at->getTimestamp(), $doc['published_at']);

        $sort = new \ReflectionMethod($this->provider(), 'sortExpression');
        $this->assertSame(['published_at:desc'], $sort->invoke($this->provider(), 'newest'));
    }
}
