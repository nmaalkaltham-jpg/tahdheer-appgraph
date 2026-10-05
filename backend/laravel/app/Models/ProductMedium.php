<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product image row. Dimensions are stored so the storefront can reserve the
 * box and never shift layout while the image loads (MIP §3.5, CLS 0).
 */
class ProductMedium extends Model
{
    protected $table = 'product_media';

    protected $fillable = ['product_id', 'url', 'alt_ar', 'width', 'height', 'position', 'is_cover'];

    protected function casts(): array
    {
        return ['is_cover' => 'bool', 'width' => 'int', 'height' => 'int', 'position' => 'int'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
