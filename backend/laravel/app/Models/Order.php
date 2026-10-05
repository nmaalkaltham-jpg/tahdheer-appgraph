<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * بلا هذه، `paid_at` نصّ SQL خام يخرج كما هو من الـAPI:
     * "2026-09-02 02:04:18" — بمسافة بدل T وبلا منطقة زمنية. Safari يعطي
     * Invalid Date، وChrome يفسّره وقتًا محلّيًّا. أي أن صفحة تأكيد الطلب
     * تعرض تاريخ شراء معطوبًا أو خاطئًا بلا أي خطأ في الطرفين.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
        ];
    }
}
