<?php

namespace App\Domain\Pricing;

use App\Models\ProductPrice;
use DateTimeInterface;

/**
 * THE only discount computer. All money is integer minor units (halalas).
 * Prices are stored VAT-inclusive; VAT is displayed by extraction
 * (vat = total − total/1.15), never added.
 */
final class PricingService
{
    public const VAT_RATE = 0.15;

    /** Percentage saved, e.g. 40000 → 30000 = 25. */
    public static function discountPct(int $regularMinor, int $saleMinor): int
    {
        if ($regularMinor <= 0 || $saleMinor < 0 || $saleMinor >= $regularMinor) {
            return 0;
        }

        return (int) round((1 - $saleMinor / $regularMinor) * 100);
    }

    /** Absolute saving in minor units. */
    public static function saving(int $regularMinor, int $saleMinor): int
    {
        if ($saleMinor >= $regularMinor) {
            return 0;
        }

        return $regularMinor - $saleMinor;
    }

    /**
     * VAT contained in a VAT-inclusive total: total − round(total / (1 + rate)).
     * Integer math on minor units; e.g. 45500 (455.00 SAR) → 5935 (59.35 SAR).
     */
    public static function vatExtracted(int $totalMinor, float $rate = self::VAT_RATE): int
    {
        return $totalMinor - (int) round($totalMinor / (1 + $rate));
    }

    /**
     * The sale price currently in effect for a price row, honoring
     * sale_from / sale_to (open bounds when null), or null when no sale applies.
     */
    public static function activeSaleMinor(ProductPrice $price, ?DateTimeInterface $now = null): ?int
    {
        return self::activeSaleWithin($price->sale_minor, $price->sale_from, $price->sale_to, $now);
    }

    /**
     * القاعدة نفسها بلا صفٍّ بعينه — يستعملها `product_offers` الذي يحمل
     * الأعمدة الأربعة ذاتها. وُلّدت بالاستخراج لا بالنسخ عمدًا: نافذتا تخفيضٍ
     * متوازيتان تفترقان يومًا، فيعرض المُبدِّل سعرًا والسلّة سعرًا آخر.
     */
    public static function activeSaleWithin(
        int|string|null $saleMinor,
        ?DateTimeInterface $from,
        ?DateTimeInterface $to,
        ?DateTimeInterface $now = null,
    ): ?int {
        if ($saleMinor === null) {
            return null;
        }

        $now ??= now();

        if ($from !== null && $now < $from) {
            return null;
        }
        if ($to !== null && $now > $to) {
            return null;
        }

        return (int) $saleMinor;
    }
}
