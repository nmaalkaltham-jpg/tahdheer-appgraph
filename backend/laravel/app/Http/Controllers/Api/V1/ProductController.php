<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\BestSellers;
use App\Domain\Catalog\BundleSummary;
use App\Domain\Catalog\ProductPreviews;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCardResource;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ProductController extends Controller
{
    /**
     * Published products only.
     *
     *   new (default) — newest publish date first.
     *   manual        — the caller's explicit ids, in the caller's order.
     *   best          — most units actually sold, from `order_items` on orders
     *                   whose money was really collected. A product with zero
     *                   paid lines is EXCLUDED, not ranked last: an empty
     *                   bestseller rail is the honest answer before the first
     *                   sale, and «الأكثر مبيعًا» must never mean «newest».
     *   featured      — the editorial `products.featured` flag, nothing else.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'source' => 'sometimes|in:new,manual,best,featured',
            'ids' => 'required_if:source,manual|array|max:24',
            'ids.*' => 'integer',
            'limit' => 'sometimes|integer|min:1|max:24',
        ]);

        $limit = (int) ($validated['limit'] ?? 12);

        /* الصفّ والفصل وصيغ المجموعة وعلَم العيّنة لسطر بيانات البطاقة — استعلامٌ لكلٍّ منها للقائمة كلّها. */
        $query = ProductPreviews::withSampleFlag(Product::query()->published()->with([
            'price', 'stage', 'grade', 'subject', 'semester', 'cover', 'reviewAggregate', 'group.publishedVariants',
        ]));

        $products = match ($validated['source'] ?? 'new') {
            'manual' => $this->manual($query, array_map(intval(...), $validated['ids']), $limit),
            'best' => $query
                ->where(self::paidUnitsSold(), '>', 0)
                ->orderByDesc(self::paidUnitsSold())
                ->orderByDesc('published_at')
                ->limit($limit)->get(),
            'featured' => $query->where('featured', true)
                ->orderByDesc('published_at')
                ->limit($limit)->get(),
            default => $query->orderByDesc('published_at')->limit($limit)->get(),
        };

        return ProductCardResource::collection($products);
    }

    /** انظر `BestSellers::paidUnitsSold` — العدّاد الواحد للترتيب وللشارة. */
    private static function paidUnitsSold(): \Illuminate\Database\Query\Builder
    {
        return BestSellers::paidUnitsSold();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    private function manual(Builder $query, array $ids, int $limit): Collection
    {
        return $query->whereIn('id', $ids)->get()
            ->sortBy(fn ($p) => array_search($p->id, $ids, true))
            ->take($limit)
            ->values();
    }

    public function show(string $slug)
    {
        $product = Product::query()->published()
            /*
             * `select` صريحٌ قبل `addSelect`: بدونه يستبدل الاستعلامُ الفرعيّ
             * أعمدةَ الجدول كلّها بدل أن يُضاف إليها، فيعود صفٌّ بعمودٍ واحد.
             */
            ->select('products.*')
            ->where('slug', $slug)
            /*
             * `media` مُحمَّلة هنا عمدًا: `ProductResource` يُصدر `images`
             * بـ`whenLoaded('media')`، وبدون هذا السطر كان المفتاح غائبًا في
             * كل استجابة — فحقلٌ مُعرَّف لا يصل الواجهة أبدًا، ومعرض الصفحة
             * يعرض الغلاف وحده مهما كان للمنتج وسائط. استعلامٌ واحد إضافي
             * لمنتجٍ واحد؛ القائمة (`index`) تبقى بلا تحميل الوسائط.
             */
            ->with([
                'price', 'facts', 'seo', 'stage', 'grade', 'subject', 'semester',
                'cover', 'media', 'reviewAggregate',
                /*
                 * سلسلة النموذج الكونيّ: المجموعة ← صيغها ← عرض كلّ صيغة، مع
                 * التوافق وأقسام المحتوى. خمسة استعلاماتٍ لمنتجٍ واحد، وهي
                 * كلّ ما تحتاجه تبويبات الصفحة ومُبدِّل الصيغ. `index` لا
                 * يحمّل شيئًا من هذا.
                 */
                'group', 'group.publishedVariants', 'group.publishedVariants.offer',
                'group.compatibilities', 'group.contentSections',
                /* مكوّنات الحزمة حين يكون المنتجُ نفسه حزمة — لتلميح صيغتها. */
                'group.bundleComponents.variant',
                /*
                 * «يُشترى معه عادةً»: العلاقة تشير إلى مجموعة، والبطاقة تُبنى
                 * من منتجها القديم — فالسلسلة تنزل إلى السعر والغلاف والتقييم
                 * كي تُصيَّر البطاقة بلا استعلامٍ لكلّ توصية.
                 */
                'group.relationships.target.legacyProduct.price',
                'group.relationships.target.legacyProduct.cover',
                'group.relationships.target.legacyProduct.stage',
                'group.relationships.target.legacyProduct.subject',
                'group.relationships.target.legacyProduct.reviewAggregate',
                /* معاينة مجّانيّة: الملفّات وأصولها كي يُفحص الدور والمرئيّة معًا. */
                'files.asset',
                /* شروط الترخيص المربوطة بعرض الصيغة — مصدر `licenseLabel`. */
                'group.publishedVariants.offer.licenseTerms',
                /* أوّل حزمةٍ منشورة تحتوي المنتج — مصدر `bundle`. */
                ...BundleSummary::EAGER,
            ])
            /*
             * عدّاد الشراء استعلامٌ فرعيّ في الاستعلام نفسه لا نداءٌ ثانٍ،
             * وهو العدّاد ذاته الذي يرتّب «الأكثر مبيعًا» — فلا يفترق ما
             * تعرضه الصفحة عمّا يرتّب به المتجر.
             */
            ->addSelect(['paid_units_sold' => self::paidUnitsSold()])
            ->firstOrFail();

        return new ProductResource($product);
    }
}
