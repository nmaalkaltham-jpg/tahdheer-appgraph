<?php

namespace App\Domain\UrlPlatform;

use RuntimeException;

/**
 * خطأٌ مسمّى من منصّة العناوين — رمزٌ ثابت يقرؤه المستدعي ويُحيله إلى حقلٍ
 * أو رمز حالة، لا نصٌّ يُقارَن.
 */
final class UrlPlatformError extends RuntimeException
{
    /** @param array<string, mixed> $context */
    private function __construct(public readonly string $errorCode, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public static function invalidPath(string $reason): self
    {
        return new self('INVALID_PATH', "مسارٌ غير مقبول: {$reason}.", ['reason' => $reason]);
    }

    public static function invalidSlug(string $reason): self
    {
        return new self('INVALID_SLUG', "رابطٌ غير مقبول: {$reason}.", ['reason' => $reason]);
    }

    public static function reserved(string $segment): self
    {
        return new self('RESERVED', "المقطع «{$segment}» محجوزٌ للنظام.", ['segment' => $segment]);
    }

    public static function collision(string $path): self
    {
        return new self('COLLISION', "المسار «{$path}» مملوكٌ لكيانٍ آخر.", ['path' => $path]);
    }

    public static function protectedHistory(string $path, string $owner): self
    {
        return new self('PROTECTED_HISTORY', "المسار «{$path}» رابطٌ تاريخيّ محميّ لـ{$owner} ولا يُعاد استعماله.", ['path' => $path, 'owner' => $owner]);
    }

    public static function locked(string $path): self
    {
        return new self('LOCKED', "الرابط «{$path}» مقفلٌ — يحتاج صلاحيّة التجاوز.", ['path' => $path]);
    }

    public static function notFound(string $what): self
    {
        return new self('NOT_FOUND', "{$what} غير موجود.", []);
    }

    public static function invalidTransition(UrlStatus $from, UrlStatus $to): self
    {
        return new self('INVALID_TRANSITION', "لا انتقال من «{$from->value}» إلى «{$to->value}».", ['from' => $from->value, 'to' => $to->value]);
    }

    /** @param array<string, mixed> $current */
    public static function staleVersion(int $expected, int $actual, array $current): self
    {
        return new self('STALE_VERSION', "النسخة المتوقّعة {$expected} والحاليّة {$actual}.", ['expected' => $expected, 'actual' => $actual, 'current' => $current]);
    }

    public static function unsafeTarget(string $reason): self
    {
        return new self('UNSAFE_TARGET', "وجهةٌ غير آمنة: {$reason}.", ['reason' => $reason]);
    }

    public static function sameSlug(string $slug): self
    {
        return new self('SAME_SLUG', "الرابط «{$slug}» هو الحاليّ.", ['slug' => $slug]);
    }
}
