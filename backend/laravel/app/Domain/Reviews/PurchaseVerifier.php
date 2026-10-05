<?php

namespace App\Domain\Reviews;

use App\Domain\Orders\OrderStatus;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * The gate in front of every review: only real purchasers may write one.
 *
 * Two independent proofs are accepted, in this order:
 *   1. an ACTIVE entitlement for the product (the access ledger — the
 *      authoritative record, and the one that survives order edits);
 *   2. a PAID-state order containing the product.
 *
 * A revoked/expired entitlement is NOT proof: access was taken back, and with
 * it the standing to publish. The returned proof carries the order id when one
 * is resolvable — that, and only that, earns the verified-purchase badge.
 */
class PurchaseVerifier
{
    /** Money is captured in these states; PENDING/FAILED/CANCELLED are not purchases. */
    public const PAID_STATUSES = OrderStatus::COLLECTED;

    /**
     * @return array{orderId: int|null, variant: string|null}|null null ⇒ no purchase
     */
    public function verify(int $customerId, int $productId): ?array
    {
        $entitlement = Entitlement::query()
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->first();

        if ($entitlement !== null) {
            return [
                'orderId' => $this->paidOrderId((int) $entitlement->order_id),
                'variant' => $entitlement->version_label,
            ];
        }

        $item = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.customer_id', $customerId)
            ->whereIn('orders.status', self::PAID_STATUSES)
            ->where('order_items.product_id', $productId)
            ->orderBy('order_items.id')
            ->select('order_items.order_id', 'order_items.version_label')
            ->first();

        if ($item === null) {
            return null;
        }

        return ['orderId' => (int) $item->order_id, 'variant' => $item->version_label];
    }

    /** An entitlement's order backs the badge only while that order is still paid. */
    private function paidOrderId(int $orderId): ?int
    {
        $paid = Order::query()
            ->whereKey($orderId)
            ->whereIn('status', self::PAID_STATUSES)
            ->exists();

        return $paid ? $orderId : null;
    }
}
