<?php

namespace App\Domain\Assets;

use App\Domain\Assets\Contracts\StorageProvider;
use App\Domain\Assets\Exceptions\AssetInUse;
use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\ProductFile;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\Mime\MimeTypes;

/**
 * استيراد الأصول — ingestion with content dedupe and reference counting
 * (MIP §4.3): sha256 is the identity, usage_count is the reference count, and a
 * referenced file is archived rather than overwritten.
 */
class AssetImporter
{
    public const VISIBILITIES = ['public_sample', 'paid_private'];

    public function __construct(private readonly StorageResolver $storage) {}

    /**
     * Store $sourcePath and attach it to $product under $role.
     *
     * @throws InvalidArgumentException bad input
     * @throws AssetInUse the role already holds a referenced asset
     * @throws StorageUnavailable the object could not be stored intact
     */
    public function import(
        Product $product,
        string $sourcePath,
        string $role = 'main',
        ?string $customerLabel = null,
        string $visibility = 'paid_private',
        bool $archiveExisting = false,
    ): AssetImportResult {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new InvalidArgumentException('Source file not found or unreadable.');
        }
        if (! in_array($visibility, self::VISIBILITIES, true)) {
            throw new InvalidArgumentException('Unknown visibility: '.$visibility);
        }

        $sha = (string) hash_file('sha256', $sourcePath);
        $originalName = basename($sourcePath);

        $existing = Asset::query()->where('sha256', $sha)->first();
        $link = ProductFile::query()
            ->where('product_id', $product->id)
            ->where('role', $role)
            ->first();

        /*
         * The sha256 row proves the RECORD exists, not the OBJECT. A lost
         * object (storage volume recreated, write lost) made every download
         * fail as FILE_MISSING, while re-uploading the very same file — the
         * natural repair — returned "deduplicated" and wrote nothing. The
         * bytes in hand hash to the row's sha256, so restoring them is exact.
         */
        if ($existing !== null) {
            $this->restoreIfLost($existing, $sourcePath, $sha);
        }

        // Same file, same role → already done. Import is idempotent.
        if ($link !== null && $existing !== null && (int) $link->asset_id === (int) $existing->id) {
            return new AssetImportResult($existing, deduplicated: true, linkCreated: false);
        }

        $archivedAssetId = null;
        if ($link !== null) {
            $archivedAssetId = $this->archiveOrRefuse($link, $archiveExisting);
        }

        $asset = $existing ?? $this->store($sourcePath, $sha, $originalName, $customerLabel, $visibility);

        DB::transaction(function () use ($product, $asset, $role) {
            ProductFile::query()->create([
                'product_id' => $product->id,
                'asset_id' => $asset->id,
                'role' => $role,
                'position' => (int) ProductFile::query()->where('product_id', $product->id)->max('position') + 1,
            ]);

            $asset->increment('usage_count');
        });

