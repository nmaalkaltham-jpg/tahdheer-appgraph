<?php

namespace App\Domain\Assets\Exceptions;

use RuntimeException;

/**
 * نودي المزوّد ولم يُعرف جوابه.
 *
 * ليست فشلًا: الطلب قد يكون وصل ونُفّذ وضاع الجواب وحده. تمييزُها عن
 * `SourceUnavailable` — التي تعني «لم نصل إليه أصلًا» — هو ما يمنع إعادةً
 * تُنفّذ الأثر مرّتين.
 */
class SourceOutcomeUnknown extends RuntimeException
{
    public function __construct(public readonly string $provider, string $reason, public readonly ?string $reference = null)
    {
        parent::__construct("نتيجة «{$provider}» مجهولة: {$reason}");
    }
}
