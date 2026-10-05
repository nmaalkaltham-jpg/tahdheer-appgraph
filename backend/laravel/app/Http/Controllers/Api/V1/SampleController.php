<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assets\Models\ProductFile;
use App\Domain\Catalog\ProductPreviews;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCardResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * العيّنات المجّانيّة — «لن تشتري ملفًّا لم تره».
 *
 * الشرط هو شرط `ProductPreviews` نفسه ولا يُخفَّف: دورٌ `sample` **ومرئيّة**
 * `public_sample` **ورابطٌ أمكن إصداره فعلًا**. ثلاثتها معًا، فمنتجٌ رُفعت له
 * عيّنة ولا يقدر المزوّد على خدمتها لا يُدرَج — صفحةٌ تَعِد بتحميلٍ لا يبدأ
 * أسوأ من صفحةٍ أقصر.
 *
 * والتجميع بنوع المورد (تحضير · عرض · أوراق عمل) كما يرسم التصميم، لكنّ
 * المجموعات تُشتقّ من المعروض لا من قائمةٍ مكتوبة: نوعٌ بلا عيّنةٍ منشورة
 * لا يظهر عنوانًا فارغًا ينتظر محتوًى.
 */
class SampleController extends Controller
{
    /** سقفٌ للصفحة الواحدة — العيّنات سطحُ استكشافٍ لا فهرسٌ كامل. */
    private const MAX = 48;

    public function __invoke(Request $request, ProductPreviews $previews): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:'.self::MAX,
        ]);

        $limit = (int) ($validated['limit'] ?? self::MAX);

        /*
         * الترشيح في قاعدة البيانات لا في PHP: `whereHas` يقصر الاستعلام على
         * المنتجات التي لها فعلًا ملفّ عيّنةٍ عامّ، فلا تُحمَّل ستّةٌ وعشرون
         * منتجًا لتُرمى عشرون منها.
         */
        $products = ProductPreviews::withSampleFlag(Product::query()->published())
            ->whereHas('files', fn ($query) => $query
                ->where('product_files.role', ProductPreviews::ROLE)
                ->whereHas('asset', fn ($asset) => $asset->where('assets.visibility', 'public_sample')))
            ->with(['price', 'stage', 'grade', 'subject', 'semester', 'cover', 'reviewAggregate', 'group.publishedVariants', 'files.asset'])
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        $items = [];

        foreach ($products as $product) {
            $available = $previews->forProduct($product);

            /* لا رابط ⇒ لا عيّنة تُعرض. الشرط الثالث، ويُفحص هنا وحده. */
            if ($available === []) {
                continue;
            }

            $items[] = [
                'product' => (new ProductCardResource($product))->resolve($request),
                'resourceType' => $product->resource_type,
                'previews' => $available,
            ];
        }

        return response()->json([
            'items' => $items,
            /*
             * الأنواع المعروضة فعلًا، بترتيب ظهورها. الواجهة تبني منها
             * مجموعاتها بلا أن تعرف تصنيف الكتالوج ولا أن تخترع عناوين.
             */
            'resourceTypes' => array_values(array_unique(array_filter(
                array_column($items, 'resourceType'),
            ))),
            'count' => count($items),
        ]);
    }

    /** يُستعمل في الاختبار والتشخيص: كم منتجًا يحمل ملفّ عيّنةٍ عامًّا. */
    public static function withPublicSampleCount(): int
    {
        return ProductFile::query()
            ->where('role', ProductPreviews::ROLE)
            ->whereHas('asset', fn ($asset) => $asset->where('assets.visibility', 'public_sample'))
            ->distinct('product_id')
            ->count('product_id');
    }
}
