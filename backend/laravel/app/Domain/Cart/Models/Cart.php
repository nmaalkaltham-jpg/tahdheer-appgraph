<?php

namespace App\Domain\Cart\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $table = 'carts';

    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class, 'cart_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
