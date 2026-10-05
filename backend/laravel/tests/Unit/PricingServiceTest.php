<?php

namespace Tests\Unit;

use App\Domain\Checkout\CheckoutValidation;
use App\Domain\Pricing\PricingService;
use App\Models\ProductPrice;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    public function test_discount_pct_400_to_300_is_25(): void
    {
        $this->assertSame(25, PricingService::discountPct(40000, 30000));
    }

    public function test_discount_pct_rounds_and_clamps_edges(): void
    {
        $this->assertSame(33, PricingService::discountPct(30000, 20000)); // 33.33 → 33
        $this->assertSame(0, PricingService::discountPct(0, 100));
        $this->assertSame(0, PricingService::discountPct(10000, 10000));
        $this->assertSame(0, PricingService::discountPct(10000, 12000));
    }

    public function test_saving_is_the_minor_unit_difference(): void
    {
        $this->assertSame(10000, PricingService::saving(40000, 30000));
        $this->assertSame(0, PricingService::saving(30000, 30000));
    }

    public function test_vat_extracted_from_455_sar_total_is_59_35(): void
    {
        // Worked example from evidence: vat = total − round(total / 1.15).
        // 45500 − round(39565.217…) = 45500 − 39565 = 5935 minor = 59.35 SAR.
        $this->assertSame(5935, PricingService::vatExtracted(45500));
        // Consistency: net + vat always reassembles the inclusive total.
    }

    public function test_vat_is_extracted_never_added(): void
    {
        $total = 11500; // 115.00 SAR inclusive
        $this->assertSame(1500, PricingService::vatExtracted($total));
        $this->assertLessThan($total, PricingService::vatExtracted($total));
    }

    public function test_active_sale_honors_window(): void
    {
        $price = new ProductPrice([
            'regular_minor' => 40000,
            'sale_minor' => 30000,
            'sale_from' => '2026-08-01 00:00:00',
            'sale_to' => '2026-08-31 23:59:59',
        ]);

        $inside = new \DateTimeImmutable('2026-08-15 12:00:00');
        $before = new \DateTimeImmutable('2026-07-15 12:00:00');
        $after = new \DateTimeImmutable('2026-09-15 12:00:00');

        $this->assertSame(30000, PricingService::activeSaleMinor($price, $inside));
        $this->assertNull(PricingService::activeSaleMinor($price, $before));
        $this->assertNull(PricingService::activeSaleMinor($price, $after));
    }

    public function test_active_sale_with_open_bounds_and_no_sale(): void
    {
        $openEnded = new ProductPrice(['regular_minor' => 40000, 'sale_minor' => 35000]);
        $this->assertSame(35000, PricingService::activeSaleMinor($openEnded, new \DateTimeImmutable));

        $noSale = new ProductPrice(['regular_minor' => 40000]);
        $this->assertNull(PricingService::activeSaleMinor($noSale, new \DateTimeImmutable));
    }

    public function test_checkout_validation_rules(): void
    {
        $this->assertTrue(CheckoutValidation::isSaudiPhone('512345678'));
        $this->assertFalse(CheckoutValidation::isSaudiPhone('412345678'));
        $this->assertFalse(CheckoutValidation::isSaudiPhone('05123456789'));

        $this->assertTrue(CheckoutValidation::isZatcaVat('300000000000003'));
        $this->assertFalse(CheckoutValidation::isZatcaVat('300000000000004'));
        $this->assertFalse(CheckoutValidation::isZatcaVat('30000000000003'));
    }
}
