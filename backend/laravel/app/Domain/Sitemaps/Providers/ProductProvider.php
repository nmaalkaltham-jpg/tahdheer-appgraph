<?php

namespace App\Domain\Sitemaps\Providers;

use App\Domain\Pricing\PricingService;
use App\Domain\SEO\IndexabilityPolicy;
use App\Domain\SEO\RouteRegistry;
use App\Domain\Sitemaps\SitemapEntry;
use App\Domain\Sitemaps\SitemapProvider;
use App\Models\Product;
use Generator;

/**
 * روابط المنتجات. كل رابط يمر عبر IndexabilityPolicy قبل إصداره.
 *
 * Streams in chunks so the memory cost is bounded regardless of catalog size.
 */
final class ProductProvider implements SitemapProvider
{
    private const CHUNK = 500;

    public function __construct(
        private readonly IndexabilityPolicy $policy,
        private readonly RouteRegistry $routes,
    ) {}

    public function name(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'المنتجات';
    }

    public function isEnabled(): bool
    {
        return $this->routes->isLive('products');
    }

    public function dormantReason(): ?string
    {
        return $this->routes->dormantReason('products');
    }

    public function entries(): Generator
    {
        if (! $this->isEnabled()) {
            return;
        }

        $query = Product::query()
            ->published()
            ->with('price')
            ->orderBy('id');

        foreach ($query->lazyById(self::CHUNK) as $product) {
            // The single indexability authority — no second opinion here.
            if (! $this->policy->forProduct($product)->isEligible()) {
                continue;
            }

            yield new SitemapEntry(
                loc: '/products/'.$product->slug,
                contentHash: $this->contentHash($product),
                changefreq: $this->changefreq($product),
            );
        }
    }

    /**
     * §7.3.7 watched fields ONLY: title, description, price, availability.
     * `updated_at` is deliberately absent — an editor touching an unrelated
     * column must not tell crawlers the page changed.
     */
    private function contentHash(Product $product): string
    {
        $price = $product->price;
        $activeSaleMinor = $price !== null ? PricingService::activeSaleMinor($price) : null;

        return SitemapEntry::hash(
            $product->title,
            $product->short_desc,
            $product->description,
            $price?->regular_minor,
            // The price actually in effect — so an expired sale moves lastmod.
            $activeSaleMinor,
            $price?->currency,
            $product->status,
        );
    }

    /**
     * changefreq is emitted ONLY when a real, dated event guarantees a change:
     * a running sale has a known end date. Otherwise it stays absent rather
     * than becoming an invented crawl hint.
     */
    private function changefreq(Product $product): ?string
    {
        $price = $product->price;

        if ($price === null || PricingService::activeSaleMinor($price) === null) {
            return null;
        }

        return $price->sale_to !== null ? 'daily' : null;
    }
}
