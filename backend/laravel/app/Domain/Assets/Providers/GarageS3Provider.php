<?php

namespace App\Domain\Assets\Providers;

use App\Domain\Assets\Contracts\StorageProvider;
use App\Domain\Assets\Exceptions\StorageUnavailable;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Throwable;

/**
 * Garage 2.x (S3-compatible, MIP §4.3) over Laravel's native s3 disk driver.
 *
 * league/flysystem-aws-s3-v3 is required in composer.json and pinned at 3.35.3
 * in composer.lock. The class_exists check in the constructor remains as a
 * defence: a build that somehow lacks the adapter or the AWS SDK throws a typed
 * StorageUnavailable instead of failing deep inside Laravel's s3 driver.
 * Incomplete credentials throw the same way, and StorageResolver falls back to
 * the local disk — loudly, once, never silently. No fabricated "cloud is ready"
 * state.
 */
final class GarageS3Provider implements StorageProvider
{
    use ComputesObjectChecksum;

    public function __construct(private readonly string $disk)
    {
        // league/flysystem ships the adapter source in its monorepo, so the
        // adapter class alone proves nothing — the AWS SDK client is the real
        // dependency Laravel's s3 driver instantiates.
        if (! class_exists(AwsS3V3Adapter::class) || ! class_exists(S3Client::class)) {
            throw new StorageUnavailable(StorageUnavailable::DRIVER_MISSING);
        }

        /** @var array<string, mixed> $config */
        $config = (array) config("filesystems.disks.{$this->disk}", []);

        foreach (['key', 'secret', 'bucket', 'endpoint'] as $required) {
            if (blank($config[$required] ?? null)) {
                // The missing setting name is safe to omit — never echo values.
                throw new StorageUnavailable(StorageUnavailable::NOT_CONFIGURED);
            }
        }

        try {
            Storage::disk($this->disk);
        } catch (Throwable) {
            throw new StorageUnavailable(StorageUnavailable::DISK_UNAVAILABLE);
        }
    }

    public function name(): string
    {
        return 'garage';
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
     * A short-lived presigned Garage URL (MIP §4.6). It necessarily contains
     * the object key, which is why DownloadDelivery refuses to hand a
     * key-bearing URL to a customer for a paid_private asset and proxies the
     * stream through the redeem endpoint instead.
     */
    public function temporaryUrl(string $key, int $ttlSeconds, array $context = []): string
    {
        try {
            return $this->disk()->temporaryUrl($key, Carbon::now()->addSeconds(max(1, $ttlSeconds)));
        } catch (Throwable) {
            throw new StorageUnavailable(StorageUnavailable::DISK_UNAVAILABLE);
        }
    }

    public function stream(string $key)
    {
        try {
            $stream = $this->disk()->readStream($key);
        } catch (Throwable $e) {
            throw new StorageUnavailable(
                $this->isNotFound($e) ? StorageUnavailable::MISSING_OBJECT : StorageUnavailable::DISK_UNAVAILABLE,
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
            /*
             * «تعذّر السؤال» ليست «غير موجود»: مفتاحٌ غائب يعود false بلا
             * استثناء، فكل استثناءٍ هنا عطلُ وصولٍ أو إذن.
             */
            throw new StorageUnavailable(StorageUnavailable::DISK_UNAVAILABLE, $e);
        }
    }

    public function delete(string $key): void
    {
        try {
            $this->disk()->delete($key);
        } catch (Throwable $e) {
            /* حذف S3 عديم الأثر على مفتاحٍ غائب، فالاستثناء هنا عطلٌ لا غياب. */
            throw new StorageUnavailable(StorageUnavailable::DELETE_FAILED, $e);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /** غياب المفتاح عند S3 يُميَّز بالحالة ٤٠٤ أو رمز الخطأ — لا بنصّ الرسالة. */
    private function isNotFound(Throwable $e): bool
    {
        for ($c = $e; $c !== null; $c = $c->getPrevious()) {
            if ($c instanceof S3Exception) {
                return $c->getStatusCode() === 404
                    || in_array($c->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true);
            }
        }

        return false;
    }
}
