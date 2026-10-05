<?php

namespace App\Domain\Risk;

/**
 * نتيجة تقييمٍ واحد (§١٠٣).
 *
 * تحمل القرار والإشارات معًا: القرار وحده يُنفَّذ ولا يُفسَّر، والإشارات
 * وحدها تُقرأ ولا تُنفَّذ.
 */
final readonly class RiskDecision
{
    /** @param list<RiskSignal> $signals */
    public function __construct(
        public string $decision,
        public array $signals,
        public int $score,
        public int $assessmentId,
    ) {}

    public function blocks(): bool
    {
        return $this->decision === 'DENY';
    }

    /** ما يُعرض للزبون — سببٌ واحد لا قائمةُ إشارات. */
    public function customerReason(): ?string
    {
        return $this->signals === [] ? null : $this->signals[0]->description;
    }
}
