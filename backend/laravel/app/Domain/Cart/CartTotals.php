<?php

namespace App\Domain\Cart;

use App\Domain\Cart\Models\Coupon;
use App\Domain\Pricing\PricingService;

/**
 * THE single totals derivation chain (MIP 5.3):
 * gross → product discounts → coupon → total → extracted VAT → saving.
 * Computed server-side from line prices only — the summary can never
 * contradict the rows because both come from the same lines.
 */
final readonly class CartTotals
{
    public function __construct(
        public int $grossMinor,
        public int $productDiscountMinor,
        public int $couponDiscountMinor,
        public int $totalMinor,
        public int $vatMinor,
        public int $savingMinor,
    ) {}

    /**
     * @param  array<int, array{regular: int, effective: int}>  $lines
     */
    public static function compute(array $lines, ?Coupon $coupon = null): self
    {
        $gross = 0;
        $productDiscount = 0;

        foreach ($lines as $line) {
            $gross += $line['regular'];
            $productDiscount += max(0, $line['regular'] - $line['effective']);
        }

        $subtotal = $gross - $productDiscount;

        $couponDiscount = 0;
        if ($coupon !== null && $subtotal > 0) {
            $couponDiscount = $coupon->type === 'percent'
                ? (int) round($subtotal * $coupon->value / 100)
                : min((int) $coupon->value, $subtotal);
        }

        $total = $subtotal - $couponDiscount;
        $vat = PricingService::vatExtracted($total);

        return new self(
            grossMinor: $gross,
            productDiscountMinor: $productDiscount,
            couponDiscountMinor: $couponDiscount,
            totalMinor: $total,
            vatMinor: $vat,
            savingMinor: $productDiscount + $couponDiscount,
        );
    }

    /** @return array{grossMinor: int, productDiscountMinor: int, couponDiscountMinor: int, totalMinor: int, vatMinor: int, savingMinor: int} */
    public function toArray(): array
    {
        return [
            'grossMinor' => $this->grossMinor,
            'productDiscountMinor' => $this->productDiscountMinor,
            'couponDiscountMinor' => $this->couponDiscountMinor,
            'totalMinor' => $this->totalMinor,
            'vatMinor' => $this->vatMinor,
            'savingMinor' => $this->savingMinor,
        ];
    }
}
