<?php

namespace App\Domain\Orders;

use RuntimeException;

/**
 * المفتاح نفسه بجسمٍ آخر: خطأٌ صريح لا «الأوّل» صامتًا.
 *
 * إعادةُ الإرسال بعد ضياع الردّ تحمل الجسم نفسه فتعود بالاسترداد الأوّل.
 * أمّا مبلغٌ آخر أو سياسةٌ أخرى بالمفتاح نفسه فطلبٌ آخر تمامًا — إرجاعُ
 * الأوّل كان يقول للمشغّل «رُدّ ١٥٠» وقد رُدّ ١٠٠.
 */
final class RefundIdempotencyConflict extends RuntimeException
{
    public function __construct(public readonly int $existingRefundId, string $detail)
    {
        parent::__construct("Idempotency key reused with a different request ({$detail}); existing refund #{$existingRefundId}.");
    }
}
