<?php

namespace App\Domain\Payments;

/** ما قاله المزوّد عن طلب استرداد: هويّته عنده وحالته. */
final readonly class RefundResult
{
    public const SUCCEEDED = 'SUCCEEDED';

    public const PENDING = 'PENDING';

    public const FAILED = 'FAILED';

    /**
     * @param  self::SUCCEEDED|self::PENDING|self::FAILED  $status
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $providerRefundId,
        public string $status,
        public ?string $message = null,
        public array $raw = [],
    ) {}
}
