<?php

namespace App\Domain\Assets\Contracts;

use App\Domain\Assets\Exceptions\StorageUnavailable;

/**
 * محوّل التخزين — «منطق المنتج لا يعرف مزوّدًا بالاسم» (MIP §4.3).
 *
 * The product logic never names a provider. Every implementation stores opaque
 * object keys; the key itself is an internal secret and must never reach a
 * response body, a log line, or an error message.
 */
interface StorageProvider
{
    /** Stable provider identifier persisted on assets.provider (garage|local). */
    public function name(): string;

    /**
     * Store an object.
     *
     * @param  resource|string  $contents
     *
     * @throws StorageUnavailable
     */
    public function put(string $key, mixed $contents): void;

    /**
     * A URL that delivers the object for at most $ttlSeconds.
     *
     * $context may carry a 'grantToken' — the local provider delivers ONLY
     * through the grant-bound redeem endpoint and requires it.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws StorageUnavailable
     */
    public function temporaryUrl(string $key, int $ttlSeconds, array $context = []): string;

    /**
     * A read stream for the object.
     *
     * @return resource
     *
     * @throws StorageUnavailable
     */
    public function stream(string $key);

    /**
     * true/false فقط حين أمكن سؤال المزوّد فعلًا.
     *
     * @throws StorageUnavailable حين يتعذّر تحديد الوجود (عطل وصول أو إذن) —
     *                            «تعذّر السؤال» ليست «غير موجود».
     */
    public function exists(string $key): bool;

    /** @throws StorageUnavailable */
    public function delete(string $key): void;

    /**
     * sha256 of the STORED bytes — computed by streaming, never trusted from
     * provider metadata (S3 ETag is not a content hash for multipart uploads).
     *
     * @throws StorageUnavailable
     */
    public function checksum(string $key): string;
}
