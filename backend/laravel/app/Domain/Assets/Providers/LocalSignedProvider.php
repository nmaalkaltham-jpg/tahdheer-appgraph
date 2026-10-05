<?php

namespace App\Domain\Assets\Providers;

use App\Domain\Assets\Contracts\StorageProvider;
use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Downloads\SignedDeliveryUrl;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * مزوّد التخزين المحلّي الموقّع — the DEV default, fully working with no
 * external service. Objects live under storage/app/private/assets on a disk
 * declared with 'serve' => false, so no /storage/{key} route can address a paid
 * original. Delivery is exclusively the grant-bound signed redeem endpoint,
 * which streams the bytes after re-validating the entitlement.
 */
final class LocalSignedProvider implements StorageProvider
{
    use ComputesObjectChecksum;

    public function __construct(private readonly string $disk) {}

    public function name(): string
    {
        return 'local';
    }

    public function put(string $key, mixed $contents): void
    {
        try {
            $ok = $this->disk()->put($key, $contents);
        } catch (Throwable) {
            throw new StorageUnavailable(StorageUnavailable::WRITE_FAILED);
        }

        if ($ok === false) {
            throw new StorageUnavailable(StorageUnavailable::WRITE_FAILED);
        }
    }

    /**
     * There is deliberately no key-addressable URL: the only way out of this
     * provider is a grant-bound signed link.
     */
    public function temporaryUrl(string $key, int $ttlSeconds, array $context = []): string
    {
        $grantToken = $context['grantToken'] ?? null;

        if (! is_string($grantToken) || $grantToken === '') {
            throw new StorageUnavailable(StorageUnavailable::GRANT_REQUIRED);
        }

        return SignedDeliveryUrl::forSeconds($grantToken, $ttlSeconds);
    }

    public function stream(string $key)
    {
        try {
            $stream = $this->disk()->readStream($key);
        } catch (Throwable $e) {
            /* لا استثناء يميّز الغياب عن تعذّر القراءة على القرص المحلّي، فيُسأل القرص مباشرة. */
            throw new StorageUnavailable(
                $this->absent($key) ? StorageUnavailable::MISSING_OBJECT : StorageUnavailable::DISK_UNAVAILABLE,
                $e,
            );
        }

        if (! is_resource($stream)) {
            throw new StorageUnavailable(StorageUnavailable::MISSING_OBJECT);
        }

        return $stream;
    }

    public function exists(string $key): bool
    {
        try {
            return $this->disk()->exists($key);
        } catch (Throwable $e) {
            throw new StorageUnavailable(StorageUnavailable::DISK_UNAVAILABLE, $e);
        }
    }

    public function delete(string $key): void
    {
        try {
            $this->disk()->delete($key);
        } catch (Throwable $e) {
            /* حذف Flysystem المحلّي يعود بلا أثر على ملفٍّ غائب، فالاستثناء عطلٌ لا غياب. */
            throw new StorageUnavailable(StorageUnavailable::DELETE_FAILED, $e);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /** الغياب المُثبَت فقط؛ تعذّر السؤال يعود false (= «لم يُثبَت الغياب»). */
    private function absent(string $key): bool
    {
        try {
            return ! $this->disk()->exists($key);
        } catch (Throwable) {
            return false;
        }
    }
}
