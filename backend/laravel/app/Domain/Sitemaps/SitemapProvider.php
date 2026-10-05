<?php

namespace App\Domain\Sitemaps;

/**
 * مزوّد روابط لخريطة الموقع.
 *
 * `entries()` MUST be a generator over a chunked query: the builder streams
 * hundreds of thousands of URLs without materialising them (§7.3 engine
 * internals). A provider whose storefront route is not built yet reports
 * `isEnabled() === false` with an Arabic reason instead of emitting URLs that
 * would 404.
 */
interface SitemapProvider
{
    /** Stable machine name, used as the sitemap part filename and API segment. */
    public function name(): string;

    /** Arabic label for the admin/audit report. */
    public function label(): string;

    public function isEnabled(): bool;

    /** Why this provider yields nothing, or null when it is enabled. */
    public function dormantReason(): ?string;

    /** @return iterable<SitemapEntry> */
    public function entries(): iterable;
}
