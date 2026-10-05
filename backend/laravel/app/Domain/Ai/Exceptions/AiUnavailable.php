<?php

declare(strict_types=1);

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

/**
 * الذكاء غير متاح — بسببٍ مسمّى.
 *
 * ─── لماذا سببٌ مصنَّف لا رسالةٌ حرّة ───
 *
 * «مطفأ» و«ساقط» و«مشغول» ثلاثة أشياء مختلفة تمامًا: الأوّل قرارٌ إداريّ،
 * والثاني عطلٌ يستحقّ إنذارًا، والثالث حالةٌ طبيعيّة تحت الحمل. وخلطها في
 * رسالةٍ واحدة يجعل لوحة التشغيل تُنذر على إطفاءٍ مقصود، أو تصمت على انهيار.
 *
 * والرمز يُعرض للواجهة فتعرف أتعرض «أعد المحاولة» أم «فعّل الميزة».
 */
final class AiUnavailable extends RuntimeException
{
    public const DISABLED = 'AI_DISABLED';

    public const FEATURE_OFF = 'AI_FEATURE_DISABLED';

    public const NOT_CONFIGURED = 'AI_NOT_CONFIGURED';

    public const UNHEALTHY = 'AI_UNHEALTHY';

    public const BUSY = 'AI_BUSY';

    public const RATE_LIMITED = 'AI_RATE_LIMITED';

    public const CIRCUIT_OPEN = 'AI_CIRCUIT_OPEN';

    public const TIMEOUT = 'AI_TIMEOUT';

    public const ENGINE_FAILED = 'AI_ENGINE_FAILED';

    public const INPUT_TOO_LARGE = 'AI_INPUT_TOO_LARGE';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self(self::DISABLED, 'محرّك الذكاء مطفأ — AI_ENABLED غير مفعّلة.');
    }

    public static function featureOff(string $feature): self
    {
        return new self(self::FEATURE_OFF, "قدرة «{$feature}» مطفأة. المتجر يعمل كاملًا بدونها.");
    }

    public static function notConfigured(string $why): self
    {
        return new self(self::NOT_CONFIGURED, "محرّك الذكاء غير مهيّأ: {$why}");
    }

    public static function unhealthy(): self
    {
        return new self(self::UNHEALTHY, 'خدمة النموذج لا تستجيب لفحص الصحّة.');
    }

    public static function busy(int $max): self
    {
        return new self(self::BUSY, "الطاقة مستهلكة: {$max} استدلالًا متزامنًا هو الحدّ. حاول بعد قليل.");
    }

    public static function rateLimited(int $perMinute): self
    {
        return new self(self::RATE_LIMITED, "تجاوزتَ {$perMinute} طلبًا في الدقيقة.");
    }

    public static function circuitOpen(int $seconds): self
    {
        return new self(self::CIRCUIT_OPEN, "الدائرة مفتوحة بعد فشلٍ متكرّر — تُغلق بعد {$seconds} ثانية.");
    }

    public static function timeout(float $seconds): self
    {
        return new self(self::TIMEOUT, "تجاوز الاستدلال {$seconds} ثانية.");
    }

    public static function engineFailed(string $detail): self
    {
        return new self(self::ENGINE_FAILED, "فشل الاستدلال: {$detail}");
    }

    public static function inputTooLarge(int $chars, int $max): self
    {
        return new self(self::INPUT_TOO_LARGE, "المدخل {$chars} محرفًا والحدّ {$max}.");
    }

    /** أهي حالةٌ تستحقّ إنذارًا أم وضعٌ طبيعيّ؟ */
    public function isFault(): bool
    {
        return in_array($this->reason, [self::UNHEALTHY, self::ENGINE_FAILED, self::TIMEOUT], true);
    }
}
