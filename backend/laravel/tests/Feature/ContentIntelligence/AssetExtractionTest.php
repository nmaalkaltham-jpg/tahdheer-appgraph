<?php

declare(strict_types=1);

namespace Tests\Feature\ContentIntelligence;

use App\Domain\Assets\Models\Asset;
use App\Domain\ContentIntelligence\AssetExtractionService;
use App\Domain\ContentIntelligence\ExtractorRegistry;
use App\Domain\ContentIntelligence\Extractors\CapabilityGapExtractor;
use App\Domain\ContentIntelligence\Extractors\OcrImageExtractor;
use App\Domain\ContentIntelligence\Extractors\PlainTextExtractor;
use App\Domain\ContentIntelligence\Extractors\SvgNativeTextExtractor;
use App\Domain\ContentIntelligence\Jobs\ExtractAssetJob;
use App\Domain\ContentIntelligence\Models\AssetExtraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * خطّ استخراج المحتوى — على بايتاتٍ حقيقيّة من `tests/Fixtures/extraction`.
 *
 * **ما تغطّيه:** النصّ الأصليّ في SVG (بلا OCR)، والنصّ الصريح، والعجز
 * المعلَن لصيغةٍ لا محوّل لها، وقِصَر الدارة بالبصمة، وإعادة الفاشل وحده.
 *
 * **ما لا تغطّيه:** PDF بتثبيتةٍ في المستودع — الملفّ الوحيد في الكتالوج
 * ٢٣٬٢٥٨ بايتًا ثنائيّة، وتثبيتةٌ بهذا الحجم تُقرأ في كلّ مراجعةٍ ولا
 * تُقرأ. مسار PDF مبرهَنٌ حيًّا على أصل الكتالوج (`asset_id=30`).
 */
class AssetExtractionTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = 'tests/Fixtures/extraction/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('assets');
    }

    public function test_svg_arabic_text_is_extracted_natively_without_ocr(): void
    {
        $asset = $this->asset('cover-arabic.svg', 'image/svg+xml');

        $row = $this->service()->extract($asset)->row;

        $this->assertSame('ok', $row->status);
        $this->assertNull($row->error_code);
        $this->assertSame(SvgNativeTextExtractor::NAME, $row->extractor);
        $this->assertSame(SvgNativeTextExtractor::VERSION, $row->extractor_version);

        /* الكيانات التعليميّة التي يريدها النظام — نصًّا أصليًّا لا صورة. */
        $this->assertStringContainsString('الرياضيات', (string) $row->text);
        $this->assertStringContainsString('الصف السادس الابتدائي', (string) $row->text);
        $this->assertStringContainsString('الفصل الدراسي الأول', (string) $row->text);
        $this->assertStringContainsString('DOCX · قابل للتعديل', (string) $row->text);

        /*
         * سبعُ وحدات: عنوانٌ وستّ عُقَد نصّ. والعقدة الفارغة (مسافاتٌ وحدها)
         * تسقط، وعقدةُ `tspan` تُعدّ مع أبيها لا مرّتين — وهو الخطأ الذي
         * يقع حين يُطلَب `tspan` في XPath إلى جانب `text`.
         */
        $this->assertSame(7, (int) $row->units);
        $this->assertSame(['title' => 1, 'text' => 6], $row->meta['nodes_by_tag']);
        $this->assertStringContainsString('٩٢ صفحة', (string) $row->text);
        $this->assertSame((int) $asset->size_bytes, (int) $row->bytes_read);
    }

    public function test_plain_text_is_extracted_and_blank_lines_are_not_counted(): void
    {
        $asset = $this->asset('notes-arabic.txt', 'text/plain');

        $row = $this->service()->extract($asset)->row;

        $this->assertSame('ok', $row->status);
        $this->assertSame(PlainTextExtractor::NAME, $row->extractor);
        $this->assertStringContainsString('الكسور العشريّة', (string) $row->text);
        /* أربعة أسطرٍ غير فارغة من خمسة: السطر الفارغ ليس وحدةً نصّيّة. */
        $this->assertSame(4, (int) $row->units);
        $this->assertFalse($row->meta['truncated']);
    }

    public function test_unsupported_format_records_a_named_capability_gap_not_a_fake_success(): void
    {
        /* ‎.pptx‎ صار مقروءًا (OfficeOpenXmlExtractor)؛ والعجز الباقي هو ‎.ppt‎ الثنائيّ القديم. */
        $asset = $this->asset('unsupported-deck.pptx.bytes', 'application/vnd.ms-powerpoint');

        $row = $this->service()->extract($asset)->row;

        $this->assertSame('capability_gap', $row->status);
        $this->assertSame('legacy_office_unsupported', $row->error_code);
        $this->assertSame(CapabilityGapExtractor::NAME, $row->extractor);
        /* لا نصّ ولا عدد وحدات: العجز لا يُخزَّن بوصفه قراءةً فارغة. */
        $this->assertNull($row->text);
        $this->assertNull($row->units);
        $this->assertNotEmpty($row->meta['requires']);
        $this->assertSame('legacy_office_text', $row->meta['capability']);
    }

    public function test_rerunning_over_unchanged_bytes_creates_no_new_extraction(): void
    {
        $asset = $this->asset('cover-arabic.svg', 'image/svg+xml');
        $service = $this->service();

        $first = $service->extract($asset);
        $this->assertFalse($first->wasSkipped());

        /*
         * البرهان ليس «مرّ التشغيل» بل «لم يقع شيء»: صفٌّ واحد، وهو الصفّ
         * نفسه بمعرّفه، ولم يُلمَس (`updated_at` لم يتغيّر) — أي أنّ البايتات
         * لم تُقرأ أصلًا (البند ٤٢).
         */
        $second = $service->extract($asset->refresh());

        $this->assertTrue($second->wasSkipped());
        $this->assertSame(1, AssetExtraction::query()->count());
        $this->assertSame($first->row->id, $second->row->id);
        $this->assertEquals($first->row->updated_at, $second->row->updated_at);
    }

    public function test_forcing_rewrites_the_same_row_and_never_duplicates_the_content_key(): void
    {
        $asset = $this->asset('cover-arabic.svg', 'image/svg+xml');
        $service = $this->service();

        $first = $service->extract($asset);
        $forced = $service->extract($asset, force: true);

        $this->assertFalse($forced->wasSkipped());
        $this->assertSame($first->row->id, $forced->row->id);
        $this->assertSame(1, AssetExtraction::query()->count());
    }

    public function test_a_failed_extraction_is_retried_while_a_capability_gap_is_not(): void
    {
        /* أصلٌ في السجلّ وكائنُه غائبٌ عن التخزين — وهي حالُ أصلَي النصّ في قاعدة التطوير. */
        $missing = $this->asset('notes-arabic.txt', 'text/plain', store: false);
        $service = $this->service();

        $failed = $service->extract($missing)->row;

        $this->assertSame('failed', $failed->status);
        $this->assertSame('storage_missing_object', $failed->error_code);
        /* السبب من المزوّد كما هو: «غائب» لا «تعذّر السؤال» (قاعدة ١١). */
        $this->assertNull($failed->bytes_read);

        /* الفشل غير نهائيّ: التشغيل التالي يُعيد المحاولة (البند ٤٤). */
        $this->assertFalse($service->extract($missing->refresh())->wasSkipped());

        $gap = $this->asset('unsupported-deck.pptx.bytes', 'application/vnd.ms-powerpoint');
        $service->extract($gap);

        /* والعجز نهائيّ: لا يُعاد إلّا برفع نسخة المستخرِج. */
        $this->assertTrue($service->extract($gap->refresh())->wasSkipped());
    }

    public function test_registry_resolves_by_mime_and_falls_back_to_the_capability_gap(): void
    {
        $registry = new ExtractorRegistry;

        $this->assertInstanceOf(SvgNativeTextExtractor::class, $registry->for('image/svg+xml'));
        /* مُعاملٌ في النوع لا يُسقط المحوّل: `text/plain; charset=utf-8` نصٌّ صريح. */
        $this->assertInstanceOf(PlainTextExtractor::class, $registry->for('text/plain; charset=utf-8'));
        /* الصور النقطيّة صار يقرؤها OcrImageExtractor؛ ما لا يدّعيه أحدٌ يبقى عجزًا مُسمّى. */
        $this->assertInstanceOf(OcrImageExtractor::class, $registry->for('image/png'));
        $this->assertInstanceOf(CapabilityGapExtractor::class, $registry->for('application/octet-stream'));
    }

    public function test_the_command_queues_one_job_per_asset_and_extracts_inline_only_when_asked(): void
    {
        /*
         * الأمر يُسجَّل هنا يدويًّا: اكتشاف Laravel يقتصر على
         * `app/Console/Commands` (ApplicationBuilder::withCommands)، وهو خارج
         * ملفّات هذا المسار. التسجيل الدائم سطرٌ واحد ينتظر مالك ذلك الملفّ.
         */
        /*
         * لا تسجيلَ يدويّ: الأمر يسكن `app/Console/Commands` فيكتشفه لارافيل.
         * وكان في `app/Domain/…/Console` حيث لا يبحث الإطار، فمرّ الاختبار
         * بتسجيلٍ يدويّ بينما `php artisan content:extract` يردّ «لا أوامر في
         * النطاق content». اختبارٌ يُسجّل ما لا يُسجَّل في الإنتاج يُثبت نفسه
         * لا الأمر — فأُزيل التسجيل ليُقاس الاكتشاف الحقيقيّ.
         */

        $this->asset('cover-arabic.svg', 'image/svg+xml');
        $this->asset('notes-arabic.txt', 'text/plain');

        Bus::fake();
        $this->artisan('content:extract')->assertSuccessful();
        /* العمل الثقيل في الطابور لا في مسار الأمر (البند ٤٥). */
        Bus::assertDispatchedTimes(ExtractAssetJob::class, 2);

        $this->artisan('content:extract --sync')->assertSuccessful();
        $this->assertSame(2, AssetExtraction::query()->where('status', 'ok')->count());
    }

    private function service(): AssetExtractionService
    {
        return $this->app->make(AssetExtractionService::class);
    }

    /** أصلٌ حقيقيّ ببايتاتٍ حقيقيّة — والمفتاح مشتقٌّ من البصمة كما يفعل المستورِد. */
    private function asset(string $fixture, string $mime, bool $store = true): Asset
    {
        $bytes = (string) file_get_contents(base_path(self::FIXTURES.$fixture));
        $sha = hash('sha256', $bytes);
        $key = 'assets/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;

        if ($store) {
            Storage::disk('assets')->put($key, $bytes);
        }

        return Asset::query()->create([
            'provider' => 'local',
            'provider_ref' => $key,
            'original_name' => $fixture,
            'sha256' => $sha,
            'size_bytes' => strlen($bytes),
            'mime' => $mime,
            'visibility' => 'paid_private',
            'usage_count' => 0,
        ]);
    }
}
