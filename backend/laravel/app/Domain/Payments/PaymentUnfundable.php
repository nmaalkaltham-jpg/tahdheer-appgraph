<?php

namespace App\Domain\Payments;

use RuntimeException;

/**
 * تحصيلٌ وصل ولا يجوز أن يُموِّل هذا الطلب.
 *
 * تُرمى **بعد** أن يُسجَّل التحصيل بمبلغه الحقيقيّ ورسمه: المال وصل فعلًا
 * ولا يُخفى. ما لا يقع هو التمويل: لا «مدفوع» ولا استحقاق ولا تنفيذ. والسبب
 * رمزٌ ثابت يُحفظ على التسليم وتقرؤه التسوية والحالات.
 */
final class PaymentUnfundable extends RuntimeException
{
    public const AMOUNT_MISMATCH = 'AMOUNT_MISMATCH';

    public const CURRENCY_MISMATCH = 'CURRENCY_MISMATCH';

    /** الطلب في حالةٍ لا تُموَّل (مُلغى، فاشل): رسمٌ متأخّر بعد الإلغاء. */
    public const ORDER_NOT_FUNDABLE = 'ORDER_NOT_FUNDABLE';

    /** الرسم أُنشئ نيّةً لطلبٍ آخر: لا يُموِّل غير صاحبه. */
    public const CHARGE_BELONGS_TO_ANOTHER_ORDER = 'CHARGE_BELONGS_TO_ANOTHER_ORDER';

    /** رسمٌ لم يُنشئه المتجر لأيّ طلب — والطلب في الحمولة غير موقَّع. */
    public const UNKNOWN_CHARGE = 'UNKNOWN_CHARGE';

    /** تحصيلٌ رُدّ منه شيء (أو يُردّ): لا يُموِّل بعدها. */
    public const CAPTURE_REFUNDED = 'CAPTURE_REFUNDED';

    /** الرسم عند تاجرٍ آخر غير تاجر المتجر. */
    public const MERCHANT_MISMATCH = 'MERCHANT_MISMATCH';

    /**
     * @param  self::*  $reason
     */
    public function __construct(
        public readonly string $reason,
        public readonly int $orderId,
        public readonly string $detail,
    ) {
        parent::__construct("{$reason}: {$detail}");
    }
}
