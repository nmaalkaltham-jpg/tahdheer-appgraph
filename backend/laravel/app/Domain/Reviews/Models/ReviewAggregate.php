<?php

namespace App\Domain\Reviews\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Materialized per-product rating summary — ONE row per product, rewritten
 * only by AggregateRebuilder. Nothing else may write these numbers.
 */
class ReviewAggregate extends Model
{
    /**
     * Below this, `aggregateRating` structured data must NOT be emitted
     * (MIP §4.7 / §9): three real reviews is the floor for a public average.
     */
    public const SCHEMA_MIN_COUNT = 3;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'distribution' => 'array',
            'count' => 'integer',
            'recommend_pct' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
