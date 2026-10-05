<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

use RuntimeException;

/**
 * فشل نشر لا يمكن للمنسّق تجاوزه.
 *
 * يُرمى فقط حين تعذّر تسجيل الفشل نفسه في سجلّ العمليات — أي حين لا يستطيع
 * النظام أن يقول ماذا حدث. الفشل المعروف يُسجَّل في السجلّ ويُعاد عمليةً
 * حالتها FAILED، لا استثناءً يصعد إلى المتصفّح.
 */
final class NavigationPublishFailed extends RuntimeException
{
    /** مفتاح تكرارٍ سبق استعماله لقائمة أخرى — إعادته هنا كذبٌ لا تكرار. */
    public static function keyReused(string $key): self
    {
        return new self("مفتاح التكرار «{$key}» مستعمَلٌ لقائمة أخرى. استعمل مفتاحًا جديدًا لهذا الموقع.");
    }
}
