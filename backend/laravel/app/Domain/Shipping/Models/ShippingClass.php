<?php

namespace App\Domain\Shipping\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingClass extends Model
{
    protected $table = 'shipping_classes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['requires_signature' => 'boolean', 'fragile' => 'boolean', 'hazardous' => 'boolean'];
    }
}
