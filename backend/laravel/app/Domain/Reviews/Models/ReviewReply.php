<?php

namespace App\Domain\Reviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Store reply — always signed with the brand name, never a staff person. */
class ReviewReply extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
