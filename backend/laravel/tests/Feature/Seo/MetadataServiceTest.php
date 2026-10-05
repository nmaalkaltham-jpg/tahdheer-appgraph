<?php

namespace Tests\Feature\Seo;

use App\Domain\SEO\MetadataService;
use App\Domain\SEO\TextBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** MIP §7.6 — the description precedence chain and the two title budgets. */
class MetadataServiceTest extends TestCase
{
    use RefreshDatabase, SeedsSeoFixtures;

    private MetadataService $metadata;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metadata = app(MetadataService::class);
    }

    /**
     * The chain is authored seo_desc → quick_answer → short_desc → description.
     * Each step is proven by REMOVING the winner and re-asking, so the order is
     * verified rather than assumed.
     */
    public function test_description_precedence_chain_walks_in_the_exact_order(): void
    {
        $product = $this->makeProduct('TD-D1', 'منتج', [
            'short_desc' => 'وصف قصير',
            'description' => 'وصف طويل',
        ]);
        $seo = $this->makeSeoRow($product, [
            'seo_desc' => 'وصف سيو مُحرَّر',
            'quick_answer' => 'إجابة سريعة',
        ]);

        $this->assertSame('seoDesc', $this->metadata->description($product, $seo)['source']);
        $this->assertSame('وصف سيو مُحرَّر', $this->metadata->description($product, $seo)['text']);

        $seo->seo_desc = null;
        $this->assertSame('quickAnswer', $this->metadata->description($product, $seo)['source']);

        $seo->quick_answer = null;
        $this->assertSame('shortDesc', $this->metadata->description($product, $seo)['source']);

        $product->short_desc = null;
        $this->assertSame('description', $this->metadata->description($product, $seo)['source']);

        $product->description = null;
        $empty = $this->metadata->description($product, $seo);
        $this->assertNull($empty['text'], 'لا وصف حقيقي ⇒ غياب صريح لا نص مُختلَق');
        $this->assertNull($empty['source']);
    }

    public function test_whitespace_only_fields_do_not_win_the_chain(): void
    {
        $product = $this->makeProduct('TD-D2', 'منتج', ['short_desc' => 'وصف قصير حقيقي']);
        $seo = $this->makeSeoRow($product, ['seo_desc' => '   ', 'quick_answer' => "\n\t"]);

        // A blank authored field is an empty field, not a winning one.
        $this->assertSame('shortDesc', $this->metadata->description($product, $seo)['source']);
    }

    public function test_description_is_word_safe_trimmed_to_the_budget(): void
    {
        $long = str_repeat('كلمة ', 80);
        $product = $this->makeProduct('TD-D3', 'منتج', ['short_desc' => $long]);
        $this->makeSeoRow($product, []);

        $text = $this->metadata->description($product)['text'];

        $this->assertNotNull($text);
        $this->assertLessThanOrEqual(TextBudget::DESCRIPTION_BUDGET, mb_strlen($text));
        $this->assertStringEndsWith('…', $text);
        // Word-safe: the cut never leaves a half word before the ellipsis.
        $this->assertStringNotContainsString('كلم…', $text);
    }

    public function test_title_reports_the_editor_budget_and_the_rendered_alarm_separately(): void
    {
        // 62 chars: over the 60-char editor budget.
        $long = 'عنوان طويل جدا لاختبار ميزانية المحرر في نظام تحسين محركات البحث';
        $result = $this->metadata->title($long);

        $this->assertGreaterThan(TextBudget::TITLE_BUDGET, $result['titleLength']);
        $this->assertTrue($result['overEditorBudget'], 'تجاوز ميزانية المحرر ٦٠');
        $this->assertLessThanOrEqual(TextBudget::TITLE_BUDGET, mb_strlen($result['title']));

        // The brand suffix pushes the RENDERED title past the separate 68 alarm.
        $this->assertTrue($result['renderedTitleAlarm']);
        $this->assertStringEndsWith('التحاضير الحديثة', $result['renderedTitle']);

        $short = $this->metadata->title('عنوان قصير');
        $this->assertFalse($short['overEditorBudget']);
        $this->assertSame('عنوان قصير', $short['title']);
    }

    public function test_canonical_is_self_unless_an_override_exists(): void
    {
        $product = $this->makeProduct('TD-C1', 'منتج', []);
        $this->makeSeoRow($product, []);

        $meta = $this->metadata->forProduct($product);
        $this->assertTrue($meta['canonicalIsSelf']);
        $this->assertStringEndsWith('/products/td-c1', $meta['canonical']);

        $other = $this->makeProduct('TD-C2', 'منتج آخر', []);
        $this->makeSeoRow($other, ['canonical_override' => '/products/td-c1']);

        $overridden = $this->metadata->forProduct($other);
        $this->assertFalse($overridden['canonicalIsSelf']);
        $this->assertStringEndsWith('/products/td-c1', $overridden['canonical']);
    }

    public function test_robots_follows_the_indexation_matrix_via_the_shared_policy(): void
    {
        $published = $this->makeProduct('TD-R1', 'منشور', []);
        $draft = $this->makeProduct('TD-R2', 'مسودة', ['status' => 'draft']);

        $this->assertSame('index, follow', $this->metadata->forProduct($published)['robots']);
        $this->assertSame('noindex, follow', $this->metadata->forProduct($draft)['robots']);
        $this->assertSame(
            'IDX-UNPUBLISHED',
            $this->metadata->forProduct($draft)['indexability']['rule'],
        );
    }

    public function test_og_image_falls_back_through_the_chain_to_the_logo(): void
    {
        config(['app.url' => 'https://example.test']);
        $product = $this->makeProduct('TD-I1', 'منتج', []);

        $withoutMedia = $this->metadata->forProduct($product)['openGraph'];
        $this->assertSame('logo', $withoutMedia['imageSource']);

        DB::table('product_media')->insert([
            'product_id' => $product->id,
            'url' => '/media/cover.jpg',
            'is_cover' => true,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $withMedia = $this->metadata->forProduct($product)['openGraph'];
        $this->assertSame('cover', $withMedia['imageSource']);
        $this->assertSame('https://example.test/media/cover.jpg', $withMedia['image']);
    }
}
