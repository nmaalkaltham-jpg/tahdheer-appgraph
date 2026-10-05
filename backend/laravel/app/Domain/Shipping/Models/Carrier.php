<?php

namespace App\Domain\Shipping\Models;

use Illuminate\Database\Eloquent\Model;

class Carrier extends Model
{
    protected $table = 'carriers';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
