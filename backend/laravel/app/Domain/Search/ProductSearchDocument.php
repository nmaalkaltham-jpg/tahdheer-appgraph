<?php

namespace App\Domain\Search;

/**
 * الإسقاط القانونيّ للبحث (§٦٣ §١٢٤).
 *
 * §١٢٤ صريحة: «محرّك البحث ليس قاعدةَ بيانات المنتج القانونيّة»، والفهرس
 * **مشتقّ** من هذا الإسقاط لا مبنيٌّ داخل المزوّد.
 *
 * والفرق عمليّ لا تنظيميّ: بناء المستند داخل محوّل Meilisearch كان يجعل
 * البديل (PostgreSQL) يبني شكلًا آخر من الجداول نفسها. فيبحث الزبون بكلمةٍ
 * فيجدها على محرّك ولا يجدها على الآخر، ولا أحد يعرف أيّهما الصحيح لأنّ
 * «الصحيح» لم يكن مكتوبًا في مكان.
 *
 * ولا يُفهرَس ما يُدفع ثمنه: مفاتيح الملفّات وعناوينها لا تدخل هنا أبدًا،
 * فالفهرس يُقرأ من الواجهة العامّة (§١٧).
 */
final readonly class ProductSearchDocument
{
    /**
     * @param  list<string>  $synonyms
     * @param  list<string>  $fulfillmentStrategies
     * @param  list<string>  $formats  صيغ الملفّات عبر كلّ الصيغ
     * @param  array<string, string|int|bool>  $attributes  السمات القابلة للتصفية
     * @param  list<string>  $useCases
     * @param  list<string>  $compatibility
     */
    public function __construct(
        public int $id,
        public ?string $sku,
        public string $title,
        public ?string $subtitle,
        public ?string $shortDescription,
        /*
         * الوصف الكامل — كان خارج الوثيقة كلّها.
         *
         * ٢٤ منتجًا من ٢٧ تحمل وصفًا (بمتوسّط ٢٣٩ محرفًا)، ولم يكن يصل إلى
         * الفهرس ولا إلى المطابقة: الباحث عن عبارةٍ لا ترد إلّا في الوصف
         * يحصل على صفر نتائج بينما المنتج أمامه في القاعدة. والوثيقة هي
         * «تمثيل البحث القانونيّ» (§١٠) فلا يصحّ أن ينقصها نصٌّ موجود.
         */
        public ?string $description,
        public ?string $nature,
        public array $synonyms = [],
        public ?string $stage = null,
        public ?string $grade = null,
        public ?string $subject = null,
        public ?string $semester = null,
        public ?string $resourceType = null,
        public array $formats = [],
        public ?bool $editable = null,
        /*
         * نطاقُ سعرٍ لا سعرٌ واحد. الصيغ الثلاث بأثمانٍ مختلفة (A4 وA5
         * ومطبوع) كانت تُفهرَس بثمنٍ واحد، فيُرشِّح الزبون «أقلّ من ٣٠» فيسقط
         * منتجٌ له صيغةٌ بـ٢٥ — وهو بالضبط ما يبحث عنه.
         */
        public ?int $priceMinMinor = null,
        public ?int $priceMaxMinor = null,
        public bool $onSale = false,
        public ?int $discountPct = null,
        public array $fulfillmentStrategies = [],
        /* §٦٣ — التوافر. منتجٌ نفد مخزونه يظهر ويُرشَّح لا يختفي صامتًا. */
        public bool $inStock = true,
        public int $variantCount = 1,
        public array $attributes = [],
        public array $useCases = [],
        public array $compatibility = [],
        public ?float $ratingAvg = null,
        public ?int $ratingCount = null,
        public int $salesCount = 0,
        public int $updatedAt = 0,
        /** وقتُ النشر — «الأحدث» يعني ما نُشر حديثًا لا ما عُدّل حديثًا. */
        public int $publishedAt = 0,
        /*
         * النصّ المطويّ للوثيقة — ما يغلق تباين المزوّدَين.
         *
         * مسار PostgreSQL يطوي **الطرفين**: الاستعلام والنصّ المخزّن
         * (`PgFallbackProvider::score`). ومسار Meilisearch كان يفهرس النصّ
         * **خامًا** ويطوي الاستعلام وحده (`QueryPipeline` السطر ٧٦)، فعنوانٌ
         * فيه «أوراق» يُوجَد بالبحث عن «اوراق» على محرّك ولا يُوجَد على
         * الآخر — والمتجر نفسه يجيب جوابين حسب أيّهما يخدم الطلب.
         *
         * والبديل المرفوض: طيُّ الحقول المعروضة نفسها (`title` و`short_desc`)
         * قبل الفهرسة. يوحّد المطابقة ويُفسد العرض — المحرّك يُعيد التظليل
         * والمقتطفات من النصّ المفهرس، فيرى الزبون عنوانه بلا همزات. فحقلٌ
         * إضافيّ يُطابَق عليه، والحقول المعروضة تبقى كما كتبها الناشر.
         */
        public ?string $normalizedText = null,
        /*
         * نصّ الملفّات المستخرَج — آخرُ حلقةٍ في السلسلة: مُنتَج ← فهمُ ملفّ
         * ← ذكاءُ محتوى ← فهرسُ بحث. كان خطّ الاستخراج يُنتج نصًّا حقيقيًّا
         * (٢٧٦ وحدة في ٢٦ صفًّا `ok`، مقيسة هذه الجلسة) ولا يراه البحث
         * البتّة: الكلمة التي لا ترد إلّا في الملفّ تُعيد صفرًا.
         *
         * وهو **داخليّ**: يُطابَق عليه ولا يُعرَض. لا يدخل استجابةَ البحث
         * (المحرّك يُسأل عن `id` وحده) ولا مورد المنتج، لأنّ محتوى ملفٍّ
         * مدفوع مكشوفًا يُلغي الحاجة إلى شرائه (البندان ٥٨ و١٢٦).
         *
         * والبديل المرفوض: حشرُه في `description`. يجعله نسخةَ المنتج التي
         * يقرأها الزبون، ويرفع رتبته إلى رتبة الوصف — وكلاهما ممنوع.
         */
        public ?string $extractedText = null,
    ) {}

    /**
     * الشكل المسطَّح الذي يبتلعه المحرّك.
     *
     * القيم الفارغة تُحذف ولا تُرسل `null`: Meilisearch يُرشِّح على وجود
     * الحقل، فحقلٌ قيمته null يجعل المنتج يُطابق «له تقييم» وهو بلا تقييم.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $doc = [
            'id' => $this->id,
            'sku' => $this->sku,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'short_desc' => $this->shortDescription,
            'description' => $this->description,
            'nature' => $this->nature,
            'synonyms' => $this->synonyms === [] ? null : implode(' ', $this->synonyms),
            'stage' => $this->stage,
            'grade' => $this->grade,
            'subject' => $this->subject,
            'semester' => $this->semester,
            'resource_type' => $this->resourceType,
            /* يبقى مفردًا للتوافق مع إعدادات الفهرس القائمة، وأوّلُه الأعمّ. */
            'format' => $this->formats[0] ?? null,
            'formats' => $this->formats === [] ? null : $this->formats,
            'editable' => $this->editable,
            'price_minor' => $this->priceMinMinor,
            'price_max_minor' => $this->priceMaxMinor,
            'on_sale' => $this->onSale,
            'discount_pct' => $this->discountPct,
            'fulfillment' => $this->fulfillmentStrategies === [] ? null : $this->fulfillmentStrategies,
            'in_stock' => $this->inStock,
            'variant_count' => $this->variantCount,
            'use_cases' => $this->useCases === [] ? null : $this->useCases,
            'compatibility' => $this->compatibility === [] ? null : $this->compatibility,
            'rating_avg' => $this->ratingAvg,
            'rating_count' => $this->ratingCount,
            'sales_count' => $this->salesCount,
            'updated_at' => $this->updatedAt,
            'published_at' => $this->publishedAt,
            /* حقلُ المطابقة المطويّ؛ العرضُ يبقى على الحقول الخام أعلاه. */
            /*
             * نصّ الملفّات: مفهرسٌ للمطابقة، غيرُ معروض. `MeilisearchProvider`
             * يضعه آخرَ `searchableAttributes` — أخفّ وزنٍ في المحرّك، مقابل
             * أخفّ طبقةٍ في المزوّد الحيّ (`W_FILE_TOKEN`). ولا يُقرأ من
             * المحرّك أبدًا: `search` و`suggest` يطلبان `attributesToRetrieve
             * => ['id']` ثمّ يُرطَّب المنتج من القاعدة.
             */
            'extracted_content' => $this->extractedText,
        ];

        foreach ($this->attributes as $key => $value) {
            // بادئةٌ تمنع سمةً باسم `title` من أن تدهس عنوان المنتج.
            $doc['attr_'.$key] = $value;
        }

        return array_filter($doc, static fn ($v) => $v !== null);
    }
}
