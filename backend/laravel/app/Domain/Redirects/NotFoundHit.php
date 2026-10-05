<?php

namespace App\Domain\Redirects;

use Illuminate\Database\Eloquent\Model;

/**
 * تجميعة 404 — صف واحد لكل مسار، يُحدَّث عدّاده ولا يُكرَّر.
 *
 * Aggregate, never a log: one row per URL keyed by `url_hash`, with a hit
 * counter and first/last seen timestamps (MIP §7.7).
 */
class NotFoundHit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** The stable aggregate key for a path. */
    public static function hashFor(string $path): string
    {
        return hash('sha256', '/'.ltrim(trim($path), '/'));
    }
}
