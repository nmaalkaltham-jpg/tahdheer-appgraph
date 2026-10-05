<?php

namespace App\Domain\Admin;

use App\Domain\Catalog\ProductCapabilities;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * بوّابة النشر — وبنودُها تتبع نوع المنتج (§٦).
 *
 * ─── العيب الذي زال ───
 *
 * كانت البوّابة قائمةً واحدة تُطلب من كلّ منتج: اسمٌ وتصنيفٌ وسعرٌ **وملفٌّ**
 * وصورة. والملفّ يعني منتجًا رقميًّا، فكان منتجٌ ماديّ لا يُنشر أبدًا مهما
 * اكتمل — بندٌ ناقصٌ لا يستطيع المشغّل إكماله لأنّ المنتج لا ملفّ له بطبيعته.
 * وفي المقابل كان الوزن — وهو ما يرفض الشحنُ ما لا يملكه — غير مطلوبٍ أصلًا،
 * فيُنشر الماديّ (لو أمكن) ثمّ يقف عند التسليم.
 *
 * ─── والقائمة تأتي من سجلّ القدرات لا من هنا ───
 *
 * البنود المشتركة أدناه، وما يخصّ الطبيعة في `ProductCapabilities`. قائمتان
 * مكتوبتان بيدهما كانتا ستفترقان عند أوّل طبيعةٍ جديدة، وهو بالضبط ما كان
 * يقع بين هذه البوّابة و`ProductQualityEngine`: سلطتان تحكمان بقائمتين.
 *
 * ولا نتيجة مخزَّنة ولا نسبةٌ مكتوبة: `completedCount` يُعدّ من الفحوص نفسها.
 */
final class ProductReadiness
{
    /**
     * البنود المشتركة قبل بنود الطبيعة — الهويّة ثمّ التصنيف ثمّ الثمن.
     *
     * والترتيب مقصود: ما تطلبه الطبيعة يقع **بينهما وبين الصورة**، فيبقى
     * ترتيب المنتج الرقميّ كما كان بالضبط (اسم · تصنيف · سعر · ملفّ · صورة).
     * قائمةٌ يتبدّل ترتيبها بلا سببٍ تُربك من حفظ مواضعها.
     */
    public const LEADING_ITEMS = [
        'title' => 'اسم المنتج',
        'classification' => 'التصنيف التعليمي الكامل (مرحلة، صف، مادة، فصل)',
        'price' => 'السعر الأساسي',
    ];

    /** والبند المشترك الأخير — بعد ما تطلبه الطبيعة. */
    public const TRAILING_ITEMS = [
        'image' => 'صورة واحدة على الأقل',
    ];

    /** كلّ البنود المشتركة، بترتيب العرض حين لا طبيعة. */
    public const COMMON_ITEMS = self::LEADING_ITEMS + self::TRAILING_ITEMS;

    /**
     * كلّ بندٍ ممكن — للقارئ الذي يحتاج المفردات كاملةً لا قائمة منتجٍ بعينه.
     *
     * يُبقي التوافق مع من كان يقرأ `ITEMS`، ويُضيف ما استجدّ.
     */
    public const ITEMS = self::COMMON_ITEMS + [
        'file' => 'ملف واحد على الأقل',
        'weight' => 'وزن الصيغة بالغرامات — الشحن يرفض ما لا وزن له',
    ];

    /**
     * @param  array<string, bool>  $checks
     * @param  array<string, string>  $labels  تسميات بنود هذا المنتج وحده
     */
    private function __construct(
        private readonly array $checks,
        private readonly array $labels = self::ITEMS,
    ) {}

    public static function for(Product $product): self
    {
        return self::forMany(new Collection([$product]))[$product->id];
    }

