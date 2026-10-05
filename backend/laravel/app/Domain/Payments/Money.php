<?php

namespace App\Domain\Payments;

/**
 * تحويلٌ دقيق بين المبلغ العشريّ للمزوّد والوحدات الصغرى — بلا فاصلةٍ عائمة.
 *
 * `(int) round((float) $amount * 100)` كان يمرّر ٥٥٢٫٥٥٥ ريالًا إلى ٥٥٢٥٦
 * هللة بصمت، ويقبل الصفر والسالب والنصوص الغريبة. هنا: نصٌّ عشريٌّ بمنزلتين
 * على الأكثر، موجب، ومعقول الطول — وإلّا `null` مع سببٍ صريح.
 */
final class Money
{
    public const DECIMALS = 2;

    /** أكبر مبلغٍ يُقبل: عشرة مليارات ريال — فوقه تجاوزٌ لا دفعة. */
    private const MAX_WHOLE_DIGITS = 11;

    /**
     * @return array{minor: int|null, problem: string|null}
     */
    public static function parseProviderAmount(mixed $amount): array
    {
        if ($amount === null || $amount === '') {
            return ['minor' => null, 'problem' => 'AMOUNT_MISSING'];
        }

        if (is_int($amount)) {
            $text = (string) $amount;
        } elseif (is_float($amount)) {
            if (! is_finite($amount)) {
                return ['minor' => null, 'problem' => 'AMOUNT_MALFORMED'];
            }
            // تمثيلٌ عشريٌّ ثابت ثمّ حذف الأصفار الزائدة: 552.5 → "552.5".
            $text = rtrim(rtrim(sprintf('%.8F', $amount), '0'), '.');
        } elseif (is_string($amount)) {
            $text = trim($amount);
        } else {
            return ['minor' => null, 'problem' => 'AMOUNT_MALFORMED'];
        }

        if (preg_match('/^(\d{1,'.self::MAX_WHOLE_DIGITS.'})(?:\.(\d+))?$/', $text, $m) !== 1) {
            return ['minor' => null, 'problem' => 'AMOUNT_MALFORMED'];
        }

        $fraction = $m[2] ?? '';
        if (strlen(rtrim($fraction, '0')) > self::DECIMALS) {
            // هللةٌ مكسورة: المزوّد لا يُحصّل أجزاء الهللة.
            return ['minor' => null, 'problem' => 'AMOUNT_PRECISION'];
        }

        $minor = ((int) $m[1]) * 100 + (int) str_pad(substr($fraction, 0, self::DECIMALS), self::DECIMALS, '0');

        if ($minor <= 0) {
            return ['minor' => null, 'problem' => 'AMOUNT_NOT_POSITIVE'];
        }

        return ['minor' => $minor, 'problem' => null];
    }

    /** 55250 → "552.50" بلا قسمةٍ عائمة. */
    public static function formatMinor(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
