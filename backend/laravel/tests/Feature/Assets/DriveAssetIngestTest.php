<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Domain\Admin\AdminRole;
use App\Domain\Assets\DriveAssetIngest;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * مدخل Drive — من معرّف ملفٍّ إلى منتجٍ قابل للتسليم.
 *
 * **ما لا تغطّيه:** لا حساب Drive حقيقيّ. الشبكة مزيَّفة، والمُختبَر هو ما
 * يُكتب في السجلّ نتيجةَ استجابة — وهو موضع الخطأ الذي يُنتج منتجًا يبدو
 * مربوطًا ولا يُسلَّم.
 */
class DriveAssetIngestTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private const FILE = [
        'id' => 'DRIVE_FILE_1',
        'name' => 'تحضير الرياضيات.pdf',
        'mimeType' => 'application/pdf',
        'size' => '2048',
        'md5Checksum' => 'abc123',
        'modifiedTime' => '2026-09-01T10:00:00.000Z',
        'version' => 7,
        'parents' => ['FOLDER_1'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_drive.access_token' => 'token-for-test']);
    }

    /**
     * يزيّف Drive لملفٍّ واحد أو أكثر، **بنداءٍ واحد**.
     *
     * `Http::fake()` تُراكم المُزيَّفات ولا تستبدلها: فنداءٌ ثانٍ لا يُبطل
     * الأوّل، ويبقى الأوّل هو المطابِق — فيُعاد محتوى الملفّ القديم لمعرّفٍ
     * جديد. وقد أنتج ذلك اختبار استبدالٍ يمرّ بينما الاستبدال لم يقع: الأصل
     * وُجد بالتجزئة نفسها فأُعيد استعماله. ومُزيَّفٌ يكذب أخطرُ من غياب
     * اختبار، لأنّه يُطمئن.
     *
     * فالتوزيع في مُغلَّفٍ واحد يقرأ معرّف الملفّ من المسار.
     *
     * @param  array<string, array{0: array<string, mixed>, 1: string}>  $files
     */
    private function fakeDriveFiles(array $files): void
    {
        Http::fake(function ($request) use ($files) {
            $url = (string) $request->url();

            foreach ($files as $id => [$meta, $bytes]) {
                if (! str_contains($url, "/files/{$id}")) {
                    continue;
                }

                return str_contains($url, 'alt=media')
                    ? Http::response($bytes, 200)
                    : Http::response($meta, 200);
            }

            return Http::response([], 404);
        });
    }

    /** الملفّ الافتراضيّ وحده — أكثر الاختبارات لا تحتاج غيره. */
    private function fakeDrive(array $file = self::FILE, string $bytes = 'PDF-BYTES-HERE'): void
    {
        $this->fakeDriveFiles([(string) ($file['id'] ?? 'DRIVE_FILE_1') => [$file, $bytes]]);
    }

    public function test_the_file_id_is_extracted_from_a_pasted_link_and_the_link_is_never_stored(): void
    {
        foreach ([
            'https://drive.google.com/file/d/DRIVE_FILE_1/view?usp=sharing' => 'DRIVE_FILE_1',
            'https://drive.google.com/open?id=DRIVE_FILE_1' => 'DRIVE_FILE_1',
            'https://drive.google.com/drive/folders/FOLDER_ABC123' => 'FOLDER_ABC123',
            'DRIVE_FILE_1' => 'DRIVE_FILE_1',
        ] as $input => $expected) {
            $this->assertSame($expected, DriveAssetIngest::fileIdFrom($input));
        }
    }

    public function test_attaching_a_drive_file_registers_a_source_and_links_it_to_the_product(): void
    {
        $this->fakeDrive();
        $product = $this->makeProduct('TD-DRIVE-1');

        $source = app(DriveAssetIngest::class)->attach($product, 'DRIVE_FILE_1');

        $this->assertSame('GOOGLE_DRIVE', $source->provider);
        /* §١٣ — المعرّف هو الهويّة، ولا يُخزَّن رابط. */
        $this->assertSame('DRIVE_FILE_1', $source->external_id);
        $this->assertSame('7', $source->source_revision);
        $this->assertSame('CURRENT', $source->sync_state);
        $this->assertSame('IMPORT_ONCE', $source->delivery_mode);
        $this->assertTrue($source->isDeliverable());

        $this->assertNotNull($source->asset_id);
        $this->assertDatabaseHas('product_files', [
            'product_id' => $product->id,
            'asset_id' => $source->asset_id,
            'role' => 'main',
        ]);
    }

    public function test_attaching_the_same_file_twice_is_idempotent(): void
    {
        $this->fakeDrive();
        $product = $this->makeProduct('TD-DRIVE-2');
        $ingest = app(DriveAssetIngest::class);

        $first = $ingest->attach($product, 'DRIVE_FILE_1');
        $second = $ingest->attach($product, 'DRIVE_FILE_1');

        $this->assertSame($first->id, $second->id, 'الملفّ الواحد صفٌّ واحد — القيد يفرضه والخدمة تحترمه');
        $this->assertSame(1, ProductFile::query()->where('product_id', $product->id)->count());
        $this->assertSame(1, (int) Asset::query()->whereKey($first->asset_id)->value('usage_count'),
            'عدّادٌ منتفخ يمنع حذف أصلٍ لم يعد مستعملًا');
    }

    /*
     * الصفّ القائم بالتجزئة نفسها يُعاد استعماله — لكنّ كائنه قد يكون فُقد.
     * كان الصفّ يبقى مشيرًا إلى العدم والنسخةُ الجديدة يتيمة، فيفشل كلّ
     * تحميل. واسمُ المزوّد كان حرفيًّا `garage_s3` لا يطابق أيّ مزوّد.
     */
    public function test_a_lost_object_is_replaced_by_the_fresh_copy_of_the_same_bytes(): void
    {
        $second = ['id' => 'DRIVE_FILE_2', 'version' => 3] + self::FILE;
        $this->fakeDriveFiles([
            'DRIVE_FILE_1' => [self::FILE, 'SAME-BYTES'],
            'DRIVE_FILE_2' => [$second, 'SAME-BYTES'],
        ]);
        $ingest = app(DriveAssetIngest::class);

        $first = $ingest->attach($this->makeProduct('TD-DRIVE-HEAL-1'), 'DRIVE_FILE_1');
        $asset = Asset::query()->findOrFail($first->asset_id);
        $this->assertSame('local', $asset->provider, 'اسمُ المزوّد من المزوّد الفعّال لا حرفيّ');
        Storage::disk('assets')->delete((string) $asset->provider_ref);

        $again = $ingest->attach($this->makeProduct('TD-DRIVE-HEAL-2'), 'DRIVE_FILE_2');

        $this->assertSame($asset->id, (int) $again->asset_id);
        $this->assertSame('SAME-BYTES', Storage::disk('assets')->get((string) $asset->refresh()->provider_ref));
    }

    public function test_a_native_google_doc_is_refused_before_anything_is_written(): void
    {
        $this->fakeDrive(['id' => 'DOC_1', 'name' => 'مستند', 'mimeType' => 'application/vnd.google-apps.document', 'version' => 1]);
        $product = $this->makeProduct('TD-DRIVE-3');

        try {
            app(DriveAssetIngest::class)->attach($product, 'DOC_1');
            $this->fail('مستند Google أصليّ يُنزَّل صفرَ بايت — ورفضُه شرطُ بيعٍ لا تفصيل.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fileId', $e->errors());
        }

        $this->assertSame(0, AssetSource::query()->count(),
            'لا صفّ يُكتب قبل أن تثبت الصلاحية');
    }

    public function test_a_missing_file_is_refused_and_leaves_no_row(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response([], 404)]);
        $product = $this->makeProduct('TD-DRIVE-4');

        $this->expectException(ValidationException::class);

        try {
            app(DriveAssetIngest::class)->attach($product, 'GONE');
        } finally {
            $this->assertSame(0, AssetSource::query()->count());
        }
    }

    public function test_replacing_a_source_keeps_the_product_and_swaps_only_the_file(): void
    {
        $this->fakeDriveFiles([
            'DRIVE_FILE_1' => [self::FILE, 'PDF-BYTES-HERE'],
            'DRIVE_FILE_2' => [['id' => 'DRIVE_FILE_2', 'name' => 'بديل.pdf',
                'mimeType' => 'application/pdf', 'size' => '4096', 'version' => 1], 'DIFFERENT-BYTES'],
        ]);
        $product = $this->makeProduct('TD-DRIVE-5');
        $ingest = app(DriveAssetIngest::class);
        $source = $ingest->attach($product, 'DRIVE_FILE_1');

        $replaced = $ingest->replace($source, 'DRIVE_FILE_2', $product);

        $this->assertSame($source->id, $replaced->id, 'المصدر هو هو — المعرّف وحده تبدّل');
        $this->assertSame('DRIVE_FILE_2', $replaced->external_id);
        $this->assertSame('CURRENT', $replaced->sync_state);
        $this->assertSame($product->id, (int) ProductFile::query()
            ->where('asset_id', $replaced->asset_id)->value('product_id'));
    }

    public function test_replacing_with_the_same_id_is_refused(): void
    {
        $this->fakeDrive();
        $product = $this->makeProduct('TD-DRIVE-6');
        $ingest = app(DriveAssetIngest::class);
        $source = $ingest->attach($product, 'DRIVE_FILE_1');

        $this->expectException(ValidationException::class);
        $ingest->replace($source, 'DRIVE_FILE_1', $product);
    }

    public function test_the_admin_surface_attaches_lists_and_never_leaks_a_credential(): void
    {
        $this->fakeDrive();
        $product = $this->makeProduct('TD-DRIVE-7');
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));

        $this->postJson("/api/v1/admin/products/{$product->id}/drive", [
            'fileId' => 'https://drive.google.com/file/d/DRIVE_FILE_1/view',
        ])->assertCreated()->assertJsonPath('data.fileId', 'DRIVE_FILE_1');

        $listing = $this->getJson("/api/v1/admin/products/{$product->id}/drive")->assertOk();

        $this->assertSame('DRIVE_FILE_1', $listing->json('data.0.fileId'));
        $this->assertTrue($listing->json('data.0.deliverable'));

        /* §١٥ — لا رمزَ ولا اعتمادَ ولا بنيةَ مجلّدات في أيّ ردّ. */
        $body = $listing->getContent();
        $this->assertStringNotContainsString('token-for-test', $body);
        $this->assertStringNotContainsString('access_token', $body);
        $this->assertStringNotContainsString('FOLDER_1', $body);
    }

    public function test_attaching_requires_the_update_permission_not_merely_read(): void
    {
        $this->fakeDrive();
        $product = $this->makeProduct('TD-DRIVE-8');
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $this->postJson("/api/v1/admin/products/{$product->id}/drive", ['fileId' => 'DRIVE_FILE_1'])
            ->assertForbidden();
    }

    public function test_drive_health_states_plainly_that_no_credential_is_configured(): void
    {
        config(['services.google_drive.access_token' => '']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));

        $this->getJson('/api/v1/admin/drive/health')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.reachable', false);
    }

    public function test_replacing_unlinks_the_old_file_so_it_stops_being_delivered(): void
    {
        $this->fakeDriveFiles([
            'DRIVE_FILE_1' => [self::FILE, 'PDF-BYTES-HERE'],
            'DRIVE_FILE_2' => [['id' => 'DRIVE_FILE_2', 'name' => 'بديل.pdf',
                'mimeType' => 'application/pdf', 'size' => '4096', 'version' => 1], 'DIFFERENT-BYTES'],
        ]);
        $product = $this->makeProduct('TD-DRIVE-9');
        $ingest = app(DriveAssetIngest::class);
        $source = $ingest->attach($product, 'DRIVE_FILE_1');
        $oldAssetId = (int) $source->asset_id;

        $replaced = $ingest->replace($source, 'DRIVE_FILE_2', $product);

        /*
         * من استبدل ملفًّا فعل ذلك لأنّه خاطئ أو قديم أو لا يملك حقّ نشره.
         * وربطُ الجديد بلا فكّ القديم يُبقي المستبدَل يُسلَّم إلى الأبد —
         * إجراءٌ صحيحٌ ظاهرًا بلا أثر.
         */
        $this->assertNotSame($oldAssetId, (int) $replaced->asset_id, 'الملفّ البديل أصلٌ آخر');

        $this->assertSame(0, ProductFile::query()
            ->where('product_id', $product->id)->where('asset_id', $oldAssetId)->count(),
            'الملفّ المستبدَل يُفكّ ربطُه — وإلّا بقي يُسلَّم');

        $this->assertSame(1, ProductFile::query()->where('product_id', $product->id)->count());
        $this->assertSame(0, (int) Asset::query()->whereKey($oldAssetId)->value('usage_count'),
            'عدّادٌ منتفخ يمنع حذف أصلٍ لم يعد مستعملًا');
        $this->assertSame('DRIVE_FILE_2', $replaced->external_id);
    }

    public function test_a_source_of_another_product_cannot_be_replaced_through_this_product(): void
    {
        $this->fakeDrive();
        $mine = $this->makeProduct('TD-DRIVE-10');
        $theirs = $this->makeProduct('TD-DRIVE-11');
        $ingest = app(DriveAssetIngest::class);
        $source = $ingest->attach($theirs, 'DRIVE_FILE_1');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));

        /* معرّفٌ في المسار لا يُقيَّد بصاحبه هو ثغرةُ مرجعٍ مباشر. */
        $this->patchJson("/api/v1/admin/products/{$mine->id}/drive/{$source->id}", [
            'fileId' => 'DRIVE_FILE_2',
        ])->assertNotFound();

        $this->assertSame('DRIVE_FILE_1', $source->refresh()->external_id);
    }

    public function test_a_variant_of_another_product_is_refused_by_field(): void
    {
        $this->fakeDrive();
        $mine = $this->makeProduct('TD-DRIVE-12');
        $theirs = $this->makeProduct('TD-DRIVE-13');

        $foreign = ProductVariant::query()
            ->where('legacy_product_id', $theirs->id)->firstOrFail();

        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));

        $this->postJson("/api/v1/admin/products/{$mine->id}/drive", [
            'fileId' => 'DRIVE_FILE_1', 'variantId' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['variantId']);
    }

    public function test_a_variant_that_does_not_exist_is_422_not_500(): void
    {
        $this->fakeDrive();
        $product = $this->makeProduct('TD-DRIVE-14');
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));

        /* القاعدة ٣٨: ما يمنعه المفتاح الأجنبيّ يمنعه التحقّق باسم حقله. */
        $this->postJson("/api/v1/admin/products/{$product->id}/drive", [
            'fileId' => 'DRIVE_FILE_1', 'variantId' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors(['variantId']);
    }

    public function test_replacing_keeps_the_files_own_role(): void
    {
        $this->fakeDriveFiles([
            'DRIVE_FILE_1' => [self::FILE, 'PDF-BYTES-HERE'],
            'DRIVE_FILE_2' => [['id' => 'DRIVE_FILE_2', 'name' => 'بديل.pdf',
                'mimeType' => 'application/pdf', 'size' => '4096', 'version' => 1], 'DIFFERENT-BYTES'],
        ]);
        $product = $this->makeProduct('TD-DRIVE-15');
        $ingest = app(DriveAssetIngest::class);
        $source = $ingest->attach($product, 'DRIVE_FILE_1', 'sample');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));
        $this->patchJson("/api/v1/admin/products/{$product->id}/drive/{$source->id}", [
            'fileId' => 'DRIVE_FILE_2',
        ])->assertOk();

        $this->assertSame('sample', ProductFile::query()
            ->where('product_id', $product->id)->value('role'),
            'الاستبدال يُبدّل الملفّ لا معناه — عيّنةٌ مجّانيّة لا تصير بضاعةً تُسلَّم بصمت');
    }
}
