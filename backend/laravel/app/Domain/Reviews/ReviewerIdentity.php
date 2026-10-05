<?php

namespace App\Domain\Reviews;

use App\Domain\Orders\OrderToken;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * INTERIM AUTHENTICATION for the Reviews domain.
 *
 * Customer sessions do not exist yet. Until they land, a reviewer identifies
 * themselves with the `X-Order-Token` header — the guest order token issued
 * ONCE at checkout and stored only as a sha256 hash (`orders.order_key_hash`),
 * verified through App\Domain\Orders\OrderToken. Holding it proves the bearer
 * holds a specific real order, which is precisely the claim a review needs.
 *
 * The token identifies the ACCOUNT, not the purchase: which products that
 * account may review is decided independently by PurchaseVerifier, so a token
 * from order A still cannot review a product the account never bought.
 *
 * MIGRATION PATH: when customer sessions land, replace the single call site
 * below with the authenticated customer. Nothing else in the domain changes —
 * every service already takes a Customer.
 */
final class ReviewerIdentity
{
    public const HEADER = 'X-Order-Token';

    /** Null for a missing, malformed, tampered or orphaned token. */
    public static function resolve(Request $request): ?Customer
    {
        $raw = $request->header(self::HEADER);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        // Constant-length hash lookup: a wrong token simply matches no row.
        $customerId = Order::query()
            ->where('order_key_hash', OrderToken::hash($raw))
            ->value('customer_id');

        return $customerId === null ? null : Customer::query()->find($customerId);
    }
}
