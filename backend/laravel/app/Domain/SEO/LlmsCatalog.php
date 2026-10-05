<?php

namespace App\Domain\SEO;

use App\Models\Product;

/**
 * فهرس الكتالوج لـ llms.txt (MIP §7.3.16).
 *
 * «llms.txt generated from live catalog (title/stage/subject/semester/URL/
 * one-liner; coverage metric; opt-in; excludes cart/checkout/account/paid
 * files)».
 *
 * Every row is a REAL published product judged by the same IndexabilityPolicy
 * the sitemap uses. A product without a one-liner ships with `oneLiner: null` —
 * the gap is visible rather than filled with generated prose, because
 * machine-only text a human never sees is cloaking (§7.10 LLMO).
 */
final class LlmsCatalog
{
    private const CHUNK = 500;

    public function __construct(
        private readonly IndexabilityPolicy $policy,
        private readonly SiteIdentity $site,
        private readonly RouteRegistry $routes,
    ) {}

    /**
     * @return array{
     *   brand: string,
     *   items: list<array<string, mixed>>,
     *   coverage: array{published: int, included: int, pct: float|null, withOneLiner: int}
     * }
     */
    public function build(): array
    {
        $items = [];
        $published = 0;
        $withOneLiner = 0;

        if ($this->routes->isLive('products')) {
            $query = Product::query()
                ->published()
                ->with(['seo', 'stage', 'subject', 'semester'])
                ->orderBy('id');

            foreach ($query->lazyById(self::CHUNK) as $product) {
                $published++;

                if (! $this->policy->forProduct($product)->isEligible()) {
                    continue;
                }

                $oneLiner = TextBudget::words(
                    TextBudget::normalize($product->short_desc)
                        ?? TextBudget::normalize($product->seo->quick_answer ?? null),
                    160,
                );

                if ($oneLiner !== null) {
                    $withOneLiner++;
                }

                $items[] = [
                    'title' => $product->title,
                    'url' => $this->site->url('/products/'.$product->slug),
                    'stage' => $product->stage?->name_ar,
                    'subject' => $product->subject?->name_ar,
                    'semester' => $product->semester?->name_ar,
                    'oneLiner' => $oneLiner,
                ];
            }
        }

        $included = count($items);

        return [
            'brand' => SiteIdentity::BRAND,
            'items' => $items,
            'coverage' => [
                'published' => $published,
                'included' => $included,
                // null = «غير مقيس» — an empty catalog has no coverage to report.
                'pct' => $published === 0 ? null : round($included / $published * 100, 2),
                'withOneLiner' => $withOneLiner,
            ],
        ];
    }
}
