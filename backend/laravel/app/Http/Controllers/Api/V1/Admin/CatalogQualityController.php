<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\ProductQualityEngine;
use App\Domain\Catalog\ProductScorecard;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منصّة اكتمال بيانات المنتج (§١٢٠ §١٥٧).
 *
 * §١٢٠ توجب سطحًا يعرض ما ينقص كلّ منتج. وقيمته الحقيقيّة ليست في العرض بل
 * في **الترتيب**: منتجٌ لا يُنشر لأنّه بلا استراتيجيّة تسليم يسبق منتجًا
 * ينقصه قسمُ أسئلةٍ شائعة. قائمةٌ أبجديّة تجعل الشاشة تقريرًا؛ والمرتّبة
 * بالحجب تجعلها قائمةَ عمل.
 *
 * ولا درجة وسطيّة واحدة (§١٥٧): الأبعاد تُعاد منفصلة، ونواقصُها مسمّاة
 * بالاسم لا معدودة. «٣ حقول ناقصة» تُرسل المشغّل يبحث، و«الصيغة بلا وزن —
 * الشحن يرفضها» تُرسله يُصلح.
 */
class CatalogQualityController extends Controller
{
    public function __construct(private readonly ProductQualityEngine $engine) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'blockingOnly' => 'sometimes|boolean',
            'status' => 'sometimes|string|max:16',
        ]);

        $groups = ProductGroup::query()
            ->with(['variants.offers.fulfillments', 'attributeValues.definition'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('id')
            ->limit(300)
            ->get();

        $cards = $groups
            ->map(fn (ProductGroup $group) => [$group, $this->engine->score($group)])
            ->when(
                (bool) ($filters['blockingOnly'] ?? false),
                fn ($rows) => $rows->filter(fn (array $row) => ! $row[1]->canPublish()),
            )
            /*
             * الحاجب أوّلًا، ثمّ الأكثر نقصًا. المنتج الذي لا يُنشر أصلًا
             * يسبق المنتج الذي ينقصه قسمُ محتوى — وإلّا صارت الشاشة تقريرًا
             * يُقرأ مرّةً لا قائمةَ عملٍ تُفتح كلّ صباح.
             */
            ->sortBy([
                fn (array $a, array $b) => ($a[1]->canPublish() ? 1 : 0) <=> ($b[1]->canPublish() ? 1 : 0),
                fn (array $a, array $b) => $this->gapCount($b[1]) <=> $this->gapCount($a[1]),
            ])
            ->values()
            ->map(fn (array $row) => [
                'id' => $row[0]->id,
                'title' => $row[0]->title,
                'slug' => $row[0]->slug,
                'status' => $row[0]->status,
                'nature' => $row[0]->product_nature,
                'legacyProductId' => $row[0]->legacy_product_id,
                'canPublish' => $row[1]->canPublish(),
                'blockers' => $row[1]->publicationBlockers(),
                'scores' => $row[1]->scores,
                'missing' => $row[1]->missing,
            ]);

        return response()->json([
            'data' => $cards,
            'dimensions' => ProductQualityEngine::DIMENSIONS,
            'blockedCount' => $cards->where('canPublish', false)->count(),
            'total' => $groups->count(),
        ]);
    }

    private function gapCount(ProductScorecard $card): int
    {
        return array_sum(array_map('count', $card->missing));
    }
}
