<?php

namespace App\Domain\Shipping\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentItem extends Model
{
    protected $table = 'shipment_items';

    protected $guarded = [];
}
