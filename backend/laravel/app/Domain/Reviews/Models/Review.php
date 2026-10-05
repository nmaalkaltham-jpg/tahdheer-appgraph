<?php

namespace App\Domain\Reviews\Models;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A single customer review. Identity always comes from the account
 * (customer_id) — there is no free-text author column anywhere, by design.
 * `order_id` is what earns the verified-purchase badge; an entitlement-backed
 * review without a resolvable order line stays unbadged rather than claiming
 * a purchase it cannot point at.
 */
class Review extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'helpful_count' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reply(): HasOne
    {
        return $this->hasOne(ReviewReply::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ReviewVote::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ReviewMedia::class);
    }

    public function categoryScores(): HasMany
    {
        return $this->hasMany(ReviewCategoryScore::class);
    }
}
