<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Assets\Models\Asset;
use App\Domain\ContentIntelligence\ExtractionStatus;
use App\Domain\ContentIntelligence\Models\AssetExtraction;
use App\Domain\Search\ProductSearchProjector;
use App\Domain\Search\Providers\MeilisearchProvider;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الحلقة الأخيرة: مُنتَج ← فهمُ ملفّ ← ذكاءُ محتوى ← فهرسُ بحث.
 *
 * خطّ الاستخراج كان يُنتج نصًّا حقيقيًّا والبحث لا يراه: كلمةٌ لا ترد إلّا
 * في الملفّ تُعيد صفرًا. هذه الحزمة تحرس ثلاثة أشياء معًا — أنّ النصّ صار
 * يُطابَق عليه، وأنّه **أدنى** طبقةٍ فلا يزيح عنوانًا ولا وصفًا، وأنّ
 * `ok` وحدها تدخل.
 */
class ExtractedContentSearchTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    /** كلمةٌ لا ترد في أيّ عنوانٍ ولا وصفٍ في الحزمة — كما «معاينة» في قاعدة التطوير. */
    private const ONLY_IN_FILE = 'الأسطرلاب';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
    }

    public function test_a_word_that_lives_only_in_an_ok_extraction_becomes_findable(): void
    {
        $this->makeProduct('EX-1000', 'ملزمة الوحدة الأولى', 1500);
        $this->productWithExtraction('EX-1001', 'ملزمة الوحدة الثانية', ExtractionStatus::Ok, 'صفحة غلاف '.self::ONLY_IN_FILE.' معاينة');

        $this->getJson('/api/v1/search?q='.rawurlencode(self::ONLY_IN_FILE))
            ->assertOk()
            ->assertJsonPath('results.total', 1)
            ->assertJsonPath('results.items.0.sku', 'EX-1001')
            ->assertJsonPath('zeroResult', false);
    }

    /** §٧ — استخراجٌ سيّئ لا يُسمّم البحث: الناقص والفاشل لا يُطابَق عليهما. */
    public function test_partial_failed_and_capability_gap_extractions_contribute_nothing(): void
    {
        $this->productWithExtraction('EX-2001', 'ملزمة مراجعة', ExtractionStatus::Partial, 'نصٌّ ناقص '.self::ONLY_IN_FILE);
        $this->productWithExtraction('EX-2002', 'ملزمة تدريبات', ExtractionStatus::Failed, 'نصٌّ فاشل '.self::ONLY_IN_FILE);
        $this->productWithExtraction('EX-2003', 'ملزمة أنشطة', ExtractionStatus::CapabilityGap, 'نصُّ عجز '.self::ONLY_IN_FILE);

        $this->getJson('/api/v1/search?q='.rawurlencode(self::ONLY_IN_FILE))
            ->assertOk()
            ->assertJsonPath('results.total', 0)
            ->assertJsonPath('zeroResult', true);
    }

    /**
     * §٢٣ — مطابقةُ الملفّ أدنى الجميع: لا تسبق عنوانًا ولا جسدًا ولا وصفًا.
     *
     * أربعةُ منتجاتٍ تحمل الكلمة نفسها في أربعة مواضع، والترتيب المنتظَر هو
     * ترتيب المصدر لا ترتيب الإنشاء.
     */
    public function test_a_file_match_never_outranks_title_body_or_description(): void
    {
        $this->productWithExtraction('EX-3004', 'ملزمة أنشطة صفّيّة', ExtractionStatus::Ok, 'غلاف معاينة '.self::ONLY_IN_FILE);
        $this->makeProduct('EX-3003', 'ملزمة تدريبات', 1500, ['description' => 'كتابٌ يشرح '.self::ONLY_IN_FILE.' بالتفصيل']);
        $this->makeProduct('EX-3002', 'ملزمة مراجعة', 1500, ['short_desc' => 'ملخّصٌ عن '.self::ONLY_IN_FILE]);
        $this->makeProduct('EX-3001', 'ملزمة '.self::ONLY_IN_FILE.' الكبرى', 1500);

        $response = $this->getJson('/api/v1/search?q='.rawurlencode(self::ONLY_IN_FILE))->assertOk();

        $this->assertSame(
            ['EX-3001', 'EX-3002', 'EX-3003', 'EX-3004'],
            array_column($response->json('results.items'), 'sku')
        );
    }

    /**
     * الرتابة: الطبقة الجديدة تُضيف ولا تُزحزح.
     *
     * المرجع هو ترتيبُ المنتجات المسجِّلة قبل وجود أيّ استخراج؛ ثمّ يُضاف
     * منتجٌ لا يُطابَق إلّا بملفّه فيلحق بالذيل، والرأس كما هو حرفًا بحرف.
     */
    public function test_adding_the_file_tier_does_not_reorder_products_that_already_scored(): void
    {
        $this->makeProduct('EX-4001', 'ملزمة '.self::ONLY_IN_FILE.' الكبرى', 1500);
        $this->makeProduct('EX-4002', 'ملزمة مراجعة', 1500, ['description' => 'يشرح '.self::ONLY_IN_FILE]);

        $before = array_column(
            $this->getJson('/api/v1/search?q='.rawurlencode(self::ONLY_IN_FILE))->assertOk()->json('results.items'),
            'sku'
        );

        $this->productWithExtraction('EX-4003', 'ملزمة أنشطة', ExtractionStatus::Ok, 'غلاف '.self::ONLY_IN_FILE);

        $after = array_column(
            $this->getJson('/api/v1/search?q='.rawurlencode(self::ONLY_IN_FILE))->assertOk()->json('results.items'),
            'sku'
        );

        $this->assertSame($before, array_slice($after, 0, count($before)));
        $this->assertSame(['EX-4003'], array_slice($after, count($before)));
    }

    /**
     * §٥٨ §١٢٦ — يُطابَق عليه ولا يُعرض.
     *
     * محتوى ملفٍّ مدفوع مكشوفًا يُلغي الحاجة إلى شرائه. فالكلمة التي طابقت
     * تعود في `query.raw` لأنّ الزائر كتبها، وما جاورها في الملفّ لا يعود.
     */
    public function test_the_public_response_never_echoes_file_content(): void
    {
        $this->productWithExtraction('EX-5001', 'ملزمة الوحدة الأولى', ExtractionStatus::Ok, self::ONLY_IN_FILE.' طلسم-مدفوع-١٩');

        $response = $this->getJson('/api/v1/search?q='.rawurlencode(self::ONLY_IN_FILE))->assertOk();

        $response->assertJsonPath('results.items.0.sku', 'EX-5001');
        $this->assertStringNotContainsString('طلسم-مدفوع-١٩', $response->getContent());
    }

    /** الوثيقة القانونيّة تحمل نصّ `ok` وحده، ومن كلّ (أصل · مستخرِج) أحدثَ نسخة. */
    public function test_the_canonical_document_carries_ok_text_only_and_the_newest_version(): void
    {
        $product = $this->productWithExtraction('EX-6001', 'ملزمة الوحدة الأولى', ExtractionStatus::Ok, 'نصٌّ-قديم', version: '1');
        $asset = Asset::query()->orderByDesc('id')->firstOrFail();
        $this->extractionFor($asset, ExtractionStatus::Ok, 'نصٌّ-جديد', version: '2');
        $this->attachAsset($product, $this->asset('EX-6001-b'), position: 1);
        $this->extractionFor(Asset::query()->orderByDesc('id')->firstOrFail(), ExtractionStatus::Failed, 'نصٌّ-فاشل');

        $document = $this->app->make(ProductSearchProjector::class)->forProduct($product->refresh());

        $this->assertStringContainsString('نصٌّ-جديد', (string) $document->extractedText);
        $this->assertStringNotContainsString('نصٌّ-قديم', (string) $document->extractedText);
        $this->assertStringNotContainsString('نصٌّ-فاشل', (string) $document->extractedText);
        $this->assertSame($document->extractedText, $document->toArray()['extracted_content']);
    }

    /** منتجٌ بلا استخراج: لا مفتاح في الوثيقة — لا سلسلةٌ فارغة تُفهرَس. */
    public function test_a_product_without_extractions_carries_no_key(): void
    {
        $product = $this->makeProduct('EX-7001', 'ملزمة بلا ملفّات', 1500);

        $document = $this->app->make(ProductSearchProjector::class)->forProduct($product);

        $this->assertNull($document->extractedText);
        $this->assertArrayNotHasKey('extracted_content', $document->toArray());
    }

    /** النصّ يُقصّ عند السقف: وثيقةٌ لا تتبع حجمَ أكبر ملفٍّ رفعه ناشر. */
    public function test_extracted_text_is_capped(): void
    {
        $product = $this->productWithExtraction('EX-8001', 'ملزمة طويلة', ExtractionStatus::Ok, str_repeat('ا', ProductSearchProjector::EXTRACTED_CHARS_CAP + 500));

        $document = $this->app->make(ProductSearchProjector::class)->forProduct($product);

        $this->assertSame(ProductSearchProjector::EXTRACTED_CHARS_CAP, mb_strlen((string) $document->extractedText));
    }

    /** المزوّدان يتّفقان فيما يُعثَر عليه: المحتوى المستخرَج آخرُ قائمة الوزن. */
    public function test_the_engine_weights_extracted_content_last(): void
    {
        $searchable = (new MeilisearchProvider('http://127.0.0.1:7700', 'k'))->settingsPayload()['searchableAttributes'];

        $this->assertSame('extracted_content', $searchable[array_key_last($searchable)]);
        $this->assertGreaterThan(
            array_search('description', $searchable, true),
            array_search('extracted_content', $searchable, true)
        );
    }

    private function productWithExtraction(string $sku, string $title, ExtractionStatus $status, string $text, string $version = '1'): Product
    {
        $product = $this->makeProduct($sku, $title, 1500);
        $asset = $this->asset($sku);
        $this->attachAsset($product, $asset);
        $this->extractionFor($asset, $status, $text, $version);

        return $product;
    }

    private function asset(string $seed): Asset
    {
        $sha = hash('sha256', $seed);

        return Asset::query()->create([
            'provider' => 'local',
            'provider_ref' => 'assets/'.substr($sha, 0, 2).'/'.$sha,
            'original_name' => $seed.'.svg',
            'sha256' => $sha,
            'size_bytes' => 1024,
            'mime' => 'image/svg+xml',
            'visibility' => 'paid_private',
            'usage_count' => 1,
        ]);
    }

    private function attachAsset(Product $product, Asset $asset, int $position = 0): void
    {
        DB::table('product_files')->insert([
            'product_id' => $product->id,
            'asset_id' => $asset->id,
            'role' => 'main-'.$position,
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function extractionFor(Asset $asset, ExtractionStatus $status, string $text, string $version = '1'): void
    {
        AssetExtraction::query()->create([
            'asset_id' => $asset->id,
            'asset_sha256' => $asset->sha256,
            'extractor' => 'svg_native_text',
            'extractor_version' => $version,
            'status' => $status->value,
            'text' => $text,
            'units' => 1,
            'duration_ms' => 1,
            'bytes_read' => 1024,
        ]);
    }
}
