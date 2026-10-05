<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class ProductContentSection extends Model
{
    protected $table = 'product_content_sections';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'verified_at' => 'datetime'];
    }
}
