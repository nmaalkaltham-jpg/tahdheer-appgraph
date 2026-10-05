<?php

namespace App\Domain\Booking\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $table = 'bookings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
