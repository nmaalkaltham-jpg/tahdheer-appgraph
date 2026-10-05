<?php

namespace App\Domain\Orders;

use App\Models\Order;

/**
 * Guest order access token: raw = base64url(HMAC-SHA256(app.key, number|email[|vN])),
 * returned ONCE at checkout; only its sha256 hash is stored (order_key_hash).
 *
 * Version 0 is the original formula. Rotating bumps `orders.order_key_version`, which is
 * the only thing that revokes one leaked token without touching every other order.
 */
final class OrderToken
{
    public static function make(string $number, string $email, int $version = 0): string
    {
        $message = $number.'|'.$email.($version > 0 ? '|v'.$version : '');
        $mac = hash_hmac('sha256', $message, self::appKey(), true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /** The token currently valid for this order — what every link to it must carry. */
    public static function forOrder(Order $order): string
    {
        return self::make((string) $order->number, (string) $order->email, (int) $order->order_key_version);
    }

    /** Revokes the order's current token and returns its replacement. */
    public static function rotate(Order $order): string
    {
        $order->order_key_version = (int) $order->order_key_version + 1;
        $token = self::forOrder($order);
        $order->forceFill(['order_key_hash' => self::hash($token)])->save();

        return $token;
    }

    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    private static function appKey(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:')
            ? (string) base64_decode(substr($key, 7), true)
            : $key;
    }
}
