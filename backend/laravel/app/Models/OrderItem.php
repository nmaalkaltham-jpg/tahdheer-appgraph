<?php

namespace App\Models;

use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند طلبٍ ولقطتُه التاريخيّة (§١٠١).
 *
 * اللقطة تُكتب وقت الشراء ولا تُشتقّ لاحقًا من المنتج: «تعديلات المنتج لا
 * تُعيد كتابة حقيقة الطلب». ولذلك السعر والصيغة والاستراتيجيّات أعمدةٌ هنا
 * لا علاقاتٌ تُتبَع وقت القراءة.
 *
 * وحقلا JSON يحتاجان تحويلًا صريحًا: بدونه يحاول Eloquent حفظ مصفوفةٍ نصًّا
 * فيفشل الإدراج كلّه — وهو ما أسقط كلّ عمليّة دفع فور إضافتهما.
 */
class OrderItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fulfillment_strategies_snapshot' => 'array',
            'selected_options_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ProductOffer::class, 'product_offer_id');
    }
}
