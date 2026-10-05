<?php

namespace App\Domain\Privacy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * طلب صاحب البيانات ومهلته (§١٠٦).
 *
 * المهلة مخزَّنة لا محسوبة عند القراءة: حسابُها من إعدادٍ متغيّر يجعل طلبًا
 * تأخّر يبدو في وقته. التاريخ المحفوظ هو الوعد الذي قُطع.
 */
class DataSubjectRequest extends Model
{
    protected $table = 'data_subject_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'due_at' => 'datetime',
            'identity_verified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** ما تجاوز مهلته ولم يُغلق — أوّل ما يُقرأ في طابور الامتثال. */
    public function isOverdue(): bool
    {
        return ! in_array($this->status, ['COMPLETED', 'REFUSED', 'PARTIALLY_COMPLETED'], true)
            && $this->due_at !== null
            && $this->due_at->isPast();
    }
}
