<?php

namespace App\Domain\Licensing\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseKey extends Model
{
    protected $table = 'license_keys';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reserved_until' => 'datetime', 'assigned_at' => 'datetime', 'activated_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