        return new AssetImportResult(
            $asset->refresh(),
            deduplicated: $existing !== null,
            linkCreated: true,
            archivedAssetId: $archivedAssetId,
        );
    }

    /**
     * Remove an asset and its object. Blocked while referenced — archive first.
     *
     * @throws AssetInUse
     */
    public function delete(Asset $asset): void
    {
        if ($asset->usage_count > 0) {
            throw new AssetInUse((int) $asset->id, (int) $asset->usage_count);
        }

        /*
         * الصفّ أوّلًا ثمّ الكائن. العكس كان يترك — إن فشل حذف الصفّ — صفًّا
         * يقول «الملفّ موجود» وتخزينًا فارغًا: عطلٌ يراه الزبون. أمّا كائنٌ
         * يتيمٌ بلا صفّ فيلتقطه `assets:reconcile` ولا يضرّ أحدًا.
         */
        $ref = (string) $asset->provider_ref;
        DB::transaction(fn () => $asset->delete());

        try {
            $this->storage->provider()->delete($ref);
        } catch (\Throwable $e) {
            Log::warning('assets.object_orphaned', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /** The object key: content-addressed, sharded, and never customer-visible. */
    public static function objectKey(string $sha256, string $originalName): string
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: '';

        return sprintf(
            'assets/%s/%s/%s%s',
            substr($sha256, 0, 2),
            substr($sha256, 2, 2),
            $sha256,
            $extension === '' ? '' : '.'.$extension,
        );
    }

    /** @return int|null the archived asset id, when one was displaced */
    private function archiveOrRefuse(ProductFile $link, bool $archiveExisting): ?int
    {
        $current = Asset::query()->find($link->asset_id);

        if ($current !== null && $current->usage_count > 0 && ! $archiveExisting) {
            throw new AssetInUse((int) $current->id, (int) $current->usage_count);
        }

        // archive-first: the old link keeps existing (audit) but stops being
        // delivered; if this product already archived this exact asset, the
        // duplicate row would violate the (product, asset, role) unique key, so
        // the stale link is dropped and its reference released instead.
        $alreadyArchived = ProductFile::query()
            ->where('product_id', $link->product_id)
            ->where('asset_id', $link->asset_id)
            ->where('role', ProductFile::ROLE_ARCHIVED)
            ->exists();

        DB::transaction(function () use ($link, $current, $alreadyArchived) {
            if ($alreadyArchived) {
                $link->delete();
                if ($current !== null && $current->usage_count > 0) {
                    $current->decrement('usage_count');
                }

                return;
            }

            $link->update(['role' => ProductFile::ROLE_ARCHIVED]);
        });

        return $current?->id;
    }

    /**
     * An existing asset whose object vanished from the active backend gets its
     * exact bytes back. Another backend's object is out of reach from here.
     */
    private function restoreIfLost(Asset $asset, string $sourcePath, string $sha): void
    {
        $provider = $this->storage->provider();
        $key = (string) $asset->provider_ref;

        if ($asset->provider !== $provider->name() || $provider->exists($key)) {
            return;
        }

        $this->writeVerified($provider, $key, $sourcePath, $sha);

        Log::warning('assets.object_restored', ['asset_id' => $asset->id]);
    }

    /** Writes the object if absent, then proves its integrity from the stored bytes. */
    private function writeVerified(StorageProvider $provider, string $key, string $sourcePath, string $sha): void
    {
        if (! $provider->exists($key)) {
            $stream = fopen($sourcePath, 'rb');
            if ($stream === false) {
                throw new StorageUnavailable(StorageUnavailable::WRITE_FAILED);
            }

            try {
                $provider->put($key, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        // Integrity is proven from the STORED bytes, never assumed.
        if (! hash_equals($sha, $provider->checksum($key))) {
            $provider->delete($key);

            throw new StorageUnavailable(StorageUnavailable::WRITE_FAILED);
        }
    }

    private function store(
        string $sourcePath,
        string $sha,
        string $originalName,
        ?string $customerLabel,
        string $visibility,
    ): Asset {
        $provider = $this->storage->provider();
        $key = self::objectKey($sha, $originalName);

        $this->writeVerified($provider, $key, $sourcePath, $sha);

        return Asset::query()->create([
            'provider' => $provider->name(),
            'provider_ref' => $key,
            'original_name' => $originalName,
            'customer_label' => $customerLabel,
            'sha256' => $sha,
            'size_bytes' => (int) filesize($sourcePath),
            'mime' => self::guessMime($sourcePath, $originalName),
            'visibility' => $visibility,
            'usage_count' => 0,
        ]);
    }

    private static function guessMime(string $sourcePath, string $originalName): string
    {
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension !== '') {
            $byExtension = MimeTypes::getDefault()->getMimeTypes($extension);
            if ($byExtension !== []) {
                return $byExtension[0];
            }
        }

        return MimeTypes::getDefault()->guessMimeType($sourcePath) ?? 'application/octet-stream';
    }
}
