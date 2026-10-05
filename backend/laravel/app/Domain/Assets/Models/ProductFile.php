<?php

namespace App\Domain\Assets\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFile extends Model
{
    /** Roles whose file is the paid deliverable, in delivery preference order. */
    public const DELIVERABLE_ROLES = ['main', 'companion', 'teacher_guide', 'answer_key'];

    /** A replaced file keeps its row (archive-first) but stops being delivered. */
    public const ROLE_ARCHIVED = 'archived';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
