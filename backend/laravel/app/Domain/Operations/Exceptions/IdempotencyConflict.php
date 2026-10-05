<?php

namespace App\Domain\Operations\Exceptions;

use App\Domain\Operations\Models\OperationRecord;
use RuntimeException;

/**
 * المفتاح نفسه بطلبٍ مختلف.
 *
 * ─── لماذا ٤٠٩ لا إعادة النتيجة ───
 *
 * الخمول عقدٌ طرفاه المفتاح **والطلب**. عميلٌ يُعيد مفتاحًا بجسمٍ مختلف
 * إمّا أخطأ في توليد المفاتيح وإمّا يظنّ أنّه يرسل شيئًا آخر. وإعادةُ نتيجة
 * الطلب الأوّل إليه في الحالتين كذب: يطلب نقل خمسة فيُقال له تمّ، والذي
 * تمّ نقلُ عشرة قبل دقيقة.
 *
 * الردّ الصحيح هو الرفض الصريح: لا نُنفّذ، ولا ندّعي أنّنا نفّذنا.
 */
class IdempotencyConflict extends RuntimeException
{
    public function __construct(public readonly OperationRecord $existing)
    {
        parent::__construct('مفتاح الخمول مستعمَلٌ لطلبٍ مختلف — استعمل مفتاحًا جديدًا.');
    }
}
