<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sale_from' => 'datetime',
            'sale_to' => 'datetime',
        ];
    }
}
