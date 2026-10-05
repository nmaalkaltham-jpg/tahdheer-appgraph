<?php

declare(strict_types=1);

namespace Tests\Feature\ContentIntelligence;

use App\Domain\Assets\Models\Asset;
use App\Domain\ContentIntelligence\AssetExtractionService;
use App\Domain\ContentIntelligence\ExtractorRegistry;
use App\Domain\ContentIntelligence\Extractors\OcrImageExtractor;
use App\Domain\Search\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Search\SeedsSearchFixtures;
use Tests\TestCase;

/**
 * OCR — صورٌ وصفحاتُ PDF ممسوحة، بالعربيّة والإنجليزيّة (Tesseract).
 *
 * قسمان: توصيلٌ بمخرجاتٍ ممثَّلة (`Process::fake`) يثبت الترشيح بالثقة
 * والتجميع والفشل؛ وقراءةٌ حقيقيّة لصورةٍ عربيّة مرسومة (tests/Fixtures) تُتخطّى
 * حيث لا محرّك — فتُثبت في الحاوية المبنيّة أنّ النصّ يُقرأ فعلًا ويُبحث فيه.
 */
class OcrExtractionTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    private const FIXTURES = 'tests/Fixtures/extraction/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('assets');
    }

    private function realEngine(): void
    {
        if (! is_executable('/usr/bin/tesseract') || ! is_executable('/usr/bin/pdftotext')) {
            $this->markTestSkipped('tesseract/poppler غير مثبّتين في هذه البيئة.');
        }
        config(['content.tesseract' => '/usr/bin/tesseract', 'content.pdftotext' => '/usr/bin/pdftotext']);
    }

    private function asset(string $bytes, string $mime): Asset
    {
        $sha = hash('sha256', $bytes);
        $key = 'assets/'.substr($sha, 0, 2).'/'.$sha;
        Storage::disk('assets')->put($key, $bytes);

        return Asset::query()->create([
            'provider' => 'local', 'provider_ref' => $key, 'original_name' => 'file', 'sha256' => $sha,
            'size_bytes' => strlen($bytes), 'mime' => $mime, 'visibility' => 'paid_private', 'usage_count' => 0,
        ]);
    }

    private function extract(string $bytes, string $mime): object
    {
        return $this->app->make(AssetExtractionService::class)->extract($this->asset($bytes, $mime))->row;
    }

    private function tsv(array $words): string
    {
        $rows = ["level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext"];
        foreach ($words as [$line, $conf, $text]) {
            $rows[] = "5\t1\t1\t1\t{$line}\t1\t0\t0\t10\t10\t{$conf}\t{$text}";
        }

        return implode("\n", $rows)."\n";
    }

    public function test_words_below_the_confidence_floor_never_reach_search(): void
    {
        config(['content.tesseract' => '/bin/sh']);
        Process::fake(['*' => Process::result($this->tsv([[1, 96, 'الغشاء'], [1, 91, 'البلازمي'], [2, 30, 'ضجيج'], [2, 88, 'الخلية']]))]);

        $row = $this->extract('fake-png', 'image/png');

        $this->assertSame('ok', $row->status);
        $this->assertSame(OcrImageExtractor::NAME, $row->extractor);
        $this->assertSame("الغشاء البلازمي\nالخلية", $row->text);
        $this->assertSame(3, (int) $row->units);
        $this->assertSame(1, $row->meta['dropped_low_confidence']);
        $this->assertEqualsWithDelta((96 + 91 + 88) / 3, $row->meta['mean_confidence'], 0.1);
    }

    public function test_an_image_without_readable_text_is_named_not_an_empty_success(): void
    {
        config(['content.tesseract' => '/bin/sh']);
        Process::fake(['*' => Process::result($this->tsv([[1, 20, 'ـ']]))]);

        $row = $this->extract('fake-png', 'image/png');

        $this->assertSame('capability_gap', $row->status);
        $this->assertSame('no_text_detected', $row->error_code);
        $this->assertNull($row->text);
    }

    public function test_a_failing_engine_is_retried_later(): void
    {
        config(['content.tesseract' => '/bin/sh']);
        Process::fake(['*' => Process::result('', 'Error in pixReadMem', 1)]);

        $this->assertSame('ocr_failed', $this->extract('fake-png', 'image/png')->error_code);
    }

    public function test_without_the_engine_the_named_gap_is_unchanged(): void
    {
        config(['content.tesseract' => '/nonexistent/tesseract']);

        $row = $this->extract('fake-png', 'image/png');

        $this->assertSame('ocr_engine_missing', $row->error_code);
        $this->assertStringEndsWith('-noocr', $row->extractor_version);
    }

    public function test_the_registry_routes_raster_images_to_ocr(): void
    {
        foreach (['image/png', 'image/jpeg', 'image/webp', 'image/tiff'] as $mime) {
            $this->assertInstanceOf(OcrImageExtractor::class, (new ExtractorRegistry)->for($mime), $mime);
        }
    }

    public function test_real_ocr_reads_arabic_and_english_from_an_image(): void
    {
        $this->realEngine();

        $row = $this->extract((string) file_get_contents(base_path(self::FIXTURES.'ocr-arabic.png')), 'image/png');

        $this->assertSame('ok', $row->status, json_encode($row->meta, JSON_UNESCAPED_UNICODE));
        $text = ArabicNormalizer::normalize((string) $row->text);
        $this->assertStringContainsString('الغشاء البلازمي', $text);
        $this->assertStringContainsString('membrane', $text);
    }

    public function test_real_ocr_reads_a_scanned_pdf_page(): void
    {
        $this->realEngine();
        $jpeg = (string) file_get_contents(base_path(self::FIXTURES.'ocr-arabic.jpg'));

        $row = $this->extract($this->imageOnlyPdf($jpeg, 900, 360), 'application/pdf');

        $this->assertSame('ok', $row->status, json_encode($row->meta, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($row->meta['ocr']);
        $this->assertSame(1, $row->meta['ocr_pages']);
        $this->assertStringContainsString('البلازمي', ArabicNormalizer::normalize((string) $row->text), (string) $row->text);
    }

    public function test_a_word_seen_only_in_a_picture_finds_its_product(): void
    {
        $this->realEngine();
        $this->seedSearchWorld();
        $product = $this->makeProduct('OCR-1', 'ملزمة الأحياء', 1500);
        $asset = $this->asset((string) file_get_contents(base_path(self::FIXTURES.'ocr-arabic.png')), 'image/png');
        DB::table('product_files')->insert(['product_id' => $product->id, 'asset_id' => $asset->id, 'role' => 'main-0', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->app->make(AssetExtractionService::class)->extract($asset);

        $this->getJson('/api/v1/search?q='.rawurlencode('الغشاء البلازمي'))->assertOk()->assertJsonPath('results.items.0.sku', 'OCR-1');
    }

    /** PDF بصفحةٍ واحدة هي صورةُ JPEG — لا طبقة نصّ فيه، كما يُخرجه الماسح الضوئيّ. */
    private function imageOnlyPdf(string $jpeg, int $w, int $h): string
    {
        $content = "q {$w} 0 0 {$h} 0 0 cm /Im0 Do Q";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$w} {$h}] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>",
            "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg)." >>\nstream\n{$jpeg}\nendstream",
            '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
