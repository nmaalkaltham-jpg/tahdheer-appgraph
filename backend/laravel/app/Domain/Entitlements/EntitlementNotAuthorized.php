<?php

declare(strict_types=1);

namespace App\Domain\Entitlements;

use RuntimeException;

/**
 * استحقاقٌ بلا إذنٍ تجاريّ — يُرفض قبل أن يخرج المنتج (§٧١).
 *
 * ─── لماذا هنا لا عند المستدعي ───
 *
 * السياسة عند المستدعي يتجاوزها كلّ مستدعٍ آخر: مهمّة، أو عمليّة جماعيّة،
 * أو فعلٌ إداريّ، أو نقطة نهاية تُكتب غدًا. وهذا بعينه ما سمح بتجاوز سقف
 * الاسترداد قبل نقله إلى النطاق.
 *
 * ─── ولماذا أخطر من الاسترداد ───
 *
 * المال المستردّ زيادةً يُطالَب به. والملفّ الرقميّ حين يُنزَّل لا يعود:
 * لا استردادَ يُصلح تنزيلًا وقع.
 */
final class EntitlementNotAuthorized extends RuntimeException
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderStatus,
    ) {
        parent::__construct(
            'لا إذن تجاريّ للطلب '.$orderId.' في الحالة «'.$orderStatus.'» — لا يُمنح استحقاق.'
        );
    }
}
