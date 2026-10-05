<?php

declare(strict_types=1);

namespace App\Domain\Search\Evaluation;

/**
 * حكمُ الصلة من النيّة المُهيكَلة لا من نصّ الاستعلام.
 *
 * النيّة تُكتب عند توليد السؤال (ما قصده السائل)، والحكم يُقارنها بحقائق
 * المنتج في الكتالوج. فالحكم لا يرى ما فهمه المحرّك — لا دائرة.
 *
 *   3 كامل      كلّ بُعدٍ ذُكر يطابق (أو المنتج المقصود بالعنوان نفسه)
 *   2 قويّ      المادّة (والصفّ إن ذُكر) تطابق؛ النوع أو الفصل أو الصيغة يختلف
 *   1 ضعيف     المادّة صحيحة والصفّ خاطئ، أو الصفّ صحيح والنوع خاطئ بلا مادّة
 *   0 خاطئ     مادّةٌ أو مرحلةٌ خاطئة — «سلبيّ صلب» حين يظهر في القمّة
 */
final class RelevanceJudge
{
    /**
     * @param  array{subject_id?: ?int, grade_id?: ?int, stage_id?: ?int, semester_id?: ?int, kind?: ?string, format?: ?string, product_id?: ?int}  $intent
     * @param  array{id: int, subject_id: ?int, grade_id: ?int, stage_id: ?int, semester_id: ?int, kind: ?string, format: ?string}  $product
     */
    public static function grade(array $intent, array $product): int
    {
        if (($intent['product_id'] ?? null) !== null && $intent['product_id'] === $product['id']) {
            return 3;
        }

        $want = static fn (string $k) => $intent[$k] ?? null;
        $same = static fn (string $k): bool => $want($k) === null || $want($k) === $product[$k];

        if (! $same('subject_id') || ! $same('stage_id')) {
            return 0;
        }

        if (! $same('grade_id')) {
            return $want('subject_id') !== null ? 1 : 0;
        }

        $specified = array_filter(['subject_id', 'grade_id', 'stage_id', 'semester_id', 'kind', 'format'], static fn (string $k): bool => $want($k) !== null);
        if ($specified === []) {
            return 0;
        }

        $secondaryMiss = ! $same('semester_id') || ! $same('kind') || ! $same('format');
        if (! $secondaryMiss) {
            return 3;
        }

        /*
         * «قويّ» يحتاج المادّة: صفٌّ صحيح بنوعٍ آخر ولا مادّةَ مذكورة
         * («تحضير سادس» ⇐ أوراقُ عملٍ للسادس) ضعيفٌ لا قويّ. والنوع وحده
         * بلا مادّةٍ ولا صفّ: خطؤه يُسقط الصلة كلّها.
         */
        if ($want('subject_id') !== null) {
            return 2;
        }

        return $want('grade_id') !== null ? 1 : 0;
    }
}
