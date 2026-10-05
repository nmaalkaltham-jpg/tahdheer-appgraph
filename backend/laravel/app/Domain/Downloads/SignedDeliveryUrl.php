<?php

namespace App\Domain\Downloads;

use Illuminate\Support\Carbon;

/**
 * رابط التسليم الموقّع — a Laravel-signed URL for the redeem endpoint.
 *
 * The redeem route is declared by the commerce domain without a route name, so
 * `URL::temporarySignedRoute()` (which resolves by name only) cannot address
 * it. This builds the identical payload the framework signs — path + `expires`
 * + HMAC-SHA256 over that exact string with the app key — and the endpoint
 * verifies it with the framework's own `$request->hasValidSignature()`. The
 * SignedDeliveryUrlTest asserts the two stay compatible, so any framework
 * change fails loudly instead of silently weakening the signature.
 *
 * The URL carries the grant token and nothing else: no object key, no bucket
 * path, no customer identifier.
 */
final class SignedDeliveryUrl
{
    public const PATH = '/api/v1/downloads/redeem/';

    /** Absolute, signed, and valid until $expiresAt. */
    public static function until(string $rawGrantToken, Carbon $expiresAt): string
    {
        $unsigned = url(self::PATH.$rawGrantToken).'?expires='.$expiresAt->getTimestamp();

        return $unsigned.'&signature='.hash_hmac('sha256', $unsigned, self::appKey());
    }

    public static function forSeconds(string $rawGrantToken, int $ttlSeconds): string
    {
        return self::until($rawGrantToken, Carbon::now()->addSeconds(max(1, $ttlSeconds)));
    }

    /**
     * The framework signs with the first configured key and verifies against
     * every key (rotation support); matching the first keeps both in step.
     */
    private static function appKey(): string
    {
        $key = config('app.key');

        return is_array($key) ? (string) $key[0] : (string) $key;
    }
}
