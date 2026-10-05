<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sitemaps\LastmodLedger;
use App\Domain\Sitemaps\SitemapEntry;
use App\Domain\Sitemaps\SitemapRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * خرائط الموقع كـJSON — المتجر يحوّلها إلى XML.
 *
 * Response keys are camelCase, like every other endpoint.
 */
class SitemapController extends Controller
{
    public function __construct(
        private readonly SitemapRegistry $registry,
        private readonly LastmodLedger $ledger,
    ) {}

    /** The index: which providers exist, which are dormant and why. */
    public function index(): JsonResponse
    {
        $providers = [];

        foreach ($this->registry->all() as $provider) {
            $providers[] = [
                'provider' => $provider->name(),
                'label' => $provider->label(),
                'enabled' => $provider->isEnabled(),
                // Explicit NOT_CONFIGURED state for every dormant provider.
                'reason' => $provider->dormantReason(),
                'url' => '/api/v1/sitemap/'.$provider->name(),
            ];
        }

        return response()->json(['providers' => $providers]);
    }

    /** One provider's URL set with truthful lastmod values. */
    public function show(string $provider): JsonResponse
    {
        $source = $this->registry->get($provider);

        if ($source === null) {
            return response()->json([
                'error' => ['code' => 'PROVIDER_NOT_FOUND', 'message' => 'مزوّد خريطة غير معروف: '.$provider],
            ], 404);
        }

        if (! $source->isEnabled()) {
            // Honest empty: a dormant provider returns [] plus the reason,
            // never a placeholder URL.
            return response()->json([
                'provider' => $source->name(),
                'enabled' => false,
                'reason' => $source->dormantReason(),
                'urls' => [],
                'count' => 0,
            ]);
        }

        $entries = $this->ledger->stamp(
            $source->name(),
            iterator_to_array($source->entries(), false),
        );

        return response()->json([
            'provider' => $source->name(),
            'enabled' => true,
            'reason' => null,
            'urls' => array_map(static fn (SitemapEntry $e) => $e->toArray(), $entries),
            'count' => count($entries),
        ]);
    }
}
