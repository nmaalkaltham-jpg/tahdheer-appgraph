<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Assets\Models\Asset;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * فاحص ذكاء المنتج — ما يُعرض، وما لا يُخفى، ولمن.
 *
 * كلّ اختبارٍ هنا يبني عيبًا حقيقيًّا في الصفوف ثمّ يسأل المسار عنه: الحالات
 * الثلاث التي لا وجود لها في قاعدة التطوير اليوم (`STALE` و`UNKNOWN`
 * و`NOT_REQUIRED`) لا تُثبَّت إلّا هكذا — وإلّا كان «المرور» شهادةً على بيانات
 * لا على شيفرة.
 */
class ProductIntelligenceTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private const SVG_SHA = 'aa11bb22cc33dd44ee55ff6677889900aa11bb22cc33dd44ee55ff6677889900';

    /** أصلٌ مربوطٌ بمنتج — بلا صفّ استخراجٍ ما لم يُطلب. */
    private function attach(Product $product, string $mime, string $sha, string $name): Asset
    {
        $asset = Asset::create([
            'provider' => 'local',
            /*
             * سرٌّ داخليّ: أحد الاختبارات أدناه يثبت أنّه لا يخرج في الاستجابة.
             * لاتينيّ عمدًا — عربيٌّ كان يُشفَّر `\uXXXX` في جسم JSON فيمرّ
             * فحصُ السلسلة على تسريبٍ واقع (وقد وقع هذا فعلًا أثناء البناء).
             */
            'provider_ref' => 'objects/SECRET-OBJECT-KEY/'.$sha,
            'original_name' => $name,
            'sha256' => $sha,
            'size_bytes' => 4280,
            'mime' => $mime,
            'visibility' => 'paid_private',
        ]);

        DB::table('product_files')->insert([
            'product_id' => $product->id, 'asset_id' => $asset->id, 'role' => 'main',
            'position' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $asset;
    }

    /** @param array<string, mixed> $values */
    private function row(Asset $asset, string $extractor, string $version, array $values): void
    {
        DB::table('asset_extractions')->insert(array_merge([
            'asset_id' => $asset->id,
            'asset_sha256' => $asset->sha256,
            'extractor' => $extractor,
            'extractor_version' => $version,
            'created_at' => now(),
            'updated_at' => now(),
        ], $values));
    }

    private function asCatalogReader(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));
    }

    /* ─── الحدّ: النصّ المستخرَج داخليّ (§٥٨ §١٢٦) ─── */

    public function test_the_inspector_refuses_an_unauthenticated_reader(): void
    {
        $product = $this->makeProduct('INTEL-401');

        $this->getJson('/api/v1/admin/catalog/intelligence/'.$product->id)
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->getJson('/api/v1/admin/catalog/intelligence')->assertStatus(401);
    }

    public function test_the_inspector_refuses_an_admin_without_catalogue_read(): void
    {
        $product = $this->makeProduct('INTEL-403');
        /*
         * دور «التحليلات» وحده بلا `products.read`
         * (`app/Domain/Admin/AdminRole.php:164`) — وهو ما يجعل هذا الاختبار
         * يقيس الحارس لا اسمًا اخترناه.
         */
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $this->getJson('/api/v1/admin/catalog/intelligence/'.$product->id)->assertStatus(403);
    }

    public function test_the_object_key_never_leaves_the_server(): void
    {
        $product = $this->makeProduct('INTEL-SECRET');
        $asset = $this->attach($product, 'image/svg+xml', self::SVG_SHA, 'cover.svg');
        $this->row($asset, 'svg_native_text', '2026.09.23', [
            'status' => 'ok', 'text' => 'تحضير', 'units' => 1, 'bytes_read' => 4280, 'duration_ms' => 3,
        ]);
        $this->asCatalogReader();

        $payload = $this->getJson('/api/v1/admin/catalog/intelligence/'.$product->id)
            ->assertOk()
            ->json();

        /*
         * يُعاد ترميز الجسم بلا هروب يونيكود قبل الفحص.
         *
         * البديل المرفوض `getContent()`: Laravel يُخرج العربيّة `\uXXXX`،
         * فالبحث عن سلسلةٍ عربيّة فيه لا يجد شيئًا **أبدًا** — ومرّ هذا
         * الاختبار على تسريبٍ مزروعٍ عمدًا قبل إصلاحه.
         *
         * وفحصُ اسم العمود `provider_ref` وحده لا يكفي: حقلٌ مسرَّب يُسمّى
         * `providerRef` في الجسم، فالمفحوص هو **القيمة** لا اسمُها.
         */
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString($asset->provider_ref, (string) $body);
        $this->assertStringNotContainsString('SECRET-OBJECT-KEY', (string) $body);
        $this->assertStringNotContainsString('provider_ref', (string) $body);
    }

    /* ─── اشتقاق المرحلة من الصفوف ─── */

    public function test_a_current_ok_row_is_complete_and_carries_the_extracted_text(): void
    {
        $product = $this->makeProduct('INTEL-OK');
        $asset = $this->attach($product, 'image/svg+xml', self::SVG_SHA, 'cover.svg');
        $this->row($asset, 'svg_native_text', '2026.09.23', [
            'status' => 'ok',
            'text' => 'تحضير اللغة الإنجليزية — الصف الثاني المتوسط',
            'units' => 11, 'bytes_read' => 4280, 'duration_ms' => 29,
        ]);
        $this->asCatalogReader();

        $this->getJson('/api/v1/admin/catalog/intelligence/'.$product->id)
            ->assertOk()
            ->assertJsonPath('pipeline.stage', 'COMPLETE')
            ->assertJsonPath('assets.0.stage', 'COMPLETE')
            ->assertJsonPath('assets.0.current.units', 11)
            ->assertJsonPath('assets.0.current.text', 'تحضير اللغة الإنجليزية — الصف الثاني المتوسط')
            ->assertJsonPath('assets.0.storage.state', 'readable');
    }

    public function test_a_failed_row_names_the_error_and_what_the_operator_does(): void
    {
        $product = $this->makeProduct('INTEL-FAIL');
        $asset = $this->attach($product, 'text/plain', str_repeat('b', 64), 'plan.txt');
        $this->row($asset, 'plain_text', '2026.09.23', [
            'status' => 'failed', 'error_code' => 'storage_missing_object', 'duration_ms' => 4,
            'meta' => json_encode(['note_ar' => 'التخزين لم يُعِد بايتات الأصل.'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->asCatalogReader();

        $response = $this->getJson('/api/v1/admin/catalog/intelligence/'.$product->id)
            ->assertOk()
            ->assertJsonPath('pipeline.stage', 'FAILED')
            ->assertJsonPath('assets.0.current.errorCode', 'storage_missing_object')
            ->assertJsonPath('assets.0.storage.state', 'missing');

        /* «نقطةٌ حمراء» لا تكفي: الاستجابة تحمل الأمر الذي يُعيد المحاولة. */
        $this->assertStringContainsString('content:extract', (string) $response->json('assets.0.remedy'));
    }

    public function test_an_asset_with_no_extraction_row_is_unknown_not_silent(): void
    {
        $product = $this->makeProduct('INTEL-SILENT');
        $this->attach($product, 'image/svg+xml', self::SVG_SHA, 'cover.svg');
        $this->asCatalogReader();

        $response = $this->getJson('/api/v1/admin/catalog/intelligence/'.$product->id)
            ->assertOk()
            ->assertJsonPath('pipeline.stage', 'UNKNOWN')
            ->assertJsonPath('assets.0.current', null)
            ->assertJsonPath('assets.0.storage.state', 'unverified');

        $this->assertStringContainsString('لا محاولة استخراج', (string) $response->json('assets.0.stageReason'));

        $this->getJson('/api/v1/admin/catalog/intelligence')
            ->assertOk()
            ->assertJsonPath('totals.assetsWithoutAttempt', 1);
    }

    public function test_only_a_superseded_row_reads_as_stale(): void
    {
        $product = $this->makeProduct('INTEL-STALE');
        $asset = $this->attach($product, 'image/svg+xml', self::SVG_SHA, 'cover.svg');
        /* نسخةٌ قديمة وحدها: المنتظَر اليوم `2026.09.23`. */
        $this->row($asset, 'svg_native_text', '2025.01.01', [
            'status' => 'ok', 'text' => 'قديم', 'units' => 1, 'bytes_read' => 4280,
        ]);
        $this->asCatalogReader();

        $this->getJson('/api/v1/admin/catalog/intelligence/'.$product->id)
            ->assertOk()
            ->assertJsonPath('pipeline.stage', 'STALE')
            ->assertJsonPath('assets.0.current', null)
            ->assertJsonPath('assets.0.superseded.0.version', '2025.01.01');
    }

    public function test_a_capability_gap_reads_as_not_required_and_names_the_missing_capability(): void
    {
        /* ‎.pptx‎ صار مقروءًا (OfficeOpenXmlExtractor)؛ العجزُ الباقي هو ‎.ppt‎ الثنائيّ القديم. */
        $product = $this->makeProduct('INTEL-GAP');
        $asset = $this->attach($product, 'application/vnd.ms-powerpoint', str_repeat('c', 64), 'lesson.ppt');
        $this->row($asset, 'capability_gap', '2026.09.23', [
            'status' => 'capability_gap', 'error_code' => 'legacy_office_unsupported',
            'meta' => json_encode([
                'capability' => 'legacy_office_text',
                'requires' => ['محوّل يقرأ صيغة OLE القديمة (doc/ppt/xls)'],
                'note_ar' => 'صيغة Office قديمة بتخزين OLE ثنائيّ. لا محوّل لها اليوم.',
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $this->asCatalogReader();

        $this->getJson('/api/v1/admin/catalog/intelligence/'.$product->id)
            ->assertOk()
            ->assertJsonPath('pipeline.stage', 'NOT_REQUIRED')
            ->assertJsonPath('assets.0.gap.capability', 'legacy_office_text')
            ->assertJsonPath('assets.0.gap.requires.0', 'محوّل يقرأ صيغة OLE القديمة (doc/ppt/xls)')
            /* الصيغة التي لا محوّل لها تُعلَن في التغطية، لا تُترك تُستنتج. */
            ->assertJsonPath('assets.0.expected.claimed', false);
    }

    /**
     * عجزٌ سُجّل قبل وجود محوّلٍ لصيغته يصير قديمًا حين يوجد المحوّل —
     * فيلتقطه `content:extract` التالي بدل أن يبقى «لا يُقرأ» إلى الأبد.
     */
    public function test_an_office_gap_recorded_before_the_extractor_existed_reads_as_stale(): void
    {
        $product = $this->makeProduct('INTEL-OOXML');
        $asset = $this->attach(
            $product,
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            str_repeat('d', 64),
            'lesson.pptx',
        );
        $this->row($asset, 'capability_gap', '2026.09.23', [
            'status' => 'capability_gap', 'error_code' => 'office_open_xml_unsupported',
            'meta' => json_encode(['capability' => 'office_open_xml_text'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->asCatalogReader();

        $this->getJson('/api/v1/admin/catalog/intelligence/'.$product->id)
            ->assertOk()
            ->assertJsonPath('pipeline.stage', 'STALE')
            ->assertJsonPath('assets.0.expected.claimed', true);
    }

    public function test_a_legacy_status_outside_the_vocabulary_is_unknown_not_guessed(): void
    {
        $product = $this->makeProduct('INTEL-LEGACY');
        $asset = $this->attach($product, 'image/svg+xml', self::SVG_SHA, 'cover.svg');
        $this->row($asset, 'svg_native_text', '2026.09.23', [
            'status' => 'ocr_unavailable', 'error_code' => 'ocr_engine_missing',
        ]);
        $this->asCatalogReader();

        $response = $this->getJson('/api/v1/admin/catalog/intelligence/'.$product->id)->assertOk();

        $this->assertSame('UNKNOWN', $response->json('pipeline.stage'));
        $this->assertStringContainsString('ocr_unavailable', (string) $response->json('assets.0.stageReason'));
    }

    public function test_a_product_without_assets_is_not_required_not_failed(): void
    {
        $product = $this->makeProduct('INTEL-EMPTY');
        $this->asCatalogReader();

        $this->getJson('/api/v1/admin/catalog/intelligence/'.$product->id)
            ->assertOk()
            ->assertJsonPath('pipeline.stage', 'NOT_REQUIRED')
            ->assertJsonPath('assets', []);
    }

    public function test_a_missing_product_is_a_404_not_an_empty_inspector(): void
    {
        $this->asCatalogReader();

        $this->getJson('/api/v1/admin/catalog/intelligence/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'PRODUCT_NOT_FOUND');
    }

    public function test_the_list_ranks_the_broken_product_above_the_whole_one(): void
    {
        $whole = $this->makeProduct('INTEL-LIST-OK');
        $asset = $this->attach($whole, 'image/svg+xml', self::SVG_SHA, 'cover.svg');
        $this->row($asset, 'svg_native_text', '2026.09.23', [
            'status' => 'ok', 'text' => 'تحضير', 'units' => 11, 'bytes_read' => 4280,
        ]);

        $broken = $this->makeProduct('INTEL-LIST-FAIL');
        $brokenAsset = $this->attach($broken, 'text/plain', str_repeat('d', 64), 'plan.txt');
        $this->row($brokenAsset, 'plain_text', '2026.09.23', [
            'status' => 'failed', 'error_code' => 'storage_missing_object',
        ]);

        $this->asCatalogReader();

        $response = $this->getJson('/api/v1/admin/catalog/intelligence')->assertOk();

        /* الأسوأ أوّلًا وإن كان معرّفه أكبر: الترتيب هو قيمة الشاشة. */
        $this->assertSame($broken->id, $response->json('data.0.id'));
        $this->assertSame('FAILED', $response->json('data.0.stage'));
        $this->assertSame(1, $response->json('stages.FAILED'));
        $this->assertSame(1, $response->json('stages.COMPLETE'));
    }
}
