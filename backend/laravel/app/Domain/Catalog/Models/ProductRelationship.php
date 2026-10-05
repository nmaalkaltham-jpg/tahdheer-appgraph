<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRelationship extends Model
{
    protected $table = 'product_relationships';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    /** المجموعة الموصى بها — طرف العلاقة الآخر. */
    public function target(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'to_product_group_id');
    }
}
