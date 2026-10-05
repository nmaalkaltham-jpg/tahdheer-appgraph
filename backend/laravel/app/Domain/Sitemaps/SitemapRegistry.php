<?php

namespace App\Domain\Sitemaps;

use App\Domain\SEO\IndexabilityPolicy;
use App\Domain\SEO\RouteRegistry;
use App\Domain\Sitemaps\Providers\ContentProvider;
use App\Domain\Sitemaps\Providers\CurriculumHubProvider;
use App\Domain\Sitemaps\Providers\CurriculumProvider;
use App\Domain\Sitemaps\Providers\ProductProvider;

/**
 * سجل مزوّدي خرائط الموقع — الخرائط المقسَّمة حسب النية (MIP §7.3.10).
 *
 * Intent-split maps: products · curriculum dimensions · curriculum hubs ·
 * static content. Every consumer (the builder, the JSON API, the audit
 * command, llms.txt) iterates THIS registry, so a provider added here is
 * immediately covered everywhere.
 */
final class SitemapRegistry
{
    /** @var array<string, SitemapProvider>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly IndexabilityPolicy $policy,
        private readonly RouteRegistry $routes,
    ) {}

    /** @return array<string, SitemapProvider> */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [new ProductProvider($this->policy, $this->routes)];

        foreach (CurriculumProvider::dimensions() as $dimension) {
            $providers[] = new CurriculumProvider($dimension, $this->policy, $this->routes);
        }

        $providers[] = new CurriculumHubProvider($this->policy, $this->routes);
        $providers[] = new ContentProvider($this->policy, $this->routes);

        $this->providers = [];

        foreach ($providers as $provider) {
            $this->providers[$provider->name()] = $provider;
        }

        return $this->providers;
    }

    /** @return array<string, SitemapProvider> providers that actually emit URLs */
    public function enabled(): array
    {
        return array_filter($this->all(), static fn (SitemapProvider $p) => $p->isEnabled());
    }

    public function get(string $name): ?SitemapProvider
    {
        return $this->all()[$name] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }
}
