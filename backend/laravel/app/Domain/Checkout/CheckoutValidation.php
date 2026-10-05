<?php

namespace App\Domain\Checkout;

/**
 * Checkout field rules (locked design):
 * - Saudi mobile entered without the fixed +966 prefix: 5XXXXXXXX
 * - ZATCA VAT number: 15 digits, starts and ends with 3
 */
final class CheckoutValidation
{
    public const SAUDI_PHONE_PATTERN = '/^5\d{8}$/';

    public const ZATCA_VAT_PATTERN = '/^3\d{13}3$/';

    public static function isSaudiPhone(string $value): bool
    {
        return preg_match(self::SAUDI_PHONE_PATTERN, $value) === 1;
    }

    public static function isZatcaVat(string $value): bool
    {
        return preg_match(self::ZATCA_VAT_PATTERN, $value) === 1;
    }
}
