<?php

namespace App\Domain\Sitemaps\Providers;

use App\Domain\SEO\IndexabilityPolicy;
use App\Domain\SEO\RouteRegistry;
use App\Domain\Sitemaps\SitemapEntry;
use App\Domain\Sitemaps\SitemapProvider;
use App\Models\Grade;
use App\Models\Kind;
use App\Models\Product;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Generator;
use Illuminate\Database\Eloquent\Model;

/**
 * الصفحات الثابتة المبنية فعلًا في المتجر.
 *
 * HONESTY DOCTRINE: this provider lists ONLY pages that exist in
 * `apps/storefront/app` today — the homepage and the five taxonomy hubs.
 * Editorial routes (`/blog`, `/guides`, `/glossary`…) are in the MIP route
 * matrix but are NOT built, so they are absent rather than listed as 404s.
 * Their `content` route key stays dormant until those pages ship.
 *
 * Each entry's content hash is derived from real catalog facts, so the
 * homepage's lastmod moves when the catalog behind it moves — and not before.
 */
final class ContentProvider implements SitemapProvider
{
    /**
     * The taxonomy hubs: route key => [term model, hub path].
     *
     * A hub is emitted only when its route class is live AND the hub itself
     * clears the §7.2 threshold — the same two gates the archives beneath it
     * pass, so a hub can never outrank its own contents in the map.
     *
     * @var array<string, array{0: class-string<Model>, 1: string}>
     */
    private const HUBS = [
        'stages' => [Stage::class, '/stages'],
        'grades' => [Grade::class, '/grades'],
        'subjects' => [Subject::class, '/subjects'],
        'semesters' => [Semester::class, '/semesters'],
        'kinds' => [Kind::class, '/kinds'],
    ];

    public function __construct(
        private readonly IndexabilityPolicy $policy,
        private readonly RouteRegistry $routes,
    ) {}

    public function name(): string
    {
        return 'content';
    }

    public function label(): string
    {
        return 'الصفحات الثابتة';
    }

    public function isEnabled(): bool
    {
        return $this->routes->isLive('home');
    }

    public function dormantReason(): ?string
    {
        return $this->isEnabled() ? null : 'الصفحة الرئيسية غير مفعَّلة';
    }

    public function entries(): Generator
    {
        if (! $this->isEnabled()) {
            return;
        }

        $publishedCount = Product::query()->published()->count();
        $latestPublishedAt = Product::query()->published()->max('published_at');

        if ($this->policy->forPath('/')->isEligible()) {
            yield new SitemapEntry(
                loc: '/',
                contentHash: SitemapEntry::hash('home', $publishedCount, $latestPublishedAt),
            );
        }

        foreach (self::HUBS as $key => [$model, $path]) {
            if (! $this->routes->isLive($key) || ! $this->policy->forPath($path)->isEligible()) {
                continue;
            }

            // A hub's content IS its term list: the lastmod moves when a term
            // is added or renamed, and stays put when nothing changed.
            $digest = $model::query()->orderBy('id')->pluck('name_ar')->implode('|');

            yield new SitemapEntry(
                loc: $path,
                contentHash: SitemapEntry::hash($key, $model::query()->count(), $digest),
            );
        }
    }
}
