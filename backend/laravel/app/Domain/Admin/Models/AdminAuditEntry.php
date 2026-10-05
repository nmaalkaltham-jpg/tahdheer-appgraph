<?php

namespace App\Domain\Admin\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only audit row (MIP §8.5). Never updated, never deleted. */
class AdminAuditEntry extends Model
{
    protected $table = 'admin_audit_log';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
