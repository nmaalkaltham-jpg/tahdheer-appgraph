<?php

namespace App\Domain\Booking\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceResource extends Model
{
    protected $table = 'service_resources';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
