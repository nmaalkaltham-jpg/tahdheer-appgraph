<?php

namespace App\Domain\Reviews\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** «هل كان هذا مفيدًا؟» — one vote per customer per review (DB-enforced). */
class ReviewVote extends Model
{
    protected $guarded = [];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
