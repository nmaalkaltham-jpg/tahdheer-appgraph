<?php

namespace App\Domain\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * سجلّ الأصول — asset ledger (MIP §4.3).
 *
 * provider_ref is the object key: an internal secret. It is never serialised
 * into an API response, an audit row, or a log line.
 */
class Asset extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    public function productFiles(): HasMany
    {
        return $this->hasMany(ProductFile::class);
    }

    public function isPaidPrivate(): bool
    {
        return $this->visibility === 'paid_private';
    }

    /** The name the customer sees — the real stored name, never invented. */
    public function downloadName(): string
    {
        return (string) ($this->customer_label ?: $this->original_name);
    }
}
