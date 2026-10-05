<?php

namespace App\Domain\Downloads;

/**
 * Contract deny reasons for the download endpoints —
 * maps EntitlementService's internal DownloadDenied reasons.
 *
 * These slugs are the whole story a client is told. Nothing about storage
 * layout, object keys, or bucket paths is ever added to them.
 */
final class DenyReason
{
    public const FORBIDDEN = 'forbidden';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const LIMIT = 'limit';

    public const UNPAID = 'unpaid';

    /** The delivery link was unsigned, tampered with, or bound to another grant. */
    public const INVALID_SIGNATURE = 'invalid_signature';

    /** No file is attached to the product yet — an honest empty state. */
    public const NOT_AVAILABLE = 'not_available';

    /** The object is configured but the provider could not serve it. */
    public const STORAGE_UNAVAILABLE = 'storage_unavailable';

    private const MAP = [
        'not_active' => self::REVOKED,
        'expired' => self::EXPIRED,
        'limit_exhausted' => self::LIMIT,
        'order_not_paid' => self::UNPAID,
    ];

    public static function fromInternal(string $reason): string
    {
        return self::MAP[$reason] ?? self::FORBIDDEN;
    }
}
