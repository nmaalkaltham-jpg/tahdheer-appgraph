<?php

namespace App\Domain\Assets\Sources;

/**
 * صحّة المحوّل. `configured=false` ليست عطلًا بل غيابَ إعداد — والتمييز
 * مهمّ: العطل يُنبَّه عليه، وغياب الإعداد يُنتظر فيه قرار المالك.
 */
final readonly class SourceHealth
{
    public function __construct(
        public string $provider,
        public bool $configured,
        public bool $reachable,
        public ?string $detail = null,
    ) {}

    public function ok(): bool
    {
        return $this->configured && $this->reachable;
    }
}
