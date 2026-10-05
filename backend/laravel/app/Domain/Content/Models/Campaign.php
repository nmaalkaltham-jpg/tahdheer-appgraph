<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A real merchandising campaign with a real window.
 *
 * The homepage countdown («ينتهي بعد ٣ أيام») is only ever allowed to render
 * because a row here says when the campaign actually ends. `ends_at` is NOT
 * nullable and the `live` scope compares it to now(), so an expired campaign
 * disappears from the storefront by itself — there is no flag anyone has to
 * remember to switch off, and no way to display a deadline nobody committed to.
 */
class Campaign extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** Started (or open-ended) and not yet finished. */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->where('ends_at', '>', now())
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()));
    }
}
