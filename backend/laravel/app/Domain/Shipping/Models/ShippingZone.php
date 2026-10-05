<?php

namespace App\Domain\Shipping\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingZone extends Model
{
    protected $table = 'shipping_zones';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['country_codes' => 'array', 'regions' => 'array', 'active' => 'boolean'];
    }
}
