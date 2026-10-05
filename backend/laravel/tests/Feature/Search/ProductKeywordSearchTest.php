<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Search\Explain\ScoreProbe;
use App\Domain\Search\SearchRequest;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * كلماتُ المنتج المفتاحيّة (`product_seo.synonyms`) — يكتبها المشرف أو يعتمدها
 * من اقتراح الذكاء، ويجب أن يجد البحثُ المنتجَ بها.
 *
 * كان Meilisearch وحده يفهرسها؛ ومسار PostgreSQL — الافتراضيّ في التطوير
 * والإنتاج — يقرأ الرمز والعنوان والوصفين ونصّ الملفّ ولا يقرؤها. فكلمةٌ
 * كتبها المشرف لم تكن تُغيّر شيئًا في البحث الفعليّ.
 */
class ProductKeywordSearchTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
    }

    private function keywords(Product $product, string $keywords): void
    {
        DB::table('product_seo')->insert(['product_id' => $product->id, 'synonyms' => $keywords, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_keyword_finds_its_product(): void
    {
        $this->keywords($this->makeProduct('KW-1', 'ملزمة الفلك', 1500), 'أجرام سماوية، مجرات');

        $this->getJson('/api/v1/search?q='.rawurlencode('مجرات'))->assertOk()->assertJsonPath('results.items.0.sku', 'KW-1');
    }

    public function test_a_curated_keyword_ranks_below_the_title_and_above_the_description(): void
    {
        $this->makeProduct('KW-T', 'مجرات درب التبانة', 1500);
        $this->keywords($this->makeProduct('KW-K', 'ملزمة الفلك', 1500), 'مجرات');
        $this->makeProduct('KW-D', 'ملزمة العلوم', 1500, ['description' => 'فصلٌ عن المجرات']);

        $skus = array_column($this->getJson('/api/v1/search?q='.rawurlencode('مجرات'))->json('results.items'), 'sku');

        $this->assertSame(['KW-T', 'KW-K', 'KW-D'], $skus);
    }

    public function test_the_explanation_names_the_keyword_tier(): void
    {
        $product = $this->makeProduct('KW-2', 'ملزمة الفلك', 1500);
        $this->keywords($product, 'مجرات');

        $probe = app(ScoreProbe::class);
        $tier = $probe->tierFor($probe->score(Product::query()->with('seo')->findOrFail($product->id), new SearchRequest(query: 'مجرات', rawQuery: 'مجرات')));

        $this->assertSame('W_KEYWORD_TOKEN', $tier['name'] ?? null);
    }
}
