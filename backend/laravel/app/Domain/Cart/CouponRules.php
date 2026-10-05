<?php

namespace App\Domain\Cart;

use App\Domain\Cart\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;

/**
 * Coupon validity rules, applied identically at cart apply time and
 * RE-applied at order creation («لا سعر يُقبل بلا إعادة حساب»).
 */
final class CouponRules
{
    public const INVALID_MESSAGE = 'رمز الخصم غير صالح أو منتهي';

    /** Statuses that do NOT count as a completed first order. */
    private const VOID_STATUSES = ['CANCELLED', 'FAILED'];

    /** Basic validity: exists caller-side; here expiry + usage limit. */
    public static function usable(Coupon $coupon): bool
    {
        if ($coupon->expires_at !== null && $coupon->expires_at->isPast()) {
            return false;
        }
        if ($coupon->usage_limit !== null && $coupon->usage_count >= $coupon->usage_limit) {
            return false;
        }

        return true;
    }

    /**
     * Full validity when the buyer's email is known (checkout):
     * enforces first_order_per_account.
     */
    public static function usableFor(Coupon $coupon, string $email): bool
    {
        if (! self::usable($coupon)) {
            return false;
        }

        if ($coupon->first_order_per_account) {
            $customer = Customer::query()->where('email', $email)->first();
            if ($customer !== null && Order::query()
                ->where('customer_id', $customer->id)
                ->whereNotIn('status', self::VOID_STATUSES)
                ->exists()) {
                return false;
            }
        }

        return true;
    }
}
