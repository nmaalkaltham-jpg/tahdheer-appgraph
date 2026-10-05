<?php

declare(strict_types=1);

namespace Tests\Feature\ContentIntelligence;

use App\Domain\Assets\Models\Asset;
use App\Domain\ContentIntelligence\AssetExtractionService;
use App\Domain\ContentIntelligence\ExtractorRegistry;
use App\Domain\ContentIntelligence\Extractors\OfficeOpenXmlExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Search\SeedsSearchFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * نصّ ملفّات Office الحديثة (pptx · docx · xlsx) — حزمة ZIP فيها XML.
 *
 * كانت كلّها «عجزًا مُسمّى» (`office_open_xml_unsupported`)، وهي صيغُ أغلب
 * منتجات المتجر: فالبحث داخل الملفّات كان مبنيًّا ولا يرى شيئًا منها.
 * الحِزم هنا تُبنى في الاختبار بـ`ZipArchive` — بايتاتٌ حقيقيّة لا وصف.
 */
class OfficeTextExtractionTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    private const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('assets');
    }

    public function test_a_deck_yields_its_slides_in_order_then_speaker_notes(): void
    {
        $row = $this->extract(self::PPTX, [
            // slide10 before slide2 in the archive: order must be numeric, not lexical.
            'ppt/slides/slide10.xml' => $this->slide(['الشريحة العاشرة']),
            'ppt/slides/slide2.xml' => $this->slide(['البناء الضوئي', 'يحدث في البلاستيدات']),
            'ppt/slides/slide1.xml' => $this->slide(['درس النبات']),
            'ppt/notesSlides/notesSlide1.xml' => $this->slide(['ملاحظة للمعلم']),
        ]);

        $this->assertSame('ok', $row->status);
        $this->assertSame(3, (int) $row->units);
        $this->assertSame(['درس النبات', 'البناء الضوئي', 'يحدث في البلاستيدات', 'الشريحة العاشرة', 'ملاحظة للمعلم'], explode("\n", (string) $row->text));
        $this->assertSame(3, $row->meta['slides']);
        $this->assertSame(1, $row->meta['notes']);
        $this->assertSame('درس النبات', $row->meta['titles'][0]);
    }

    public function test_a_document_yields_body_tables_headers_and_footnotes(): void
    {
        $row = $this->extract(self::DOCX, [
            'word/document.xml' => $this->docx('<w:p><w:r><w:t>الوحدة الأولى</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t xml:space="preserve">المفعول </w:t></w:r><w:r><w:t>به</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'),
            'word/header1.xml' => $this->docx('<w:p><w:r><w:t>مدرسة التحاضير</w:t></w:r></w:p>', 'w:hdr'),
            'word/footnotes.xml' => $this->docx('<w:footnote><w:p><w:r><w:t>حاشية</w:t></w:r></w:p></w:footnote>', 'w:footnotes'),
        ]);

        $this->assertSame('ok', $row->status);
        $this->assertSame(['الوحدة الأولى', 'المفعول به', 'مدرسة التحاضير', 'حاشية'], explode("\n", (string) $row->text));
    }

    public function test_a_workbook_yields_sheet_names_and_shared_strings(): void
    {
        $row = $this->extract(self::XLSX, [
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="درجات الفصل الأول" sheetId="1"/><sheet name="الحضور" sheetId="2"/></sheets></workbook>',
            'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>اسم الطالب</t></si><si><r><t>الدرجة </t></r><r><t>النهائية</t></r></si></sst>',
        ]);

        $this->assertSame('ok', $row->status);
        $this->assertSame(2, (int) $row->units);
        $this->assertSame(['درجات الفصل الأول', 'الحضور', 'اسم الطالب', 'الدرجة النهائية'], explode("\n", (string) $row->text));
    }

    public function test_a_deck_of_pictures_is_an_ocr_gap_not_an_empty_success(): void
    {
        $row = $this->extract(self::PPTX, ['ppt/slides/slide1.xml' => $this->slide([])]);

        $this->assertSame('capability_gap', $row->status);
        $this->assertSame('no_native_text', $row->error_code);
        $this->assertNull($row->text);
    }

    public function test_bytes_that_are_not_a_package_fail_by_name(): void
    {
        $row = $this->extractBytes(self::DOCX, 'ليس ملفّ ZIP');

        $this->assertSame('failed', $row->status);
        $this->assertSame('corrupt_package', $row->error_code);
    }

    public function test_a_package_that_inflates_past_the_budget_is_refused_before_reading(): void
    {
        // Compresses to a few KB; inflates past the XML budget.
        $row = $this->extract(self::PPTX, ['ppt/slides/slide1.xml' => str_repeat(' ', OfficeOpenXmlExtractor::MAX_XML_BYTES + 1)]);

        $this->assertSame('failed', $row->status);
        $this->assertSame('package_too_large', $row->error_code);
    }

    public function test_external_entities_are_never_resolved(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/hostname">]><p:sld xmlns:p="p" xmlns:a="a"><a:p><a:t>قبل &e; بعد</a:t></a:p></p:sld>';
        $row = $this->extract(self::PPTX, ['ppt/slides/slide1.xml' => $xxe]);

        $this->assertStringNotContainsString(trim((string) @file_get_contents('/etc/hostname')) ?: '§never§', (string) $row->text);
    }

    public function test_the_registry_routes_every_office_open_xml_type_here(): void
    {
        $registry = new ExtractorRegistry;

        foreach ([self::PPTX, self::DOCX, self::XLSX] as $mime) {
            $this->assertInstanceOf(OfficeOpenXmlExtractor::class, $registry->for($mime), $mime);
        }
    }

    public function test_a_word_that_lives_only_on_a_slide_finds_its_product(): void
    {
        $this->seedSearchWorld();
        $product = $this->makeProduct('OX-1', 'عرض درس النبات', 1500);
        $asset = $this->asset(self::PPTX, $this->zip(['ppt/slides/slide1.xml' => $this->slide(['الكلوروفيل يمتص الضوء'])]));
        DB::table('product_files')->insert(['product_id' => $product->id, 'asset_id' => $asset->id, 'role' => 'main-0', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->app->make(AssetExtractionService::class)->extract($asset);

        $response = $this->getJson('/api/v1/search?q='.rawurlencode('الكلوروفيل'))->assertOk();

        $response->assertJsonPath('results.items.0.sku', 'OX-1');
        // Matched on, never shown: the slide sentence stays inside the paid file.
        $this->assertStringNotContainsString('يمتص الضوء', $response->getContent());
    }

    /* ------------------------------------------------------------ helpers */

    /** @param array<string, string> $parts */
    private function extract(string $mime, array $parts): object
    {
        return $this->extractBytes($mime, $this->zip($parts));
    }

    private function extractBytes(string $mime, string $bytes): object
    {
        return $this->app->make(AssetExtractionService::class)->extract($this->asset($mime, $bytes))->row;
    }

    /** @param array<string, string> $parts */
    private function zip(array $parts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ooxml');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($parts as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** @param list<string> $paragraphs */
    private function slide(array $paragraphs): string
    {
        $body = implode('', array_map(fn (string $t): string => '<a:p><a:r><a:t>'.htmlspecialchars($t).'</a:t></a:r></a:p>', $paragraphs));

        return '<?xml version="1.0"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody>'.$body.'</p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
    }

    private function docx(string $inner, string $root = 'w:document'): string
    {
        $wrapped = $root === 'w:document' ? "<w:body>{$inner}</w:body>" : $inner;

        return '<?xml version="1.0"?><'.$root.' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'.$wrapped.'</'.$root.'>';
    }

    private function asset(string $mime, string $bytes): Asset
    {
        $sha = hash('sha256', $bytes);
        $key = 'assets/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;
        Storage::disk('assets')->put($key, $bytes);

        return Asset::query()->create([
            'provider' => 'local', 'provider_ref' => $key, 'original_name' => 'file', 'sha256' => $sha,
            'size_bytes' => strlen($bytes), 'mime' => $mime, 'visibility' => 'paid_private', 'usage_count' => 0,
        ]);
    }
}
