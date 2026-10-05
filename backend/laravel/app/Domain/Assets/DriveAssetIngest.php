<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use App\Domain\Assets\Exceptions\SourceUnavailable;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Assets\Models\ProductFile;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ربط ملفّ Google Drive بمنتج — الباب الذي لم يكن للمحوّل (§١٣ §١٤ §١٥).
 *
 * ─── العيب الذي يزيله ───
 *
 * `GoogleDriveSource` محوّلٌ كامل: يميّز ٤٠٤ من ٤٠٣ من ٤٠١، ويرفض مستندات
 * Google الأصليّة، ويستورد بمفتاحٍ يحمل المراجعة. و`SyncGovernor` يحوكم
 * حالته، و`VerifyAssetSourceJob` يعيد التحقّق، ولوحةُ المشغّل تعرض النتيجة.
 * وكان كلّ ذلك **غير قابلٍ للبلوغ**: لا سطر في التطبيق يُنشئ صفًّا في
 * `asset_sources`. الإدراج الوحيد ترحيلُ ردمٍ يُنفَّذ مرّة. أي أنّ Drive كان
 * مبنيًّا ومختبَرًا وبلا مدخل.
 *
 * ─── المعرّف هو الهويّة، لا الرابط ───
 *
 * المدخل `fileId` لا عنوان URL. الرابط يتغيّر بتغيّر المشاركة وبنسخ العنوان
 * من المتصفّح ويحمل معاملاتٍ لا تعني شيئًا؛ والمعرّف يبقى عبر إعادة التسمية
 * والنقل. ولذلك يُستخرج المعرّف من رابطٍ إن لُصق رابط — تسهيلًا للمشغّل —
 * ثمّ **لا يُخزَّن الرابط أبدًا**.
 *
 * ─── وما يقع قبل الربط لا بعده ───
 *
 * الصلاحية تُفحص الآن: موجودٌ، ومقروء، وليس مستند Google أصليًّا، وحجمه غير
 * صفر. فحصُها عند الشراء وحده كان يعني بيع ملفٍّ لا يُسلَّم — والاكتشاف بعد
 * أخذ المال أغلى من رفضٍ عند الربط.
 */
final class DriveAssetIngest
{
    public const PROVIDER = 'GOOGLE_DRIVE';

    public function __construct(
        private readonly AssetSourceResolver $sources,
        private readonly SyncGovernor $governor,
        private readonly StorageResolver $storage,
    ) {}

    /**
     * يستخرج معرّف الملفّ من رابط Drive، أو يُعيد المدخل كما هو.
     *
     * الأشكال المدعومة هي ما يلصقه المشغّل فعلًا من المتصفّح:
     *   /file/d/{id}/view      ·  ?id={id}       ·  /folders/{id}
     * وما لا يُطابق يُعامَل معرّفًا خامًّا — وسيرفضه Drive إن لم يكن.
     */
    public static function fileIdFrom(string $input): string
    {
        $input = trim($input);

        foreach (['#/file/d/([A-Za-z0-9_-]{10,})#', '#[?&]id=([A-Za-z0-9_-]{10,})#', '#/folders/([A-Za-z0-9_-]{10,})#'] as $pattern) {
            if (preg_match($pattern, $input, $matches) === 1) {
                return $matches[1];
            }
        }

        return $input;
    }

