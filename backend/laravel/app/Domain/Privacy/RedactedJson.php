<?php

declare(strict_types=1);

namespace App\Domain\Privacy;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * The `array` cast, minus order tokens.
 *
 * Provider payloads are kept as evidence, and Tap echoes the charge's `redirect.url` —
 * the buyer's `/orders/{token}` link — in every charge object it sends back. Stored
 * verbatim, every paid order's credential would sit in plaintext in the payment tables.
 * Only the credential segment is replaced; every other byte of the payload is kept.
 *
 * @implements CastsAttributes<array<mixed>|null, mixed>
 */
final class RedactedJson implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value === null ? null : json_decode((string) $value, true);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode(CredentialPaths::redactDeep($value), JSON_THROW_ON_ERROR);
    }
}
