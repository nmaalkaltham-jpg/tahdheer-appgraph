<?php

declare(strict_types=1);

namespace Tests\Feature\ContentIntelligence;

use App\Domain\Assets\Models\Asset;
use App\Domain\ContentIntelligence\AssetExtractionService;
use App\Domain\ContentIntelligence\ExtractorRegistry;
use App\Domain\ContentIntelligence\Extractors\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Search\SeedsSearchFixtures;
use Tests\TestCase;

/**
 * نصّ صفحات PDF عبر poppler (`pdftotext`) — ولا شيءَ يتغيّر حيث لا يوجد.
 *
 * الأداة تُثبَّت في صورة الحاوية (backend/laravel/Dockerfile). الاختبار لا
 * يفترض وجودها: `Process::fake` يمثّل مخرجاتها، ومسارُها يُضبط إلى ملفٍّ
 * تنفيذيٍّ موجود. فيُثبت هنا التوصيل كلّه: النداء، والصفحات، والفشل، والفراغ.
 */
class PdfTextExtractionTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('assets');
        config(['content.pdftotext' => '/bin/sh']);
    }

    /** PDF صغيرٌ حقيقيّ البنية بعنوانٍ عربيّ في قاموس Info (UTF-16BE). */
    private function pdf(): string
    {
        $title = strtoupper(bin2hex("\xFE\xFF".mb_convert_encoding('ملزمة الكيمياء', 'UTF-16BE', 'UTF-8')));

        return "%PDF-1.4\n1 0 obj << /Title <{$title}> >> endobj\ntrailer << /Info 1 0 R >>\n%%EOF\n";
    }

    private function extract(): object
    {
        $bytes = $this->pdf();
        $sha = hash('sha256', $bytes.microtime());
        $key = 'assets/'.substr($sha, 0, 2).'/'.$sha;
        Storage::disk('assets')->put($key, $bytes);
        $asset = Asset::query()->create([
            'provider' => 'local', 'provider_ref' => $key, 'original_name' => 'file.pdf', 'sha256' => hash('sha256', $bytes),
            'size_bytes' => strlen($bytes), 'mime' => 'application/pdf', 'visibility' => 'paid_private', 'usage_count' => 0,
        ]);
        $this->lastAsset = $asset;

        return $this->app->make(AssetExtractionService::class)->extract($asset)->row;
    }

    private ?Asset $lastAsset = null;

    public function test_page_text_is_read_page_by_page_after_the_document_metadata(): void
    {
        Process::fake(['*' => Process::result("الروابط التساهمية\nالصفحة الأولى\f\fتمارين الوحدة\f")]);

        $row = $this->extract();

        $this->assertSame('ok', $row->status);
        $this->assertSame(PdfTextExtractor::NAME, $row->extractor);
        $this->assertSame(3, (int) $row->units, 'three pages, one of them blank');
        $this->assertSame(['ملزمة الكيمياء', 'الروابط التساهمية', 'الصفحة الأولى', 'تمارين الوحدة'], explode("\n", (string) $row->text));
        Process::assertRan(fn (PendingProcess $p): bool => is_array($p->command) && $p->command[0] === '/bin/sh' && in_array('UTF-8', $p->command, true));
    }

    public function test_a_scanned_pdf_with_no_text_layer_is_an_ocr_gap(): void
    {
        Process::fake(['*' => Process::result("\f\f\f")]);

        $row = $this->extract();

        $this->assertSame('capability_gap', $row->status);
        $this->assertSame('no_native_text', $row->error_code);
        $this->assertSame(3, $row->meta['pages']);
    }

    public function test_a_failing_engine_is_a_named_failure_and_retried_later(): void
    {
        Process::fake(['*' => Process::result('', 'Syntax Error: broken xref', 1)]);

        $row = $this->extract();

        $this->assertSame('failed', $row->status);
        $this->assertSame('pdftotext_failed', $row->error_code);
    }

    public function test_without_the_engine_the_result_is_exactly_the_metadata_reading(): void
    {
        config(['content.pdftotext' => '/nonexistent/pdftotext']);
        Process::fake();

        $row = $this->extract();

        $this->assertSame('partial', $row->status);
        $this->assertSame('ملزمة الكيمياء', $row->text);
        Process::assertNothingRan();
    }

    public function test_installing_the_engine_makes_metadata_only_rows_stale(): void
    {
        config(['content.pdftotext' => '/nonexistent/pdftotext']);
        $without = (new PdfTextExtractor)->version();
        config(['content.pdftotext' => '/bin/sh']);

        $with = (new PdfTextExtractor)->version();
        $this->assertNotSame($without, $with);
        // extractor_version is varchar(20): both forms must fit or the row never saves.
        $this->assertLessThanOrEqual(20, strlen($without));
        $this->assertLessThanOrEqual(20, strlen($with));
    }

    public function test_the_registry_routes_pdf_here(): void
    {
        $this->assertInstanceOf(PdfTextExtractor::class, (new ExtractorRegistry)->for('application/pdf'));
    }

    public function test_a_word_only_inside_the_pdf_finds_its_product(): void
    {
        Process::fake(['*' => Process::result("الأسطرلاب البحري\f")]);
        $this->seedSearchWorld();
        $product = $this->makeProduct('PDF-1', 'ملزمة الفلك', 1500);
        $this->extract();
        DB::table('product_files')->insert(['product_id' => $product->id, 'asset_id' => $this->lastAsset->id, 'role' => 'main-0', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/api/v1/search?q='.rawurlencode('الأسطرلاب'))->assertOk()->assertJsonPath('results.items.0.sku', 'PDF-1');
    }
}
