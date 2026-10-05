<?php

namespace App\Domain\Licensing\Models;

use Illuminate\Database\Eloquent\Model;

class GrantedLicense extends Model
{
    protected $table = 'granted_licenses';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['commercial_rights' => 'boolean', 'redistribution_rights' => 'boolean', 'modification_rights' => 'boolean', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
