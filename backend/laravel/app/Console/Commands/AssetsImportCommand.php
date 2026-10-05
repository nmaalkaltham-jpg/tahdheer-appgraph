<?php

namespace App\Console\Commands;

use App\Domain\Assets\AssetImporter;
use App\Domain\Assets\Exceptions\AssetInUse;
use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Assets\Models\ProductFile;
use App\Models\Product;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * CLI adapter for App\Domain\Assets\AssetImporter. Command classes are only
 * auto-discovered under app/Console/Commands, so the thin shell lives here and
 * every rule (dedupe, reference counting, archive-first) stays in the domain.
 */
class AssetsImportCommand extends Command
{
    protected $signature = 'assets:import
        {product : product id, slug, or SKU}
        {file : path to the source file}
        {--role=main : main|companion|sample|teacher_guide|answer_key}
        {--label= : customer-facing filename (defaults to the real file name)}
        {--visibility=paid_private : paid_private|public_sample}
        {--archive : archive the file currently holding this role instead of refusing}
        {--dry-run : اعرض ما سيقع (البصمة، الدور، ما سيُؤرشَف) بلا رفعٍ ولا كتابة}';

    protected $description = 'استيراد ملف منتج إلى التخزين ببصمة sha256 ومنع التكرار';

    public function handle(AssetImporter $importer): int
    {
        $product = $this->resolveProduct((string) $this->argument('product'));

        if ($product === null) {
            $this->components->error('المنتج غير موجود: '.$this->argument('product'));

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $path = (string) $this->argument('file');

            if (! is_file($path)) {
                $this->components->error('الملفّ غير موجود: '.$path);

                return self::FAILURE;
            }

            $holder = ProductFile::query()
                ->where('product_id', $product->id)->where('role', (string) $this->option('role'))->first();

            $this->components->twoColumnDetail('product', $product->id.' · '.$product->title);
            $this->components->twoColumnDetail('sha256', substr(hash_file('sha256', $path), 0, 16).'…');
            $this->components->twoColumnDetail('size', filesize($path).' bytes');
            $this->components->twoColumnDetail('role', (string) $this->option('role'));
            $this->components->twoColumnDetail('visibility', (string) $this->option('visibility'));
            $this->components->twoColumnDetail(
                'current holder',
                $holder === null ? 'لا شيء' : 'asset #'.$holder->asset_id.($this->option('archive') ? ' — سيُؤرشَف' : ' — سيُرفض بلا --archive'),
            );
            $this->components->info('تجربة جافّة: لم يُرفع شيء ولم يُكتب شيء.');

            return self::SUCCESS;
        }

        try {
            $result = $importer->import(
                product: $product,
                sourcePath: (string) $this->argument('file'),
                role: (string) $this->option('role'),
                customerLabel: $this->option('label') !== null ? (string) $this->option('label') : null,
                visibility: (string) $this->option('visibility'),
                archiveExisting: (bool) $this->option('archive'),
            );
        } catch (AssetInUse $e) {
            $this->components->error($e->getMessage().' (أعد التشغيل مع ‎--archive‎)');

            return self::FAILURE;
        } catch (StorageUnavailable $e) {
            $this->components->error('تعذّر التخزين: '.$e->reason);

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $asset = $result->asset;

        // The object key is deliberately absent from this output.
        $this->components->twoColumnDetail('asset id', (string) $asset->id);
        $this->components->twoColumnDetail('sha256', substr((string) $asset->sha256, 0, 16).'…');
        $this->components->twoColumnDetail('size', $asset->size_bytes.' bytes');
        $this->components->twoColumnDetail('mime', (string) $asset->mime);
        $this->components->twoColumnDetail('visibility', (string) $asset->visibility);
        $this->components->twoColumnDetail('usage_count', (string) $asset->usage_count);
        $this->components->twoColumnDetail('deduplicated', $result->deduplicated ? 'yes' : 'no');

        if ($result->archivedAssetId !== null) {
            $this->components->twoColumnDetail('archived asset id', (string) $result->archivedAssetId);
        }

        $this->components->info($result->linkCreated
            ? 'تم ربط الملف بالمنتج.'
            : 'الملف مرتبط بالمنتج مسبقًا — لا تغيير.');

        return self::SUCCESS;
    }

    private function resolveProduct(string $reference): ?Product
    {
        if (ctype_digit($reference)) {
            return Product::query()->find((int) $reference);
        }

        return Product::query()
            ->where('slug', $reference)
            ->orWhere('sku', $reference)
            ->first();
    }
}
