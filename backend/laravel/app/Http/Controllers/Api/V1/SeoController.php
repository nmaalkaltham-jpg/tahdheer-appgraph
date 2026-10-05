<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\SEO\ProductPageSeo;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

/**
 * حمولة SEO لصفحة المنتج: الوسوم + المحتوى المرئي + الرسم البياني.
 *
 * ONE response carries the metadata, the visible arrays and the JSON-LD graph
 * built from those same arrays. The storefront renders `visible.breadcrumbs`
 * with <Breadcrumbs> and drops `schema` into <ProductSchema> — so the markup
 * and the page cannot describe different things.
 */
class SeoController extends Controller
{
    public function __construct(private readonly ProductPageSeo $page) {}

    /** GET /api/v1/seo/products/{slug} */
    public function product(string $slug): JsonResponse
    {
        $product = Product::query()
            ->published()
            ->where('slug', $slug)
            ->with(['price', 'facts', 'seo', 'stage', 'grade', 'subject', 'semester'])
            ->firstOrFail();

        return response()->json($this->page->forProduct($product));
    }
}
