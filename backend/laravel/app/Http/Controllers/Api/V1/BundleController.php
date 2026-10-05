<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\BundleSummary;
use App\Domain\Catalog\Models\BundleComponent;
use App\Domain\Catalog\Models\ProductGroup;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * الحزم العامّة: المجموعات `COMPOSITE` المنشورة التي لها مكوّنٌ واحد على الأقلّ.
 *
 * حزمةٌ بلا مكوّنات أو بلا ثمنٍ نافذ لا تُعرض — بطاقةُ حزمةٍ تعد بملفّاتٍ
 * ليست فيها وهمٌ. والقائمة تحمل المكوّنات كاملةً لأنّ بطاقة التصميم تعدّدها.
 */
class BundleController extends Controller
{
    private const PER_PAGE = 12;

    private function query(): Builder
    {
        return ProductGroup::query()->published()
            ->where('product_nature', 'COMPOSITE')
            ->whereExists(BundleComponent::query()->whereColumn('bundle_product_group_id', 'product_groups.id'))
            ->with(BundleSummary::BUNDLE_EAGER)
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /** @return array<string, mixed>|null */
    private static function card(ProductGroup $bundle): ?array
    {
        $summary = BundleSummary::summarize($bundle);

        return $summary === null ? null : $summary + ['items' => BundleSummary::items($bundle)];
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->query()->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ProductGroup $b) => self::card($b))->filter()->values(),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $bundle = $this->query()->where('slug', $slug)->firstOrFail();
        $card = self::card($bundle);

        abort_if($card === null, 404);

        return response()->json(['data' => $card]);
    }
}
