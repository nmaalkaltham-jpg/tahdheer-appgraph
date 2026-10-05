<?php

namespace App\Domain\Shipping\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قاعدة تسعير شحنٍ واحدة (§٣١).
 *
 * النطاقات نصفُ مفتوحة `[min, max)`: نطاقان متلاصقان بحدّين مغلقين
 * يتراكبان عند نقطة التماسّ بالضبط، فيُختار السعر بحسب ترتيب الاستعلام لا
 * بحسب قاعدة — خطأٌ لا يظهر إلّا عند الوزن الحدّيّ تمامًا.
 */
class ShippingRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function shippingClass(): BelongsTo
    {
        return $this->belongsTo(ShippingClass::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** هل يقع هذا الوزن وهذه القيمة داخل القاعدة؟ */
    public function covers(int $weightGrams, int $orderMinor): bool
    {
        if ($weightGrams < $this->min_weight_grams) {
            return false;
        }
        if ($this->max_weight_grams !== null && $weightGrams >= $this->max_weight_grams) {
            return false;
        }
        if ($orderMinor < $this->min_order_minor) {
            return false;
        }
        if ($this->max_order_minor !== null && $orderMinor >= $this->max_order_minor) {
            return false;
        }

        return true;
    }

    /**
     * الثمن النافذ. `free_over_minor` فارغًا يعني «لا مجّانيّة»، لا
     * «مجّانيّ دائمًا» — والفرق بينهما متجرٌ يشحن مجّانًا بلا أن يقصد.
     */
    public function priceFor(int $orderMinor): int
    {
        if ($this->free_over_minor !== null && $orderMinor >= $this->free_over_minor) {
            return 0;
        }

        return $this->price_minor;
    }
}
