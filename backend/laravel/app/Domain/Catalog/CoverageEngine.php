<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Product;

/**
 * تغطية التخصّص — حكم قابل للتفسير، لا درجة مخترَعة.
 *
 * لا تصنيف جديد: يقرأ المنهج الموجود (مرحلة/صف/مادة/فصل) ويحكم بقواعد صريحة.
 *
 * القاعدة الحاكمة: **لا درجة بلا بيانات**. إن نقصت بيانات المنتج فالحالة
 * `INSUFFICIENT_DATA` لا رقم مئوي — «تغطية 75٪» بلا مصدر تُقرأ حقيقةً وتُبنى
 * عليها قرارات، وهي أسوأ من الاعتراف بالجهل.
 *
 * كل حكم يحمل رموز أسباب، فيستطيع المشرف أن يعرف *لماذا* لا أن يصدّق فقط.
 */
final class CoverageEngine
{
    public const COVERED = 'covered';

    public const PARTIAL = 'partial';

    public const NOT_COVERED = 'not_covered';

    public const INSUFFICIENT = 'insufficient_data';

    /**
     * الأبعاد التي تُقاس عليها التغطية، بترتيب الأهمية.
     *
     * @var array<string, array{label: string, blocking: bool}>
     */
    private const DIMENSIONS = [
        'stage' => ['label' => 'المرحلة', 'blocking' => true],
        'subject' => ['label' => 'المادة', 'blocking' => true],
        'grade' => ['label' => 'الصف', 'blocking' => false],
        'semester' => ['label' => 'الفصل', 'blocking' => false],
    ];

    /**
     * @return array{
     *   status: string, covered: list<string>, missing: list<string>,
     *   reasonCodes: list<string>, dimensions: array<string, array<string, mixed>>,
     *   siblings: array{sameSubjectGrade: int, sameSubjectStage: int}|null
     * }
     */
    public function forProduct(Product $product): array
    {
        $product->loadMissing(['stage', 'grade', 'subject', 'semester']);

        $covered = [];
        $missing = [];
        $reasons = [];
        $dimensions = [];

        foreach (self::DIMENSIONS as $key => $meta) {
            $relation = $product->{$key};
            $present = $relation !== null;

            $dimensions[$key] = [
                'label' => $meta['label'],
                'present' => $present,
                'value' => $present ? $relation->name_ar : null,
                'blocking' => $meta['blocking'],
            ];

            if ($present) {
                $covered[] = $key;
            } else {
                $missing[] = $key;
                $reasons[] = ($meta['blocking'] ? 'MISSING_REQUIRED_' : 'MISSING_OPTIONAL_').strtoupper($key);
            }
        }

        $blockingMissing = array_values(array_filter(
            $missing,
            static fn (string $k): bool => self::DIMENSIONS[$k]['blocking'],
        ));

        /*
         * بلا المرحلة أو المادة لا يمكن الحكم أصلًا — لا «غير مغطّى» ولا
         * «مغطّى جزئيًّا»، فكلاهما ادّعاء معرفة. الحالة: بيانات ناقصة.
         */
        $status = match (true) {
            $blockingMissing !== [] => self::INSUFFICIENT,
            $missing === [] => self::COVERED,
            default => self::PARTIAL,
        };

        return [
            'status' => $status,
            'covered' => $covered,
            'missing' => $missing,
            'reasonCodes' => $reasons,
            'dimensions' => $dimensions,
            // الجيران يُحسبون من الكتالوج الحقيقي، ولا يُحسبون بلا بُعد حاصر.
            'siblings' => $status === self::INSUFFICIENT ? null : $this->siblings($product),
        ];
    }

    /**
     * عدد المنتجات المنشورة التي تشترك في المادة — إشارة إلى اتّساع التغطية
     * لا حكم عليها.
     *
     * @return array{sameSubjectGrade: int, sameSubjectStage: int}
     */
    private function siblings(Product $product): array
    {
        $base = Product::query()
            ->where('status', 'published')
            ->where('id', '!=', $product->id)
            ->where('subject_id', $product->subject_id);

        return [
            'sameSubjectGrade' => $product->grade_id === null
                ? 0
                : (clone $base)->where('grade_id', $product->grade_id)->count(),
            'sameSubjectStage' => (clone $base)->where('stage_id', $product->stage_id)->count(),
        ];
    }
}
