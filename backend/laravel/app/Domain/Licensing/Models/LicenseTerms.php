<?php

namespace App\Domain\Licensing\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseTerms extends Model
{
    protected $table = 'license_terms';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['commercial_rights' => 'boolean', 'redistribution_rights' => 'boolean', 'modification_rights' => 'boolean'];
    }
}
