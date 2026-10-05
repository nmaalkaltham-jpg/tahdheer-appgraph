<?php

declare(strict_types=1);

namespace App\Domain\Writing;

use RuntimeException;

/** المزوّد غير مهيَّأ أو تعذّر الوصول إليه. لا بديل مُلفَّق. */
final class WritingAssistantUnavailable extends RuntimeException
{
    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    public const PROVIDER_FAILED = 'PROVIDER_FAILED';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notConfigured(string $provider): self
    {
        return new self(
            self::NOT_CONFIGURED,
            "المساعد الكتابي غير مهيَّأ (المزوّد: {$provider}).",
        );
    }

    /** فشل المزوّد. التفصيل للسجلّ لا للواجهة — قد يحمل معرّفات طلب. */
    public static function providerFailed(string $provider, string $detail): self
    {
        return new self(
            self::PROVIDER_FAILED,
            "تعذّر الحصول على اقتراحات من {$provider}: {$detail}",
        );
    }
}
