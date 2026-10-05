<?php

namespace App\Domain\Search;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\ProductCompatibility;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductCapabilities;
use App\Domain\ContentIntelligence\ExtractionStatus;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pricing\PricingService;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * يبني الإسقاط القانونيّ للبحث من النموذج الكونيّ (§٦٣ §١٢٤).
 *
 * يقرأ المجموعة وصيغَها وعروضَها وسماتِها المكتوبة النوع، ويسقط إلى المنتج
 * القديم حين لا تكون المجموعة موجودة — فالبنود السابقة للنموذج الكونيّ تبقى
 * قابلةً للبحث بلا ترحيلٍ قسريّ.
 *
 * وقرارٌ يستحقّ التسجيل: **السعر نطاقٌ لا رقم**. الصيغ الثلاث بأثمانٍ
 * مختلفة كانت تُفهرَس بثمنٍ واحد، فيُرشِّح الزبون «أقلّ من ٣٠» فيسقط منتجٌ
 * له صيغةٌ بـ٢٥ — وهي بالضبط ما يريد. والحدّ الأدنى وحده لا يكفي: «بين ٥٠
 * و١٠٠» تحتاج الطرفين.
 */
class ProductSearchProjector
{
    /** العمود الذي يحمل النصّ المطويّ المشتقّ من صفّ المنتج. */
    public const FOLDED_COLUMN = 'search_normalized';

    /**
     * نسخةُ الطيّ. تتغيّر كلّما تغيّر `ArabicNormalizer`.
     *
     * بدونها يبقى نصٌّ مطويٌّ بقواعد قديمة مقروءًا بوصفه صحيحًا بعد تعديل
     * المطبِّع، فيختلف الترتيب عن الحساب الحيّ بلا عَرَض يُرى. رفعُ الرقم
     * يُبطل كلّ المخزون دفعةً واحدة ويُعيد المسار إلى الطيّ الآنيّ.
     */
    public const FOLD_VERSION = 1;

    /**
     * سقفُ النصّ المستخرَج لكلّ منتج — محرفًا.
     *
     * أكبرُ منتجٍ اليوم ٢٥٧ محرفًا (مقيس هذه الجلسة على `asset_extractions`)،
     * فالسقف ثلاثون ضعفًا للمقيس. وهو موجودٌ لأنّ المقيس ليس عهدًا: مستخرِجُ
     * PDF كامل على كتابٍ من ثلاثمئة صفحة يُدخل ميغابايتًا في كلّ وثيقة وفي
     * كلّ استعلامٍ يطوي النصّ — فيصير تحسينُ إدراكٍ عيبَ أداء. والبديل
     * المرفوض: لا سقف. حجمُ الوثيقة حينئذٍ يتبع أكبر ملفٍّ رفعه ناشر.
     */
    public const EXTRACTED_CHARS_CAP = 8000;

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * النصّ المستخرَج من ملفّات المنتجات — `ok` وحدها، دفعةً واحدة.
     *
     * السلسلة: `product_files.asset_id → assets.id → asset_extractions.asset_id`.
     *
     * والحالة شرطٌ لا ترشيحًا تجميليًّا (§٧): `failed` بلا نصّ، و`partial`
     * نصُّه بعضُ الملفّ فيَعِد البحثُ بما لم يُقرأ، و`capability_gap` إعلانُ
     * عجزٍ لا محتوى. مقيس هذه الجلسة على قاعدة التطوير: ٢٦ صفًّا `ok` و٢٤
     * `capability_gap` وصفٌّ `partial` نصُّه ٢٨ محرفًا وصفّان `failed`
     * بـ`storage_missing_object`. فاستخراجٌ سيّئ لا يُسمّم البحث لأنّه لا
     * يدخله أصلًا.
     *
     * والدمج: أصولُ المنتج كلّها في نصٍّ واحد مرتّبةً بـ`position` ثمّ
     * `asset_id` — ترتيبٌ ثابت بين نداءين، وإلّا اختلفت الوثيقة عن نفسها بلا
     * تغيّر بيانات. ومن كلّ (أصل · مستخرِج) أحدثُ صفٍّ وحده: رفعُ
     * `extractor_version` يُبقي الصفّ القديم في الجدول (القيد الفريد على
     * البصمة والمستخرِج والنسخة)، فجمعُ النسختين يُدخل نصًّا أبطلته نسخةٌ
     * أحدث. والبديل المرفوض: صفٌّ واحد لكلّ أصل — يُسقط مستخرِجًا ثانيًا
     * يقرأ وجهًا آخر من الملفّ نفسه (بيانات PDF الوصفيّة مع نصّه).
     *
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    public static function extractedTextFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('product_files')
            ->join('asset_extractions', 'asset_extractions.asset_id', '=', 'product_files.asset_id')
            ->whereIn('product_files.product_id', $productIds)
            ->where('asset_extractions.status', ExtractionStatus::Ok->value)
            ->whereNotNull('asset_extractions.text')
            ->orderBy('product_files.product_id')
            ->orderBy('product_files.position')
            ->orderBy('product_files.asset_id')
            ->orderBy('asset_extractions.id')
            ->get([
                'product_files.product_id as product_id',
                'product_files.asset_id as asset_id',
                'asset_extractions.extractor as extractor',
                'asset_extractions.text as text',
            ]);

