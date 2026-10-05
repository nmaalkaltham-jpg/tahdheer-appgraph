<?php

namespace App\Domain\Redirects;

/** A resolved redirect: where to go, with which status, and by which rule. */
final class RedirectMatch
{
    public function __construct(
        public readonly int $ruleId,
        public readonly string $source,
        public readonly string $target,
        public readonly int $statusCode,
    ) {}

    /** @return array{ruleId: int, source: string, target: string, statusCode: int} */
    public function toArray(): array
    {
        return [
            'ruleId' => $this->ruleId,
            'source' => $this->source,
            'target' => $this->target,
            'statusCode' => $this->statusCode,
        ];
    }
}
