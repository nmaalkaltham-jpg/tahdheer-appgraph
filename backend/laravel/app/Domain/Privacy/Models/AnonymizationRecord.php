<?php

namespace App\Domain\Privacy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * أثر المحو (§١٠٦).
 *
 * يبقى **أنّ المحو وقع** بعد محو الشخص: بغيره يستحيل إثبات الامتثال،
 * ويبدو الطلبُ المجهولُ الاسم عطلًا في البيانات لا قرارًا نُفِّذ.
 */
class AnonymizationRecord extends Model
{
    protected $table = 'anonymization_log';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['fields_cleared' => 'array', 'created_at' => 'datetime'];
    }
}