    /**
     * يربط ملفّ Drive بمنتج: يتحقّق، ثمّ يجلب، ثمّ يُسجّل، ثمّ يربط.
     *
     * والترتيب مقصود: لا صفّ يُكتب قبل أن تثبت الصلاحية، فلا يبقى في السجلّ
     * مصدرٌ لملفٍّ لا يصلح للبيع.
     *
     * @param  string  $role  دور الملفّ في المنتج (`main` · `sample` …)
     */
    public function attach(
        Product $product,
        string $fileIdOrUrl,
        string $role = 'main',
        ?int $variantId = null,
        ?string $customerLabel = null,
    ): AssetSource {
        $fileId = self::fileIdFrom($fileIdOrUrl);

        if ($fileId === '') {
            throw ValidationException::withMessages([
                'fileId' => ['أدخل معرّف ملفّ Drive أو رابطه.'],
            ]);
        }

        $adapter = $this->sources->for(self::PROVIDER);

        /*
         * مصدرٌ مسجَّل سلفًا لا يُستورَد ثانيةً.
         *
         * `(provider, external_id)` فريدٌ في المخطّط، فالاستيراد الثاني كان
         * سيصطدم بالقيد بعد أن يكون قد نزّل الملفّ كاملًا — تكلفةُ شبكةٍ
         * ثمّ خطأُ خادم. والربط يُعاد استعماله بدل ذلك، وهو ما يجعل الطلب
         * المكرَّر بلا أثرٍ إضافيّ.
         */
        $existing = AssetSource::query()
            ->where('provider', self::PROVIDER)
            ->where('external_id', $fileId)
            ->first();

        if ($existing !== null && $existing->asset_id !== null) {
            $this->link($product, (int) $existing->asset_id, $role, $variantId);

            return $this->governor->refresh($existing, 'manual');
        }

        $problems = $adapter->validate($fileId);

        if ($problems !== []) {
            throw ValidationException::withMessages(['fileId' => $problems]);
        }

        $meta = $adapter->getMetadata($fileId);

        try {
            $imported = $adapter->import($fileId);
        } catch (SourceUnavailable $e) {
            throw ValidationException::withMessages(['fileId' => [$e->getMessage()]]);
        }

        return DB::transaction(function () use (
            $product, $fileId, $role, $variantId, $customerLabel, $meta, $imported, $existing
        ): AssetSource {
            /*
             * الأصل يُعرَّف بمحتواه: ملفّان متطابقان عند مزوّدين مختلفين أصلٌ
             * واحد. `sha256` فريدٌ في `assets`، فالبحث قبل الإنشاء يمنع
             * اصطدامًا ويوفّر تخزينًا.
             */
            $asset = $this->assetFor($imported, $meta->fileName ?? $fileId, $meta->mimeType, $customerLabel);

            $source = $existing ?? new AssetSource(['provider' => self::PROVIDER, 'external_id' => $fileId]);

            $source->fill([
                'asset_id' => $asset->id,
                /*
                 * §١٤ IMPORT_ONCE: نملك نسخةً، فالتسليم لا يمرّ بـDrive وقت
                 * الشراء. اعتماد Drive لحظة التنزيل كان يجعل عطلًا عندهم
                 * عطلًا عندنا، ويربط تسليمَ ما بيع بصلاحيةٍ قد تُسحب.
                 */
                'delivery_mode' => 'IMPORT_ONCE',
                'external_parent_id' => $meta->parentId,
                'file_name' => $meta->fileName,
                'mime_type' => $meta->mimeType,
                'size_bytes' => $imported['size_bytes'],
                'source_revision' => $meta->revision,
                'imported_revision' => $meta->revision,
                'source_modified_at' => $meta->modifiedAt,
                'content_hash' => $imported['sha256'],
                'sync_state' => 'CURRENT',
                'last_sync_at' => now(),
                'last_verified_at' => now(),
                'last_error' => null,
                'failed_attempts' => 0,
            ]);

            $source->save();

            $this->link($product, (int) $asset->id, $role, $variantId);

            return $source->refresh();
        });
    }

