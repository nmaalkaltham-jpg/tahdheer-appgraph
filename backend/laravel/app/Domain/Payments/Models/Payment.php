<?php

namespace App\Domain\Payments\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $table = 'payments';

    protected $guarded = [];

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class, 'payment_id');
    }

    /**
     * الطلب الذي تخصّه هذه الدفعة.
     *
     * تُقرأ لكشف أخطر تناقضٍ في المتجر: دفعةٌ حُصّلت وطلبُها غير مدفوع —
     * مالٌ أُخذ من زبونٍ لم يستلم شيئًا، ولا يُصدر عطلًا ولا سجلًّا.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
