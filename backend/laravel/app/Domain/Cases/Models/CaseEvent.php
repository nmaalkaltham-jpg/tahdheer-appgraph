<?php

declare(strict_types=1);

namespace App\Domain\Cases\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * حدثٌ في خيط القضيّة (§٧).
 *
 * الخيط يُلحق ولا يُعدَّل: تاريخُ قرارٍ يُحرَّر لاحقًا ليس تاريخًا.
 */
class CaseEvent extends Model
{
    protected $table = 'case_events';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'datetime'];
    }
}
