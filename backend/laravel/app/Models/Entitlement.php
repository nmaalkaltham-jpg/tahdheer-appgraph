<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Entitlement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * سطر الطلب الذي وُلد عنه هذا الاستحقاق — وهو حامل الصيغة المشتراة
     * (`order_items.product_variant_id`). التسليم يحتاجه ليختار الملفّ
     * الصحيح، فبلا هذه العلاقة لا سبيل إلى الصيغة إلّا استعلامٌ يدويّ.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
