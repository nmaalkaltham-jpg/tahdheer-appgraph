<?php

namespace Tests\Feature\Storage;

use App\Domain\Assets\AssetImporter;
use App\Domain\Assets\Exceptions\AssetInUse;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\ProductFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Ingestion rules: real bytes, sha256 identity, reference counting. */
class AssetImportTest extends TestCase
{
    use RefreshDatabase;
    use StorageFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('assets');
    }

    public function test_import_stores_real_bytes_and_records_the_checksum(): void
    {
        $product = $this->makeProduct('TD-IMP-001');
        $contents = "محتوى ملف حقيقي للاختبار.\n";

        $asset = $this->importAsset($product, $contents, 'تحضير لغتي.txt');

        $this->assertSame(hash('sha256', $contents), $asset->sha256);
        $this->assertSame(strlen($contents), $asset->size_bytes);
        $this->assertSame('text/plain', $asset->mime);
        $this->assertSame('local', $asset->provider);
        $this->assertSame(1, $asset->usage_count);
        $this->assertSame($contents, Storage::disk('assets')->get((string) $asset->provider_ref));
        $this->assertDatabaseHas('product_files', [
            'product_id' => $product->id,
            'asset_id' => $asset->id,
            'role' => 'main',
        ]);
    }

    public function test_identical_bytes_are_deduplicated_and_reference_counted(): void
    {
        $contents = "نفس المحتوى تمامًا.\n";
        $first = $this->importAsset($this->makeProduct('TD-IMP-002'), $contents);
        $second = $this->importAsset($this->makeProduct('TD-IMP-003'), $contents);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Asset::query()->count());
        $this->assertSame(2, (int) $first->refresh()->usage_count);
        $this->assertSame(2, ProductFile::query()->where('asset_id', $first->id)->count());
    }

    public function test_reimporting_the_same_file_for_the_same_role_is_a_no_op(): void
    {
        $product = $this->makeProduct('TD-IMP-004');
        $contents = "ملف ثابت.\n";

        $asset = $this->importAsset($product, $contents);
        $again = $this->importAsset($product, $contents);

        $this->assertSame($asset->id, $again->id);
        $this->assertSame(1, (int) $asset->refresh()->usage_count);
        $this->assertSame(1, ProductFile::query()->where('product_id', $product->id)->count());
    }

    /*
     * المعرِّفُ بالمحتوى يُثبت أنّ الصفّ موجود، لا أنّ الملفّ موجود. فُقد
     * الكائن (مجلّدُ تخزينٍ أُعيد إنشاؤه، أو كتابةٌ ضاعت) فصار كلُّ تحميلٍ
     * «الملفّ غير متوفّر» — والعلاجُ الطبيعيّ، رفعُ الملفّ نفسه ثانيةً، كان
     * يُعلن «تمّ» ولا يكتب شيئًا. الاستيراد يجب أن يترك الكائن موجودًا.
     */
    public function test_reimporting_restores_a_lost_object_instead_of_reporting_a_no_op(): void
    {
        $product = $this->makeProduct('TD-IMP-HEAL-1');
        $contents = "ملفٌّ فُقد كائنه.\n";
        $asset = $this->importAsset($product, $contents);
        Storage::disk('assets')->delete((string) $asset->provider_ref);

        $again = $this->importAsset($product, $contents);

        $this->assertSame($asset->id, $again->id);
        $this->assertSame($contents, Storage::disk('assets')->get((string) $asset->provider_ref));
        $this->assertSame(1, (int) $asset->refresh()->usage_count);
    }

    public function test_deduplicating_onto_an_asset_whose_object_is_lost_restores_it(): void
    {
        $contents = "محتوى مشترك فُقد كائنه.\n";
        $first = $this->importAsset($this->makeProduct('TD-IMP-HEAL-2'), $contents);
        Storage::disk('assets')->delete((string) $first->provider_ref);

        $second = $this->importAsset($this->makeProduct('TD-IMP-HEAL-3'), $contents);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($contents, Storage::disk('assets')->get((string) $first->provider_ref));
    }

    public function test_a_referenced_file_is_never_overwritten_without_archiving(): void
    {
        $product = $this->makeProduct('TD-IMP-005');
        $original = $this->importAsset($product, "النسخة الأولى.\n");

        try {
            $this->importAsset($product, "النسخة الثانية.\n");
            $this->fail('Replacing a referenced asset must be refused.');
        } catch (AssetInUse $e) {
            $this->assertSame((int) $original->id, $e->assetId);
        }

        $replacement = $this->importAsset($product, "النسخة الثانية.\n", archiveExisting: true);

        $this->assertNotSame($original->id, $replacement->id);
        $this->assertDatabaseHas('product_files', [
            'product_id' => $product->id,
            'asset_id' => $original->id,
            'role' => ProductFile::ROLE_ARCHIVED,
        ]);
        $this->assertDatabaseHas('product_files', [
            'product_id' => $product->id,
            'asset_id' => $replacement->id,
            'role' => 'main',
        ]);
        // The archived original is still stored — archive-first, never destroy.
        $this->assertTrue(Storage::disk('assets')->exists((string) $original->provider_ref));
    }

    public function test_deleting_a_referenced_asset_is_blocked(): void
    {
        $product = $this->makeProduct('TD-IMP-006');
        $asset = $this->importAsset($product, "مرجع حي.\n");

        $this->expectException(AssetInUse::class);
        app(AssetImporter::class)->delete($asset);
    }

    public function test_the_artisan_command_imports_without_printing_the_object_key(): void
    {
        $product = $this->makeProduct('TD-IMP-007');
        $dir = storage_path('app/private/testing-staging');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/أوراق عمل.txt';
        File::put($path, "محتوى من سطر الأوامر.\n");

        try {
            $exitCode = Artisan::call('assets:import', [
                'product' => $product->sku,
                'file' => $path,
                '--label' => 'أوراق عمل — الفصل الأول.txt',
            ]);
        } finally {
            File::delete($path);
        }

        $this->assertSame(0, $exitCode);

        $asset = Asset::query()->firstOrFail();
        $this->assertSame('أوراق عمل — الفصل الأول.txt', $asset->customer_label);
        $this->assertSame(1, (int) $asset->usage_count);
        $this->assertStringNotContainsString((string) $asset->provider_ref, Artisan::output());
    }

    public function test_the_command_reports_a_missing_product_instead_of_guessing(): void
    {
        $this->assertSame(1, Artisan::call('assets:import', [
            'product' => 'no-such-product',
            'file' => __FILE__,
        ]));
    }
}
