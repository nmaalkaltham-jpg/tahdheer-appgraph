<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** صنف الضريبة — مرجعٌ مستقلّ عن تسمية الفئة في الواجهة (§١٠٧). */
class TaxClass extends Model
{
    protected $guarded = [];

    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class);
    }

    /**
     * الضريبة المستخرجة من ثمنٍ شاملٍ لها.
     *
     * الطلبات القائمة تستخرج ولا تضيف (`orders.vat_minor`)، وهذا يطابقها.
     */
    public function extractFromGrossMinor(int $grossMinor): int
    {
        return (int) round($grossMinor * $this->rate_bps / (10000 + $this->rate_bps));
    }
}
