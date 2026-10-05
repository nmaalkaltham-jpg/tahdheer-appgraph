<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\SEO\IndexabilityPolicy;
use App\Domain\SEO\SiteIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * سياسة robots ومفتّش قابلية الفهرسة.
 *
 * The robots rules the storefront serves come from the SAME
 * {@see IndexabilityPolicy} that governs sitemaps and llms.txt — there is no
 * second, drifting list of disallowed paths.
 */
class RobotsController extends Controller
{
    /** Paths the policy excludes that are worth stating explicitly to crawlers. */
    private const ADVERTISED_DISALLOW = [
        '/cart', '/checkout', '/account', '/orders', '/search',
        '/pay', '/wishlist', '/compare', '/login', '/admin', '/api',
        '/downloads', '/files',
    ];

    public function __construct(
        private readonly IndexabilityPolicy $policy,
        private readonly SiteIdentity $site,
    ) {}

    /** GET /api/v1/robots */
    public function policy(): JsonResponse
    {
        $disallow = [];

        foreach (self::ADVERTISED_DISALLOW as $path) {
            // Only advertise a rule the policy genuinely enforces.
            if (! $this->policy->forPath($path)->isEligible()) {
                $disallow[] = $path;
            }
        }

        return response()->json([
            'userAgent' => '*',
            'disallow' => $disallow,
            // NOT_CONFIGURED rather than a guessed hostname.
            'sitemap' => $this->site->isConfigured() ? $this->site->url('/sitemap.xml') : null,
            'host' => $this->site->isConfigured() ? $this->site->origin() : null,
        ]);
    }

    /** GET /api/v1/seo/indexability?path=… — the admin URL inspector. */
    public function inspect(Request $request): JsonResponse
    {
        $validated = $request->validate(['path' => 'required|string|max:2048']);

        $verdict = $this->policy->forPath($validated['path']);

        return response()->json([
            'path' => $validated['path'],
            'robots' => $verdict->robots(),
        ] + $verdict->toArray());
    }
}