    /**
     * يستبدل ملفّ المصدر بآخر دون هدم المنتج (§١٦).
     *
     * لوحة المشغّل كانت تقول «استبدل المصدر» ولا تعرض فعلًا يفعله. والحاجة
     * حقيقيّة: ملفٌّ حُذف من Drive أو نُقل خارج نطاقنا يترك المنتج غير قابلٍ
     * للتسليم، والبديل الوحيد كان حذف المنتج وإعادة بنائه — فتضيع مراجعاته
     * وروابطه وتاريخه.
     *
     * والمعرّف وحده يتغيّر: الأصل الجديد يُسجَّل، والمنتج يبقى هو.
     */
    public function replace(AssetSource $source, string $fileIdOrUrl, Product $product, string $role = 'main'): AssetSource
    {
        $fileId = self::fileIdFrom($fileIdOrUrl);

        if ($fileId === $source->external_id) {
            throw ValidationException::withMessages([
                'fileId' => ['هذا هو المعرّف نفسه — الاستبدال يوجب ملفًّا آخر.'],
            ]);
        }

        $clash = AssetSource::query()
            ->where('provider', self::PROVIDER)
            ->where('external_id', $fileId)
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'fileId' => ['هذا الملفّ مسجَّلٌ مصدرًا آخر — لا يُسجَّل الملفّ الواحد مرّتين.'],
            ]);
        }

        $adapter = $this->sources->for(self::PROVIDER);
        $problems = $adapter->validate($fileId);

        if ($problems !== []) {
            throw ValidationException::withMessages(['fileId' => $problems]);
        }

        $meta = $adapter->getMetadata($fileId);
        $imported = $adapter->import($fileId);

        /* الأصل القديم يُعرف قبل أن يُدهَس المرجع — بعده لا سبيل إلى فكّه. */
        $previousAssetId = $source->asset_id === null ? null : (int) $source->asset_id;

        return DB::transaction(function () use ($source, $fileId, $meta, $imported, $product, $role, $previousAssetId): AssetSource {
            $asset = $this->assetFor($imported, $meta->fileName ?? $fileId, $meta->mimeType, null);

            $source->fill([
                'external_id' => $fileId,
                'asset_id' => $asset->id,
                'external_parent_id' => $meta->parentId,
                'file_name' => $meta->fileName,
                'mime_type' => $meta->mimeType,
                'size_bytes' => $imported['size_bytes'],
                'source_revision' => $meta->revision,
                'imported_revision' => $meta->revision,
                'source_modified_at' => $meta->modifiedAt,
                'content_hash' => $imported['sha256'],
                'sync_state' => 'CURRENT',
                'last_error' => null,
                'failed_attempts' => 0,
                'last_sync_at' => now(),
                'last_verified_at' => now(),
            ]);
            $source->save();

            $this->link($product, (int) $asset->id, $role, null);

            /*
             * ─── والقديم يُفكّ، وهو جوهر «الاستبدال» ───
             *
             * الربط وحده كان يترك **ملفّين** تحت الدور نفسه: الفهرس الفريد
             * `(product_id, asset_id, role)` يسمح بذلك لأنّ الأصل مختلف.
             * فيبقى الملفّ المستبدَل قابلًا للتسليم إلى الأبد — ومن استبدله
             * فعل ذلك لأنّه خاطئ أو قديم أو لا يملك حقّ نشره. وذاك أسوأ ما
             * يقع: إجراءٌ صحيحٌ ظاهرًا لا يُنتج أثره.
             *
             * ويُنقَص عدّاد الاستعمال معه: عدّادٌ منتفخ يمنع حذف أصلٍ لم يعد
             * مستعملًا.
             */
            if ($previousAssetId !== null && $previousAssetId !== (int) $asset->id) {
                $unlinked = ProductFile::query()
                    ->where('product_id', $product->id)
                    ->where('asset_id', $previousAssetId)
                    ->delete();

                if ($unlinked > 0) {
                    Asset::query()->whereKey($previousAssetId)
                        ->where('usage_count', '>', 0)
                        ->decrement('usage_count', $unlinked);
                }
            }

            return $source->refresh();
        });
    }

    /**
     * يربط الأصل بالمنتج تحت دورٍ، مرّةً واحدة.
     *
     * `firstOrCreate` على (المنتج، الدور، الأصل): إعادة النداء لا تُنشئ
     * سطرًا ثانيًا ولا تزيد عدّاد الاستعمال مرّتين — وعدّادٌ منتفخ يمنع حذف
     * أصلٍ لم يعد مستعملًا.
     */
    private function link(Product $product, int $assetId, string $role, ?int $variantId): void
    {
        $file = ProductFile::query()->firstOrCreate(
            ['product_id' => $product->id, 'asset_id' => $assetId, 'role' => $role],
            [
                'position' => (int) ProductFile::query()->where('product_id', $product->id)->max('position') + 1,
                'product_variant_id' => $variantId,
            ],
        );

        if ($file->wasRecentlyCreated) {
            Asset::query()->whereKey($assetId)->increment('usage_count');

            return;
        }

        /* صيغةٌ مُرسَلة على ربطٍ قائم تُحدَّث — وإلّا بقي الملفّ عامًّا بلا سبب. */
        if ($variantId !== null && (int) $file->product_variant_id !== $variantId) {
            $file->product_variant_id = $variantId;
            $file->save();
        }
    }

    /**
     * الأصل يُعرَّف بمحتواه، والصفّ القائم يُعاد استعماله — لكنّ الصفّ يُثبت
     * السجلّ لا الكائن. إن فُقد كائنُه من المزوّد الفعّال، فالنسخة التي كُتبت
     * للتوّ بالتجزئة نفسها هي البايتات نفسها: يُحال الصفّ إليها بدل أن يبقى
     * مشيرًا إلى عدمٍ ويُترك الجديد يتيمًا. واسمُ المزوّد من المزوّد نفسه —
     * كان حرفيًّا `garage_s3` لا يطابق أيّ مزوّد.
     *
     * @param  array{key: string, sha256: string, size_bytes: int}  $imported
     */
    private function assetFor(array $imported, string $originalName, ?string $mime, ?string $customerLabel): Asset
    {
        $provider = $this->storage->provider();
        $asset = Asset::query()->where('sha256', $imported['sha256'])->first();

        if ($asset === null) {
            return Asset::query()->create([
                'provider' => $provider->name(),
                'provider_ref' => $imported['key'],
                'original_name' => $originalName,
                'customer_label' => $customerLabel,
                'sha256' => $imported['sha256'],
                'size_bytes' => $imported['size_bytes'],
                'mime' => $mime,
                'visibility' => 'paid_private',
                'usage_count' => 0,
            ]);
        }

        if ($asset->provider_ref !== $imported['key'] && ! $provider->exists((string) $asset->provider_ref)) {
            $asset->update(['provider' => $provider->name(), 'provider_ref' => $imported['key']]);
            Log::warning('assets.object_restored', ['asset_id' => $asset->id]);
        }

        return $asset;
    }
}
