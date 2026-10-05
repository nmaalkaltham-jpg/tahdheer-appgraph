<?php

namespace App\Domain\Payments;

use RuntimeException;

/**
 * لم يصل ردٌّ حاسم من المزوّد: مهلةٌ أو انقطاعٌ أو خطأُ خادم.
 *
 * المال ربّما خرج وربّما لا. لا يُعاد الطلب أعمى ولا يُعلَن الفشل: يبقى
 * الحجز، ويُعاد السؤال بمفتاح الخمول نفسه حتّى يجيب المزوّد.
 */
final class RefundOutcomeUnknown extends RuntimeException {}
