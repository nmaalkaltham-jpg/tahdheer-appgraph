<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pricing\PricingService;
use App\Domain\Search\QueryPipeline;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/search/suggest — typeahead: ≥2 chars, ≤3 rows, products only
 * for now (scope grows to categories/guides later). Below the threshold the
 * answer is an explicit empty list, never an error.
 */
class SearchSuggestController extends Controller
{
    public function __invoke(Request $request, QueryPipeline $pipeline): JsonResponse
    {
        $q = (string) $request->query('q', '');

        $items = $pipeline->suggest($q, 3)->map(function (Product $product) {
            $item = [
                'kind' => 'product',
                /* يُرسَل مع اختيار الاقتراح (`/search/suggest/select`) — قياسٌ لا غير. */
                'id' => (int) $product->id,
                'title' => $product->title,
                'url' => '/products/'.$product->slug,
            ];

            if ($product->price !== null) {
                $item['priceMinor'] = PricingService::activeSaleMinor($product->price) ?? $product->price->regular_minor;
            }

            return $item;
        })->values();

        return response()->json(['items' => $items]);
    }
}
