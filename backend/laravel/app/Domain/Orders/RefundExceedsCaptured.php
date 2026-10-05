<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use RuntimeException;

/**
 * استردادٌ يتجاوز ما حُصِّل — يُرفض قبل أن يخرج المال.
 *
 * ─── لماذا استثناءٌ لا تحقّقُ مدخلات ───
 *
 * تحقّقُ المدخلات يقع في وحدة التحكّم، فيتخطّاه كلّ مستدعٍ آخر: مهمّة،
 * عمليّة جماعيّة، نقطة نهاية جديدة. والثابت الماليّ يجب أن يُفرض حيث يقع
 * الأثر — في النطاق — لا حيث يبدأ الطلب.
 *
 * ويحمل الأرقام الثلاثة: المحصَّل، والمستردّ سلفًا، والمطلوب. فالمشغّل يقرأ
 * **لماذا** رُفض لا أنّه رُفض.
 */
final class RefundExceedsCaptured extends RuntimeException
{
    public function __construct(
        public readonly int $capturedMinor,
        public readonly int $alreadyRefundedMinor,
        public readonly int $requestedMinor,
    ) {
        parent::__construct(sprintf(
            'الاسترداد يتجاوز المحصَّل: حُصِّل %d، استُرد %d، والمطلوب %d.',
            $capturedMinor, $alreadyRefundedMinor, $requestedMinor,
        ));
    }

    public function remainingMinor(): int
    {
        return max(0, $this->capturedMinor - $this->alreadyRefundedMinor);
    }
}
