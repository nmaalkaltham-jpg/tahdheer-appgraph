<?php

namespace App\Domain\SEO;

/**
 * One indexability decision with the Arabic reason a human will read in the
 * admin inspector. A verdict is never returned without a reason.
 */
final class IndexabilityVerdict
{
    public const ELIGIBLE = 'eligible';

    public const MONITOR = 'monitor';

    public const EXCLUDED = 'excluded';

    private function __construct(
        public readonly string $verdict,
        public readonly string $reason,
        public readonly string $rule,
    ) {}

    public static function eligible(string $reason, string $rule): self
    {
        return new self(self::ELIGIBLE, $reason, $rule);
    }

    /** Real content that has not yet cleared a threshold — watched, not banned. */
    public static function monitor(string $reason, string $rule): self
    {
        return new self(self::MONITOR, $reason, $rule);
    }

    public static function excluded(string $reason, string $rule): self
    {
        return new self(self::EXCLUDED, $reason, $rule);
    }

    /** Only `eligible` URLs may enter a sitemap, llms.txt, IndexNow or the link graph. */
    public function isEligible(): bool
    {
        return $this->verdict === self::ELIGIBLE;
    }

    /** Robots directive implied by the verdict (§7.1 indexation matrix). */
    public function robots(): string
    {
        return $this->verdict === self::ELIGIBLE ? 'index, follow' : 'noindex, follow';
    }

    /** @return array{verdict:string, reason:string, rule:string} */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict,
            'reason' => $this->reason,
            'rule' => $this->rule,
        ];
    }
}