        /** @var array<int, array<string, string>> $byProduct */
        $byProduct = [];
        foreach ($rows as $row) {
            $text = trim((string) $row->text);
            if ($text === '') {
                continue;
            }

            /* المفتاح (أصل · مستخرِج): الأحدث يدهس الأقدم وموضعه الأوّل محفوظ. */
            $byProduct[(int) $row->product_id][$row->asset_id.'|'.$row->extractor] = $text;
        }

        $out = [];
        foreach ($byProduct as $productId => $texts) {
            $out[$productId] = mb_substr(implode("\n", $texts), 0, self::EXTRACTED_CHARS_CAP);
        }

        return $out;
    }

    public function forProduct(Product $product): ProductSearchDocument
    {
        /* الطيّ يُحسب مرّةً عند الإسقاط لا مرّةً في كلّ استعلام (§عيب الأداء). */
        $this->rememberFolded($product);

        $group = ProductGroup::query()
            ->where('legacy_product_id', $product->id)
            ->with(['variants.offers.fulfillments', 'attributeValues.definition'])
            ->first();

        return $group === null
            ? $this->fromLegacy($product)
            : $this->fromGroup($group, $product);
    }

    /**
     * نصّ المنتج مطويًّا، بالحقول التي يسجّل عليها مسار PostgreSQL وحدها.
     *
     * الطبقات في `PgFallbackProvider` تفرّق بين العنوان والجسد والوصف
     * بأوزانٍ متباعدة، فنصٌّ واحد مسطَّح كان سيدمج الطبقات ويغيّر الترتيب —
     * وهذا إصلاحُ أداءٍ واتّساق لا إصلاحُ صلة. فالمخزون مفاتيحُ بعدد الطبقات.
     *
     * و`src` بصمةُ النصّ الخام: المخزَّن لا يُصدَّق إلّا إذا طابقها. السبب
     * مقيس — `SyncProductJob::handle` يعود فورًا حين لا يكون المزوّد
     * `meilisearch`، و`config/search.php:16` يجعل الافتراضيّ `pg`، فتعديلُ
     * منتجٍ في النشر القائم **لا يمرّ بالمُسقِط أصلًا**. بلا بصمةٍ يبقى
     * العنوان القديم مطويًّا في العمود فيُطابَق على نصٍّ لم يعد موجودًا:
     * كذبٌ صامت أسوأ من إعادة الحساب التي جئنا نحذفها.
     *
     * @return array{v: int, src: string, sku: string, title: string, body: string, desc: string}
     */
    public static function foldFields(?string $sku, ?string $title, ?string $subtitle, ?string $shortDesc, ?string $description): array
    {
        return [
            'v' => self::FOLD_VERSION,
            'src' => self::fingerprintOf($sku, $title, $subtitle, $shortDesc, $description),
            // الرمز لا تُطوى حروفه — طيُّها يخلط رموزًا متمايزة (§ArabicNormalizer).
            'sku' => mb_strtolower(ArabicNormalizer::normalizeDigits((string) $sku), 'UTF-8'),
            'title' => ArabicNormalizer::normalize((string) $title),
            'body' => ArabicNormalizer::normalize(trim(($subtitle ?? '').' '.($shortDesc ?? ''))),
            'desc' => ArabicNormalizer::normalize((string) $description),
        ];
    }

    /** @return array{v: int, src: string, sku: string, title: string, body: string, desc: string} */
    public static function foldProduct(Product $product): array
    {
        return self::foldFields($product->sku, $product->title, $product->subtitle, $product->short_desc, $product->description);
    }

    /** بصمةُ النصّ الخام الذي اشتُقّ منه الطيّ — الفاصل بين مخزونٍ حيّ وبائت. */
    public static function fingerprintOf(?string $sku, ?string $title, ?string $subtitle, ?string $shortDesc, ?string $description): string
    {
        return md5(implode("\u{0}", [(string) $sku, (string) $title, (string) $subtitle, (string) $shortDesc, (string) $description]));
    }

    /**
     * يحفظ الطيّ في العمود حين يتغيّر مصدره فقط.
     *
     * الكتابة بمنشئ الاستعلام لا بالنموذج عمدًا: `save()` يُطلق أحداث النموذج
     * ويحرّك `updated_at` — وهو حقلٌ في الوثيقة نفسها ومصدرُ مزامنة الفهرس
     * (§قاعدة ٣٦)، فكتابةُ ذاكرةٍ مشتقّة كانت ستبدو تعديلًا على المنتج فتُطلق
     * فهرسةً تُعيد الكتابة بلا نهاية.
     */
    private function rememberFolded(Product $product): void
    {
        if ($product->id === null) {
            return;
        }

        $folded = self::foldProduct($product);
        $stored = self::decodeFold($product->getAttribute(self::FOLDED_COLUMN));

        if ($stored !== null && $stored['src'] === $folded['src']) {
            return;
        }

        $encoded = json_encode($folded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        DB::table('products')->where('id', $product->id)->update([self::FOLDED_COLUMN => $encoded]);

        // النسخة في الذاكرة تُوافَق ثمّ تُزامَن حتّى لا يراها حفظٌ لاحق «متّسخة».
        $product->setAttribute(self::FOLDED_COLUMN, $encoded);
        $product->syncOriginalAttribute(self::FOLDED_COLUMN);
    }

    /**
     * يفكّ الطيّ المخزَّن، ويُعيد null لكلّ ما لا يُوثق به: غائبًا أو فاسدًا
     * أو بنسخةٍ أخرى. والقارئ يطوي آنيًّا حينئذٍ — فـnull لا تعني «لا مطابقة».
     *
     * @return array{v: int, src: string, sku: string, title: string, body: string, desc: string}|null
     */
    public static function decodeFold(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)
            || ($decoded['v'] ?? null) !== self::FOLD_VERSION
            || ! is_string($decoded['src'] ?? null)
            || ! is_string($decoded['sku'] ?? null)
            || ! is_string($decoded['title'] ?? null)
            || ! is_string($decoded['body'] ?? null)
            || ! is_string($decoded['desc'] ?? null)) {
            return null;
        }

        return $decoded;
    }

    private function fromGroup(ProductGroup $group, Product $product): ProductSearchDocument
    {
        $variants = $group->variants;
        $offers = $variants->flatMap(fn (ProductVariant $v) => $v->offers)
            ->filter(fn (ProductOffer $o) => $o->status === 'active');

        /* الثمن النافذ لكلّ عرض، لا الثمن الأصليّ: التخفيض جزءٌ من الترشيح. */
        $effective = $offers->map(fn (ProductOffer $o) => $o->effectivePriceMinor())->all();
        $regular = $offers->map(fn (ProductOffer $o) => (int) $o->regular_minor)->all();

        $strategies = $offers
            ->flatMap(fn (ProductOffer $o) => $o->fulfillments->pluck('strategy'))
            ->unique()->values()->all();

        /*
         * التوافر: غير المتعقَّب متاحٌ دائمًا، والمتعقَّب يُسأل عنه المخزون.
         * إخفاءُ ما نفد خطأ — الزبون يبحث عنه ويريد أن يعرف أنّه نفد، لا أن
         * يظنّ أنّ المتجر لا يبيعه.
         */
        $tracked = $offers->filter(fn (ProductOffer $o) => $o->inventory_strategy === 'TRACKED');
        $inStock = $tracked->isEmpty() || $tracked->contains(
            fn (ProductOffer $o) => $this->inventory->availableFor((int) $o->product_variant_id) > 0
        );

        $sale = $effective !== [] && $regular !== [] && min($effective) < min($regular);

        return new ProductSearchDocument(
            id: $product->id,
            sku: $variants->first()?->sku ?? $product->sku,
            title: $group->title,
            subtitle: $group->subtitle,
            shortDescription: $group->short_desc,
            description: $group->description,
            nature: $group->product_nature,
            synonyms: $this->synonyms($product),
            stage: $product->stage?->slug,
            grade: $product->grade?->slug,
            subject: $product->subject?->slug,
            semester: $product->semester?->slug,
            resourceType: $this->attributeText($group, 'resource_type') ?? $product->resource_type,
            formats: $this->formats($group, $product),
            editable: $this->attributeBool($group, 'editable'),
            priceMinMinor: $effective === [] ? null : min($effective),
            priceMaxMinor: $effective === [] ? null : max($effective),
            onSale: $sale,
            discountPct: $sale ? PricingService::discountPct(min($regular), min($effective)) : null,
            fulfillmentStrategies: $strategies,
            inStock: $inStock,
            variantCount: $variants->count(),
            attributes: $this->filterableAttributes($group),
            useCases: $this->useCases($group),
            compatibility: $this->compatibility($group),
            ratingAvg: $this->rating($product)['avg'],
            ratingCount: $this->rating($product)['count'],
            salesCount: $this->salesCount($product),
            updatedAt: $product->updated_at?->getTimestamp() ?? 0,
            publishedAt: $product->published_at?->getTimestamp() ?? 0,
            normalizedText: $this->documentText([
                $group->title, $group->subtitle, $group->short_desc, $group->description,
                ...$this->synonyms($product),
            ]),
            extractedText: $this->extractedText($product),
        );
    }

    /**
     * المنتج القديم بلا مجموعة.
     *
     * يبقى قابلًا للبحث بحقوله القائمة: الترحيل القسريّ كان سيُخرج كلّ ما لم
     * يُرحَّل بعدُ من نتائج البحث فجأةً.
     */
    private function fromLegacy(Product $product): ProductSearchDocument
    {
        $product->loadMissing(['price', 'seo', 'stage', 'grade', 'subject', 'semester']);

        $effective = null;
        $sale = null;
        if ($product->price !== null) {
            $sale = PricingService::activeSaleMinor($product->price);
            $effective = $sale ?? (int) $product->price->regular_minor;
        }

        return new ProductSearchDocument(
            id: $product->id,
            sku: $product->sku,
            title: $product->title,
            subtitle: $product->subtitle,
            shortDescription: $product->short_desc,
            description: $product->description,
            /*
             * الطبيعة من الترجمة المركزيّة لا من `match` هنا.
             *
             * كان `default => 'DIGITAL'` يحوّل كلّ مجهولٍ إلى «رقميّ» بصمت،
             * فيُفهرَس منتجٌ هجين أو وصوليّ رقميًّا ويُرشَّح بما ليس هو.
             * والترجمة المركزيّة تُعيد `null` للمجهول، فيُختار `DIGITAL`
             * هنا **صراحةً** بوصفه سقوطًا معلومًا لا افتراضًا مخبوءًا —
             * وهذا الفرع لا يُبلَغ إلّا لمنتجٍ لا مجموعةَ له، إذ النسخة
             * العليا `fromGroup` تقرأ الطبيعة الحقيقيّة.
             */
            nature: ProductCapabilities::natureOfLegacy($product->fulfillment_type) ?? 'DIGITAL',
            synonyms: $this->synonyms($product),
            stage: $product->stage?->slug,
            grade: $product->grade?->slug,
            subject: $product->subject?->slug,
            semester: $product->semester?->slug,
            resourceType: $product->resource_type,
            formats: $product->format === null ? [] : [mb_strtoupper($product->format)],
            editable: $product->editable === 'unset' ? null : $product->editable === 'yes',
            priceMinMinor: $effective,
            priceMaxMinor: $effective,
            onSale: $sale !== null,
            discountPct: $sale === null ? null : PricingService::discountPct((int) $product->price->regular_minor, $sale),
            ratingAvg: $this->rating($product)['avg'],
            ratingCount: $this->rating($product)['count'],
            salesCount: $this->salesCount($product),
            updatedAt: $product->updated_at?->getTimestamp() ?? 0,
            publishedAt: $product->published_at?->getTimestamp() ?? 0,
            normalizedText: $this->documentText([
                $product->title, $product->subtitle, $product->short_desc, $product->description,
                ...$this->synonyms($product),
            ]),
            extractedText: $this->extractedText($product),
        );
    }

    /**
     * نصّ الوثيقة كلّه مطويًّا في حقلٍ واحد.
     *
     * هنا تُدمج الحقول عمدًا — خلاف العمود المخزَّن: المحرّك يوزن بترتيب
     * `searchableAttributes` ولا يقرأ أوزاننا، فالحقل المطويّ يزيد الإدراك
     * ولا يُنشئ طبقةً جديدة. والمصدر نصّ **الوثيقة** لا صفّ المنتج: الوثيقة
     * تُبنى من المجموعة حين توجد، وفهرسةُ نصٍّ غير المفهرس تعيد العيب نفسه
     * من الجهة الأخرى.
     *
     * @param  list<string|null>  $parts
     */
    private function documentText(array $parts): ?string
    {
        $present = array_filter(array_map(static fn ($p) => trim((string) $p), $parts), static fn (string $p) => $p !== '');
        $text = ArabicNormalizer::normalize(implode(' ', $present));

        return $text === '' ? null : $text;
    }

    /**
     * نصّ ملفّات هذا المنتج وحده.
     *
     * يمرّ بالدالّة الساكنة نفسها التي يستعملها المزوّد الحيّ، فشرطُ `ok`
     * وقاعدةُ الدمج مكتوبان مرّةً واحدة. ونسخُهما هنا كان سيسمح بأن يرى
     * المحرّك نصًّا لا يراه PostgreSQL — وهو التباعد الذي عضّ هذا المستودع
     * مرّتين (انظر `ProductSearchDocument::$normalizedText`).
     */
    private function extractedText(Product $product): ?string
    {
        $text = self::extractedTextFor([(int) $product->id])[(int) $product->id] ?? '';

        return $text === '' ? null : $text;
    }

    /** @return list<string> */
    private function formats(ProductGroup $group, Product $product): array
    {
        $ids = $group->variants->pluck('id');

        $fromAttributes = AttributeValue::query()
            ->whereIn('product_variant_id', $ids)
            ->whereHas('definition', fn ($q) => $q->where('key', 'file_format'))
            ->pluck('text_value')
            ->filter()->map(fn ($v) => mb_strtoupper((string) $v))->unique()->values()->all();

        if ($fromAttributes !== []) {
            return $fromAttributes;
        }

        return $product->format === null ? [] : [mb_strtoupper($product->format)];
    }

    /**
     * السمات المُعلَن أنّها قابلة للتصفية وحدها (§٤٣).
     *
     * فهرسةُ كلّ سمةٍ تُضخّم المستند بما لا يُرشَّح به أحد، وتجعل إعدادات
     * المحرّك تنمو بلا سقف. العلم في السجلّ هو القرار.
     *
     * @return array<string, string|int|bool>
     */
    private function filterableAttributes(ProductGroup $group): array
    {
        $out = [];

        foreach ($group->attributeValues as $value) {
            $definition = $value->definition;
            if ($definition === null || ! $definition->filterable) {
                continue;
            }

            $typed = $value->typedValue();
            if ($typed === null || is_array($typed)) {
                continue;
            }

            $out[$definition->key] = $typed;
        }

        return $out;
    }

    private function attributeText(ProductGroup $group, string $key): ?string
    {
        foreach ($group->attributeValues as $value) {
            if ($value->definition?->key === $key) {
                return $value->text_value;
            }
        }

        return null;
    }

    private function attributeBool(ProductGroup $group, string $key): ?bool
    {
        foreach ($group->attributeValues as $value) {
            if ($value->definition?->key === $key) {
                return $value->bool_value;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function useCases(ProductGroup $group): array
    {
        return DB::table('product_use_cases')
            ->join('use_cases', 'use_cases.id', '=', 'product_use_cases.use_case_id')
            ->where('product_use_cases.product_group_id', $group->id)
            ->pluck('use_cases.name')->all();
    }

    /** @return list<string> */
    private function compatibility(ProductGroup $group): array
    {
        return ProductCompatibility::query()
            ->where('product_group_id', $group->id)
            ->pluck('target_name')->all();
    }

    /** @return list<string> */
    private function synonyms(Product $product): array
    {
        $raw = $product->seo?->synonyms;

        return $raw === null || trim($raw) === '' ? [] : [trim($raw)];
    }

    /** @return array{avg: float|null, count: int|null} */
    private function rating(Product $product): array
    {
        $aggregate = DB::table('review_aggregates')->where('product_id', $product->id)->first();

        // التقييم يُفهرَس حين يوجد فعلًا — لا صفرٌ يُقرأ «سيّئ».
        return $aggregate !== null && (int) $aggregate->count > 0
            ? ['avg' => (float) $aggregate->avg_rating, 'count' => (int) $aggregate->count]
            : ['avg' => null, 'count' => null];
    }

    private function salesCount(Product $product): int
    {
        return DB::table('entitlements')->where('product_id', $product->id)->count();
    }
}