    /**
     * Batch evaluation — three aggregate queries total, never N+1.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, self>
     */
    public static function forMany(Collection $products): array
    {
        $ids = $products->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $withPrice = DB::table('product_prices')->whereIn('product_id', $ids)
            ->where('regular_minor', '>', 0)->distinct()->pluck('product_id')->all();
        $withFile = DB::table('product_files')->whereIn('product_id', $ids)
            ->distinct()->pluck('product_id')->all();
        $withImage = DB::table('product_media')->whereIn('product_id', $ids)
            ->distinct()->pluck('product_id')->all();

        /*
         * الطبيعة تأتي من المجموعة عبر الجسر، في استعلامٍ مجمَّع لا واحدٍ لكلّ
         * منتج: هذه البوّابة تُقيَّم لصفحة القائمة كاملةً، وN+1 هنا يعني
         * استعلامًا لكلّ صفٍّ في اللوحة.
         */
        $natures = DB::table('product_groups')
            ->whereIn('legacy_product_id', $ids)
            ->pluck('product_nature', 'legacy_product_id')
            ->all();

        /*
         * والوزن من الصيغة الجسر. منتجٌ بلا مجموعةٍ بعد (أُنشئ قبل أن يوجد
         * مسارُ الكتابة) لا وزن له ولا طبيعة — فيُعامَل بالبنود المشتركة
         * وحدها، لا بافتراض أنّه رقميّ.
         */
        /*
         * ─── الوزن يُقاس على **كلّ** صيغةٍ تُباع، لا على الجسر وحده ───
         *
         * `legacy_product_id` تحمله الصيغة الأولى فقط بالتصميم. فالفحص عليها
         * وحدها كان يُجيز نشر منتجٍ من ثلاث صيغ وزنَ أولاها مكتوبٌ والأخريان
         * بلا وزن: تمرّ البوّابة، ثمّ يقف الشحن عند من اشترى الثانية. والمرور
         * بالمجموعة يفحصهنّ جميعًا.
         *
         * والشرط «لا صيغةَ بلا وزن» لا «توجد صيغةٌ لها وزن»: الأولى تُجيز ما
         * تمنعه الثانية بالضبط.
         */
        $groupsByProduct = DB::table('product_groups')
            ->whereIn('legacy_product_id', $ids)
            ->pluck('legacy_product_id', 'id')
            ->all();

        $weightless = $groupsByProduct === [] ? [] : DB::table('product_variants')
            ->whereIn('product_group_id', array_keys($groupsByProduct))
            ->whereNull('weight_grams')
            ->distinct()
            ->pluck('product_group_id')
            ->map(fn ($groupId) => $groupsByProduct[$groupId] ?? null)
            ->filter()
            ->all();

        $out = [];
        foreach ($products as $product) {
            $nature = $natures[$product->id] ?? null;

            /*
             * ─── منتجٌ بلا مجموعة لا يُعفى من بند الملفّ ───
             *
             * البنود الخاصّة تأتي من الطبيعة، والطبيعةُ تأتي من المجموعة.
             * فمنتجٌ سبق النموذج الكونيّ كان يفقد بند «ملفٌّ واحد على الأقلّ»
             * تمامًا — أي يُنشر بلا شيءٍ يُسلَّم، وهو ما كانت البوّابة تمنعه
             * قبل هذا التغيير كلّه. والسقوط إلى العمود القديم يُعيد الشرط:
             * لا إعفاء بالصمت.
             */
            $effectiveNature = $nature ?? ProductCapabilities::natureOfLegacy($product->fulfillment_type);
            $extra = $effectiveNature === null
                ? ProductCapabilities::publishItems('DIGITAL')
                : ProductCapabilities::publishItems($effectiveNature);

            $evidence = [
                'title' => trim((string) $product->title) !== '',
                'classification' => $product->stage_id !== null
                    && $product->grade_id !== null
                    && $product->subject_id !== null
                    && $product->semester_id !== null,
                'price' => in_array($product->id, $withPrice, false),
                'image' => in_array($product->id, $withImage, false),
                'file' => in_array($product->id, $withFile, false),
                /* كلّ الصيغ لها وزن = لا صيغةَ بلا وزن. */
                'weight' => ! in_array($product->id, $weightless, false),
            ];

            /*
             * الفحوص تُبنى بترتيب التسميات لا بترتيب جمع الأدلّة: `missing()`
             * تشتقّ ترتيبها من هذه المصفوفة، فبناؤها بترتيبٍ آخر كان يجعل
             * القائمة المعروضة وقائمة النواقص تختلفان في الترتيب — وهو فرقٌ
             * يراه المشغّل بلا سببٍ يفهمه.
             */
            $labels = self::LEADING_ITEMS + $extra + self::TRAILING_ITEMS;

            $checks = [];
            foreach ($labels as $key => $_label) {
                $checks[$key] = $evidence[$key] ?? false;
            }

            $out[$product->id] = new self($checks, $labels);
        }

        return $out;
    }

    public function isReady(): bool
    {
        return $this->missing() === [];
    }

    /** @return list<string> */
    public function missing(): array
    {
        return array_values(array_keys(array_filter($this->checks, fn (bool $ok) => ! $ok)));
    }

    /**
     * @return array{
     *     ready: bool, completedCount: int, totalCount: int,
     *     items: list<array{key: string, label: string, ok: bool}>,
     *     missing: list<string>, missingLabels: list<string>
     * }
     */
    public function toArray(): array
    {
        $items = [];
        foreach ($this->labels as $key => $label) {
            if (array_key_exists($key, $this->checks)) {
                $items[] = ['key' => $key, 'label' => $label, 'ok' => $this->checks[$key]];
            }
        }

        $missing = $this->missing();

        return [
            'ready' => $missing === [],
            'completedCount' => count(array_filter($this->checks)),
            'totalCount' => count($this->checks),
            'items' => $items,
            'missing' => $missing,
            'missingLabels' => array_map(fn (string $key) => $this->labels[$key] ?? $key, $missing),
        ];
    }
}
