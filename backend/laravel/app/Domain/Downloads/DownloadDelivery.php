<?php

namespace App\Domain\Downloads;

use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\Assets\StorageResolver;
use App\Models\Entitlement;
use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Secure delivery half of the download flow.
 *
 * EntitlementService decides WHO may download.
 * This class decides WHICH asset is delivered and HOW it is delivered.
 *
 * Security invariants:
 * - Raw object keys never become customer authorization.
 * - Raw grant tokens never leave the application authorization layer.
 * - Paid/private assets are never exposed through publicUrl().
 * - Streaming never loads the whole object into PHP memory.
 */
final class DownloadDelivery
{
    public function __construct(
        private readonly StorageResolver $storage,
    ) {}

    /**
     * Resolve the exact deliverable asset for an entitlement.
     *
     * Role priority is evaluated before variant specificity:
     * main > companion > teacher_guide > other.
     *
     * Within the same role, the purchased variant wins over the generic file.
     * A file for another variant is never eligible.
     */
    public function assetFor(Entitlement $entitlement): ?Asset
    {
        $variantId = $entitlement->orderItem?->product_variant_id;

        $link = ProductFile::query()
            ->where('product_id', $entitlement->product_id)
            ->whereIn('role', ProductFile::DELIVERABLE_ROLES)
            ->where(function ($query) use ($variantId): void {
                if ($variantId === null) {
                    $query->whereNull('product_variant_id');

                    return;
                }

                $query->where(function ($variantQuery) use ($variantId): void {
                    $variantQuery
                        ->whereNull('product_variant_id')
                        ->orWhere('product_variant_id', $variantId);
                });
            })
            ->orderByRaw(
                "CASE role
                    WHEN 'main' THEN 0
                    WHEN 'companion' THEN 1
                    WHEN 'teacher_guide' THEN 2
                    ELSE 3
                END"
            )
            ->orderByRaw('CASE WHEN product_variant_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        return $link?->asset()->first();
    }

    /**
     * Issue a customer-facing delivery URL for a paid asset.
     *
     * The raw grant token is never forwarded to S3/Garage/MinIO. If the
     * provider URL exposes the internal object key, delivery falls back to
     * the grant-bound signed application URL.
     */
    public function issueUrl(
        Asset $asset,
        string $rawGrantToken,
        int $ttlSeconds,
    ): string {
        $this->assertValidTtl($ttlSeconds);

        if ($rawGrantToken === '') {
            throw new RuntimeException('Download grant token is empty.');
        }

        $key = $this->assetKey($asset);

        try {
            $url = $this->storage
                ->provider()
                ->temporaryUrl($key, $ttlSeconds);
        } catch (StorageUnavailable) {
            return SignedDeliveryUrl::forSeconds($rawGrantToken, $ttlSeconds);
        }

        if (! $this->isSafeTemporaryUrl($url, $key, $rawGrantToken)) {
            return SignedDeliveryUrl::forSeconds($rawGrantToken, $ttlSeconds);
        }

        return $url;
    }

    /**
     * Issue a URL for genuinely public content only.
     *
     * @throws PaidContentViolation
     */
    public function publicUrl(Asset $asset, int $ttlSeconds): ?string
    {
        $this->assertValidTtl($ttlSeconds);

        if ($asset->isPaidPrivate()) {
            throw new PaidContentViolation((int) $asset->id);
        }

        try {
            $url = $this->storage
                ->provider()
                ->temporaryUrl($this->assetKey($asset), $ttlSeconds);
        } catch (StorageUnavailable) {
            return null;
        }

        return $this->isHttpUrl($url) ? $url : null;
    }

    /**
     * Stream the object without buffering the complete file in PHP memory.
     *
     * $onCompleted runs only after the stream reaches EOF successfully.
     * $onFailed runs for failures that happen after the response object has
     * already been created (for example a storage read failure mid-stream).
     *
     * Callback failures are logged and never corrupt the file response.
     *
     * @throws StorageUnavailable
     */
    public function stream(
        Asset $asset,
        ?Closure $onCompleted = null,
        ?Closure $onFailed = null,
    ): StreamedResponse {
        $stream = $this->storage
            ->provider()
            ->stream($this->assetKey($asset));

        if (! is_resource($stream)) {
            throw new RuntimeException('Storage provider returned an invalid stream.');
        }

        $headers = [
            'Content-Type' => (string) ($asset->mime ?: 'application/octet-stream'),
            'Content-Disposition' => self::contentDisposition($asset->downloadName()),
            'X-Content-Type-Options' => 'nosniff',
            'X-Download-Options' => 'noopen',
            'Cache-Control' => 'private, no-store, no-cache, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        if ((int) $asset->size_bytes > 0) {
            $headers['Content-Length'] = (string) $asset->size_bytes;
        }

        return response()->stream(
            function () use ($stream, $onCompleted, $onFailed): void {
                try {
                    $result = fpassthru($stream);

                    if ($result === false) {
                        throw new RuntimeException('Failed while streaming download.');
                    }

                    if (connection_status() !== CONNECTION_NORMAL) {
                        throw new RuntimeException('Download connection terminated before completion.');
                    }

                    $this->invokeCallbackSafely($onCompleted, 'completed');
                } catch (Throwable $e) {
                    $this->invokeCallbackSafely($onFailed, 'failed', $e);

                    throw $e;
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            },
            200,
            $headers,
        );
    }

    /**
     * RFC 6266 / RFC 5987 Content-Disposition with a safe ASCII fallback
     * and a UTF-8 filename for Arabic and other Unicode names.
     */
    public static function contentDisposition(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = str_replace(['/', '\\', '"'], ['_', '_', ''], $name);
        $name = trim($name);

        if ($name === '') {
            $name = 'download';
        }

        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? '';
        $ascii = trim($ascii, '_');

        if ($ascii === '' || preg_match('/^[A-Za-z0-9]/', $ascii) !== 1) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $ascii = 'download';

            if (
                $extension !== ''
                && preg_match('/^[A-Za-z0-9]+$/', $extension) === 1
            ) {
                $ascii .= '.'.$extension;
            }
        }

        return sprintf(
            "attachment; filename=\"%s\"; filename*=UTF-8''%s",
            $ascii,
            rawurlencode($name),
        );
    }

    private function isSafeTemporaryUrl(
        string $url,
        string $key,
        string $rawGrantToken,
    ): bool {
        return $this->isHttpUrl($url)
            && ! $this->containsSensitiveValue($url, $key, includeBasename: true)
            && ! $this->containsSensitiveValue($url, $rawGrantToken);
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * Detect a sensitive value in plain, URL-encoded, or repeatedly decoded
     * forms. For storage keys, the basename is sensitive as well.
     */
    private function containsSensitiveValue(
        string $url,
        string $value,
        bool $includeBasename = false,
    ): bool {
        if ($value === '') {
            return false;
        }

        $decodedOnce = rawurldecode($url);
        $decodedTwice = rawurldecode($decodedOnce);

        foreach ([$url, $decodedOnce, $decodedTwice] as $candidate) {
            if (str_contains($candidate, $value)) {
                return true;
            }
        }

        if (! $includeBasename) {
            return false;
        }

        $basename = basename(str_replace('\\', '/', $value));

        if ($basename === '') {
            return false;
        }

        foreach ([$url, $decodedOnce, $decodedTwice] as $candidate) {
            if (str_contains($candidate, $basename)) {
                return true;
            }
        }

        return false;
    }

    private function assetKey(Asset $asset): string
    {
        $key = trim((string) $asset->provider_ref);

        if ($key === '') {
            throw new RuntimeException(
                "Asset {$asset->id} has no storage provider reference."
            );
        }

        return $key;
    }

    private function assertValidTtl(int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            throw new RuntimeException('Download URL TTL must be greater than zero.');
        }
    }

    private function invokeCallbackSafely(
        ?Closure $callback,
        string $phase,
        ?Throwable $streamFailure = null,
    ): void {
        if ($callback === null) {
            return;
        }

        try {
            $streamFailure === null
                ? $callback()
                : $callback($streamFailure);
        } catch (Throwable $callbackFailure) {
            Log::error('downloads.stream_callback_failed', [
                'phase' => $phase,
                'exception' => $callbackFailure::class,
                'message' => $callbackFailure->getMessage(),
            ]);
        }
    }
}
