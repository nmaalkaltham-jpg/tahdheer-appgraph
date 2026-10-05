<?php

namespace App\Domain\Privacy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * سجلّ موافقةٍ واحد — يُضاف ولا يُعدَّل (§١٠٦).
 *
 * السحب حدثٌ جديد لا تعديلٌ للقديم: السجلّ دليلُ امتثال، وتعديلُه يُفقد
 * إثبات أنّ الإذن كان قائمًا يوم استُعمل.
 */
class ConsentRecord extends Model
{
    protected $table = 'consent_records';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['granted' => 'boolean', 'recorded_at' => 'datetime'];
    }
}
