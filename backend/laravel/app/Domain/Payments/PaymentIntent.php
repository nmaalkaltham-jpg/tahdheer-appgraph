<?php

namespace App\Domain\Payments;

final readonly class PaymentIntent
{
    public function __construct(
        public string $provider,
        public string $intentId,
        public string $status,
        public ?string $payUrl = null,
    ) {}
}
