<?php

namespace App\Domain\Reviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Customer media — moderated before publish, exactly like the review body. */
class ReviewMedia extends Model
{
    protected $table = 'review_media';

    protected $guarded = [];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
