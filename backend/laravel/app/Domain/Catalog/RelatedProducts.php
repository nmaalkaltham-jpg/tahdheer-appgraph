<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * «منتجات ذات صلة» لصفحة المنتج — أربع بطاقاتٍ على الأكثر.
 *
 * الأولويّة للمنشور في **الصفّ والمادّة نفسيهما** مرتّبًا بالبيع المدفوع ثمّ
 * بالنشر؛ فإن بقي فراغٌ مُلئ ممّا «يُشترى معه عادةً» (علاقات المجموعة
 * المنقَّبة). لا تشابهَ نصّيّ ولا حشوَ بأحدث المنتجات.
 */
final class RelatedProducts
{
    public const LIMIT = 4;

    /** العلاقات التي تحتاجها البطاقة كي تُصيَّر بلا استعلامٍ لكلّ صفّ. */
    private const CARD = ['price', 'stage', 'grade', 'subject', 'semester', 'cover', 'reviewAggregate', 'group.publishedVariants'];

    /** @return Collection<int, Product> */
    public function forProduct(Product $product): Collection
    {
        $related = new Collection;

        if ($product->grade_id !== null && $product->subject_id !== null) {
            $related = Product::query()->published()
                ->select('products.*')
                ->whereKeyNot($product->id)
                ->where('grade_id', $product->grade_id)
                ->where('subject_id', $product->subject_id)
                ->with(self::CARD)
                ->orderByDesc(BestSellers::paidUnitsSold())
                ->orderByDesc('published_at')
                ->limit(self::LIMIT)
                ->get();
        }

        if ($related->count() < self::LIMIT && $product->relationLoaded('group') && $product->group !== null) {
            $seen = $related->pluck('id')->push($product->id)->all();

            $together = $product->group->relationships
                ->where('relation_type', CoPurchaseMiner::RELATION)
                ->map(fn ($rel) => $rel->target?->legacyProduct)
                ->filter(fn ($p) => $p !== null && $p->status === 'published' && ! in_array($p->id, $seen, true))
                ->unique('id')
                ->take(self::LIMIT - $related->count());

            $related = $related->concat($together);
        }

        return $related->values();
    }
}
