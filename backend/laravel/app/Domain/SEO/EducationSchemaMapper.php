<?php

namespace App\Domain\SEO;

use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductCapabilities;
use App\Models\Product;

/**
 * تحويل المنتج التعليمي إلى كيان LearningResource / Course / DigitalDocument.
 *
 * Ported from the legacy theme's `inc/product-education-schema.php`
 * (`tahdheer_edu_schema_resource_entity`, `..._encoding_format`,
 * `..._iso_duration`). The intent that carried over verbatim:
 *
 *   · The educational node is SEPARATE from Product and linked by `@id` — the
 *     Product node is never duplicated.
 *   · No educational metadata at all ⇒ NO entity («بلا نوع صريح وبلا أي بيانات
 *     تعليمية: لا كيان — تجنّبًا لبيانات بلا معنى»).
 *   · Type selection: explicit `course` ⇒ Course, explicit `digital_document`
 *     or a downloadable digital product ⇒ DigitalDocument, otherwise
 *     LearningResource.
 *   · Course uses `provider`; every other type uses `publisher`.
 *   · The Arabic duration parser and the MIME map are ported as-is.
 */
final class EducationSchemaMapper
{
    /** Written file format → standard media type (legacy `..._encoding_format`). */
    private const MIME_MAP = [
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** Arabic-Indic digits → Latin, for the duration parser. */
    private const ARABIC_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /** Resource types the catalog treats as a taught course rather than a file. */
    private const COURSE_TYPES = ['دورة', 'course', 'برنامج تدريبي'];

    public function __construct(private readonly SiteIdentity $site) {}

    /**
     * Build the educational node, or null when the product carries no real
     * educational metadata.
     *
     * @param  array<string, scalar|null>  $facts  key => value, as rendered in the visible facts table
     * @return array<string, mixed>|null
     */
    public function entity(Product $product, array $facts = []): ?array
    {
        $canonicalUrl = $this->site->url('/products/'.$product->slug);

        $level = $this->level($product);
        $subject = $product->relationLoaded('subject') && $product->subject !== null
            ? $product->subject->name_ar
            : null;
        $encodings = $this->encodingFormats($product);
        $duration = $this->isoDuration($facts['duration'] ?? $facts['course_duration'] ?? null);

        // No educational signal whatsoever ⇒ no node. Never an empty shell.
        if ($level === null && $subject === null && $encodings === [] && $duration === null) {
            return null;
        }

        $type = $this->entityType($product, $encodings);

        $entity = [
            '@type' => $type,
            '@id' => $canonicalUrl.'#educational-resource',
            'name' => $product->title,
            'url' => $canonicalUrl,
            'inLanguage' => 'ar-SA',
        ];

        $description = TextBudget::normalize($product->short_desc ?? $product->description);

        if ($description !== null) {
            $entity['description'] = $description;
        }

        $publisher = ['@id' => $this->site->organizationId()];

        // Legacy rule: Course uses provider, everything else uses publisher.
        $entity[$type === 'Course' ? 'provider' : 'publisher'] = $publisher;

        if ($level !== null) {
            $entity['educationalLevel'] = $level;
        }

        if ($subject !== null) {
            $entity['about'] = ['@type' => 'Thing', 'name' => $subject];
            $entity['teaches'] = $subject;
        }

        if ($encodings !== []) {
            /*
             * صيغةٌ واحدة تُكتب نصًّا ومتعدّدةٌ تُكتب قائمة: `encodingFormat`
             * يقبل الاثنين، وقائمةٌ من عنصرٍ واحد تُعلن تعدّدًا لا وجود له.
             */
            $entity['encodingFormat'] = count($encodings) === 1 ? $encodings[0] : $encodings;
        }

        /*
         * عدد الصفحات من الحقيقة المنشورة نفسها التي يصيّرها جدول «لمحة
         * سريعة»، لا من تقديرٍ ولا من حجم ملفّ. غائبةٌ ⇒ المفتاح غائب.
         */
        $pages = $facts['pages_count'] ?? null;

        if (is_int($pages) || (is_string($pages) && ctype_digit($pages))) {
            $pages = (int) $pages;

            if ($pages > 0) {
                $entity['numberOfPages'] = $pages;
            }
        }

        if ($duration !== null) {
            $entity['timeRequired'] = $duration;
        }

        if ($type === 'LearningResource' || $type === 'DigitalDocument') {
            $entity['learningResourceType'] = $product->resource_type ?? 'مورد تعليمي';
        }

        $properties = $this->properties($product, $facts);

        if ($properties !== []) {
            $entity['additionalProperty'] = $properties;
        }

        return $entity;
    }

    /**
     * Legacy type selection. `resource_type` is the editor's explicit signal;
     * a digital product with a file format falls back to DigitalDocument.
     *
     * @param  list<string>  $encodings  media types already resolved for this product
     */
    private function entityType(Product $product, array $encodings): string
    {
        $resourceType = mb_strtolower(trim((string) ($product->resource_type ?? '')));

        foreach (self::COURSE_TYPES as $courseType) {
            if ($resourceType !== '' && str_contains($resourceType, mb_strtolower($courseType))) {
                return 'Course';
            }
        }

        /*
         * §١٥٨ — المجهول لا يصير «رقميًّا» بالصمت.
         *
         * كان `?? 'digital'` يجعل كلّ منتجٍ بلا نوعٍ معروف يُعلَن
         * `DigitalDocument` في بيانات المدرسة — أي أنّ محرّك البحث يُخبَر
         * بشيءٍ لم يقله أحد. والترجمة المركزيّة تُعيد `null` للمجهول، وهنا
         * يُعامَل المجهول غيرَ رقميّ فيسقط إلى `LearningResource` العامّة.
         */
        $isDigital = ProductCapabilities::natureOfLegacy($product->fulfillment_type) === 'DIGITAL';

        if ($isDigital && $encodings !== []) {
            return 'DigitalDocument';
        }

        return 'LearningResource';
    }

    /** Educational level from the real curriculum relations, never guessed. */
    private function level(Product $product): ?string
    {
        foreach (['grade', 'stage'] as $relation) {
            if ($product->relationLoaded($relation) && $product->{$relation} !== null) {
                return $product->{$relation}->name_ar;
            }
        }

        return null;
    }

    /**
     * Arabic PropertyValue list built only from facts the page renders in its
     * visible facts table.
     *
     * @param  array<string, scalar|null>  $facts
     * @return list<array<string, mixed>>
     */
    private function properties(Product $product, array $facts): array
    {
        $labels = [
            'slides_count' => 'عدد الشرائح',
            'pages_count' => 'عدد الصفحات',
            'worksheets_count' => 'عدد أوراق العمل',
            'activities_count' => 'عدد الأنشطة',
            'lessons_count' => 'عدد الدروس',
        ];

        $properties = [];
        $seen = [];

        foreach ($facts as $key => $value) {
            $name = $labels[$key] ?? null;

            if ($name === null || $value === null || $value === '') {
                continue;
            }

            // Legacy dedupe-by-name: one PropertyValue per visible label.
            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $properties[] = [
                '@type' => 'PropertyValue',
                'name' => $name,
                'value' => is_int($value) ? $value : (string) $value,
            ];
        }

        if ($product->relationLoaded('semester') && $product->semester !== null) {
            $properties[] = [
                '@type' => 'PropertyValue',
                'name' => 'الفصل الدراسي',
                'value' => $product->semester->name_ar,
            ];
        }

        return $properties;
    }

    /**
     * أنواع الوسائط التي تُباع فعلًا لهذا المنتج.
     *
     * الصيغ المنشورة هي ما يعرضه مُبدِّل الصيغ في الصفحة (§٤١)، ومنتجٌ
     * بثلاث صيغٍ (pdf · docx · pptx) كان يُعلن نوعًا واحدًا لمحرّك البحث —
     * وهو خلافٌ بين النتيجة الغنيّة وما في الصفحة. والمسوّدة والمتقاعدة
     * تُستبعدان لأنّهما لا تُباعان.
     *
     * والقراءة دفاعيّة كقراءة {@see SchemaBuilder::variantPrices()}: العلاقة
     * المحمَّلة أوّلًا، ثمّ استعلامٌ واحد، ثمّ صيغة `products` الواحدة لمنتجٍ
     * خارج النموذج الكونيّ. ولا يُخترع نوعُ وسائطٍ لصيغةٍ مجهولة.
     *
     * @return list<string>
     */
    private function encodingFormats(Product $product): array
    {
        $group = $product->relationLoaded('group')
            ? $product->group
            : ProductGroup::query()
                ->where('legacy_product_id', $product->id)
                ->with('publishedVariants')
                ->first();

        $formats = [];

        if ($group !== null) {
            foreach ($group->publishedVariants as $variant) {
                /** @var ProductVariant $variant */
                $mime = $this->encodingFormat($variant->format);

                if ($mime !== null && ! in_array($mime, $formats, true)) {
                    $formats[] = $mime;
                }
            }
        }

        if ($formats !== []) {
            return $formats;
        }

        $mime = $this->encodingFormat($product->format);

        return $mime === null ? [] : [$mime];
    }

    /** Written format → media type, or null when the format is unknown. */
    public function encodingFormat(?string $format): ?string
    {
        $format = mb_strtolower(trim((string) $format));
        $format = ltrim($format, '.');

        return self::MIME_MAP[$format] ?? null;
    }

    /**
     * Arabic/Latin duration text → ISO-8601 duration, or null.
     * Ported from `tahdheer_edu_schema_iso_duration`.
     */
    public function isoDuration(mixed $text): ?string
    {
        if (! is_string($text) && ! is_int($text)) {
            return null;
        }

        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        // Already a valid ISO-8601 duration.
        if (preg_match('/^P(?:\d+D)?(?:T(?:\d+H)?(?:\d+M)?(?:\d+S)?)$/', $text) === 1) {
            return $text;
        }

        $normalized = strtr($text, self::ARABIC_DIGITS);

        $hours = 0;
        $minutes = 0;
        $found = false;

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(ساعات|ساعة|ساعه|hours|hour|hrs|hr|h)/iu', $normalized, $m) === 1) {
            $value = (float) str_replace(',', '.', $m[1]);
            $hours = (int) floor($value);
            $minutes += (int) round(($value - $hours) * 60);
            $found = true;
        }

        if (preg_match('/(\d+)\s*(دقائق|دقيقة|دقيقه|minutes|minute|mins|min|m)/iu', $normalized, $m) === 1) {
            $minutes += (int) $m[1];
            $found = true;
        }

        if (! $found) {
            return null;
        }

        $hours += intdiv($minutes, 60);
        $minutes %= 60;

        if ($hours < 1 && $minutes < 1) {
            return null;
        }

        return 'PT'.($hours > 0 ? $hours.'H' : '').($minutes > 0 ? $minutes.'M' : '');
    }
}
