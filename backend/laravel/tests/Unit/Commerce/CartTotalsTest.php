<?php

namespace Tests\Unit\Commerce;

use App\Domain\Cart\CartTotals;
use App\Domain\Cart\Models\Coupon;
use PHPUnit\Framework\TestCase;

class CartTotalsTest extends TestCase
{
    /**
     * Golden chain: 40000 + (30000 on sale at 25000) with a 15% coupon.
     * gross → productDiscount → coupon → total → extracted VAT → saving.
     */
    public function test_golden_totals_chain(): void
    {
        $totals = CartTotals::compute([
            ['regular' => 40000, 'effective' => 40000],
            ['regular' => 30000, 'effective' => 25000],
        ], new Coupon(['type' => 'percent', 'value' => 15]));

        $this->assertSame(70000, $totals->grossMinor);
        $this->assertSame(5000, $totals->productDiscountMinor);
        $this->assertSame(9750, $totals->couponDiscountMinor);   // 15% of 65000
        $this->assertSame(55250, $totals->totalMinor);
        $this->assertSame(55250 - (int) round(55250 / 1.15), $totals->vatMinor);
        $this->assertSame(7207, $totals->vatMinor);
        $this->assertSame(14750, $totals->savingMinor);
    }

    public function test_fixed_coupon_clamped_to_subtotal_never_negative(): void
    {
        $totals = CartTotals::compute(
            [['regular' => 30000, 'effective' => 25000]],
            new Coupon(['type' => 'fixed', 'value' => 99999]),
        );

        $this->assertSame(25000, $totals->couponDiscountMinor);
        $this->assertSame(0, $totals->totalMinor);
        $this->assertSame(0, $totals->vatMinor);
    }

    public function test_empty_cart_is_all_zeros(): void
    {
        $totals = CartTotals::compute([]);

        $this->assertSame(
            ['grossMinor' => 0, 'productDiscountMinor' => 0, 'couponDiscountMinor' => 0,
                'totalMinor' => 0, 'vatMinor' => 0, 'savingMinor' => 0],
            $totals->toArray(),
        );
    }

    public function test_no_coupon_still_extracts_vat_from_total(): void
    {
        $totals = CartTotals::compute([['regular' => 40000, 'effective' => 40000]]);

        $this->assertSame(40000, $totals->totalMinor);
        $this->assertSame(5217, $totals->vatMinor);
        $this->assertSame(0, $totals->savingMinor);
    }
}
