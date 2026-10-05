<?php

namespace App\Domain\Payments;

/** حالة رسمٍ كما يقرؤها سؤالُ المزوّد المباشر (لا خطّافه). */
final readonly class ProviderCharge
{
    public const CAPTURED = 'CAPTURED';

    /** ما زال ممكنًا أن يُدفع (INITIATED وأمثاله). */
    public const OPEN = 'OPEN';

    /** نهائيٌّ بلا مال: رُفض، أُلغي، انتهت مهلته. */
    public const FAILED = 'FAILED';

    /** حالةٌ لا نعرفها — لا تُعامل نجاحًا ولا فشلًا. */
    public const UNKNOWN = 'UNKNOWN';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $chargeId,
        public string $status,
        public string $providerStatus,
        public ?int $amountMinor,
        public ?string $currency,
        public ?string $amountProblem,
        public array $raw = [],
        public ?string $merchantId = null,
        /** @var list<array{id: string, amountMinor: int|null, currency: string|null, status: string}> */
        public array $refunds = [],
    ) {}
}
