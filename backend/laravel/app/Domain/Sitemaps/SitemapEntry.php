<?php

namespace App\Domain\Sitemaps;

use Carbon\CarbonInterface;

/**
 * مُدخل واحد في خريطة الموقع.
 *
 * `contentHash` — not a timestamp — is what a provider supplies. lastmod is
 * DERIVED from that hash by {@see LastmodLedger}, which is the whole point of
 * MIP §7.3.7: a rebuild that changes nothing must not move any lastmod.
 *
 * `changefreq` and `priority` are nullable and stay null unless a REAL signal
 * justifies them (§7.3.20 «unmeasured = غير مقيس, never 0»). This codebase does
 * not invent crawl priorities.
 */
final class SitemapEntry
{
    public function __construct(
        public readonly string $loc,
        public readonly string $contentHash,
        public readonly ?string $changefreq = null,
        public readonly ?float $priority = null,
        public ?CarbonInterface $lastmod = null,
    ) {}

    /** Hash the fields a reader would notice; nothing else may move lastmod. */
    public static function hash(mixed ...$parts): string
    {
        return hash('sha256', implode("\x1f", array_map(
            static fn ($part) => $part === null ? "\x00" : (string) (is_bool($part) ? (int) $part : $part),
            $parts,
        )));
    }

    /** @return array<string, mixed> camelCase for the API boundary. */
    public function toArray(): array
    {
        $out = ['loc' => $this->loc, 'lastmod' => $this->lastmod?->toIso8601String()];

        if ($this->changefreq !== null) {
            $out['changefreq'] = $this->changefreq;
        }

        if ($this->priority !== null) {
            $out['priority'] = $this->priority;
        }

        return $out;
    }
}
