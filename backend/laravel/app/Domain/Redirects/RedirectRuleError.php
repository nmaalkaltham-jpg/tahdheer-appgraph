<?php

namespace App\Domain\Redirects;

use RuntimeException;

/**
 * خطأ مُصنَّف يرفض حفظ قاعدة تحويل معطوبة.
 *
 * A typed refusal, not a warning: the rule is NOT saved. Every case carries a
 * machine `code` for the admin UI and an Arabic `reason` for the human.
 */
final class RedirectRuleError extends RuntimeException
{
    public const SELF_REDIRECT = 'SELF_REDIRECT';

    public const LOOP = 'LOOP';

    public const TARGET_NOT_FOUND = 'TARGET_NOT_FOUND';

    public const INVALID_SOURCE = 'INVALID_SOURCE';

    public const INVALID_TARGET = 'INVALID_TARGET';

    public const INVALID_STATUS = 'INVALID_STATUS';

    public const DUPLICATE_SOURCE = 'DUPLICATE_SOURCE';

    /** النسخة المحفوظة تقدّمت: تحريرٌ آخر سبق هذا ولم يُرَ. */
    public const STALE_VERSION = 'STALE_VERSION';

    /**
     * @param  array<string, mixed>  $context  ما يحتاجه الحلّ: النسخ والحالة الحاليّة.
     */
    private function __construct(
        public readonly string $errorCode,
        string $reason,
        public readonly array $context = [],
    ) {
        parent::__construct($reason);
    }

    public static function selfRedirect(string $path): self
    {
        return new self(self::SELF_REDIRECT, 'المصدر والهدف متطابقان: '.$path);
    }

    /** @param list<string> $chain */
    public static function loop(array $chain): self
    {
        return new self(self::LOOP, 'حلقة تحويل مغلقة: '.implode(' ← ', $chain));
    }

    public static function targetNotFound(string $target): self
    {
        return new self(
            self::TARGET_NOT_FOUND,
            'الهدف مسجَّل كصفحة غير موجودة (404): '.$target,
        );
    }

    public static function invalidSource(string $reason): self
    {
        return new self(self::INVALID_SOURCE, $reason);
    }

    public static function invalidTarget(string $reason): self
    {
        return new self(self::INVALID_TARGET, $reason);
    }

    public static function invalidStatus(int $status): self
    {
        return new self(self::INVALID_STATUS, 'رمز التحويل غير مدعوم: '.$status.' — يُسمح بـ301 أو 302 فقط');
    }

    public static function duplicateSource(string $source): self
    {
        return new self(self::DUPLICATE_SOURCE, 'يوجد قاعدة أخرى بالمصدر نفسه: '.$source);
    }

    /**
     * تعارضُ نسخة — وليس خطأ إدخال.
     *
     * الرسالة تقول ماذا حدث لا «حاول ثانيةً»: إعادةُ المحاولة العمياء تُعيد
     * الكتابة فوق تحرير الآخر، وهو بالضبط ما تمنعه النسخة.
     *
     * @param  array<string, mixed>  $current  الصفّ كما هو الآن — ليُعرض للمقارنة.
     */
    public static function staleVersion(int $expected, int $actual, array $current): self
    {
        return new self(
            self::STALE_VERSION,
            "تغيّرت القاعدة منذ فتحها: النسخة المحفوظة {$actual} والمُرسلة {$expected}. راجع الحاليّ ثمّ أعد الإرسال.",
            ['expectedVersion' => $expected, 'actualVersion' => $actual, 'current' => $current],
        );
    }

    /** @return array{code: string, reason: string} */
    public function toArray(): array
    {
        return ['code' => $this->errorCode, 'reason' => $this->getMessage()];
    }
}
