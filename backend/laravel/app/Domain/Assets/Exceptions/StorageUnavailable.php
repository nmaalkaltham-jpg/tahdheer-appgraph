<?php

namespace App\Domain\Assets\Exceptions;

use RuntimeException;

/**
 * Typed storage failure. The reason is an enum-like slug — never a message
 * carrying an object key, a bucket path, or a credential.
 */
class StorageUnavailable extends RuntimeException
{
    public const DRIVER_MISSING = 'driver_missing';       // flysystem s3 package absent

    public const NOT_CONFIGURED = 'not_configured';       // env incomplete

    public const DISK_UNAVAILABLE = 'disk_unavailable';   // disk could not be built

    public const MISSING_OBJECT = 'missing_object';       // key not found on the provider

    public const WRITE_FAILED = 'write_failed';

    public const DELETE_FAILED = 'delete_failed';       // الحذف تعذّر — لا يعني أن المفتاح غائب

    public const GRANT_REQUIRED = 'grant_required';       // local delivery needs a grant token

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct("Storage unavailable: {$reason}", 0, $previous);
    }
}
