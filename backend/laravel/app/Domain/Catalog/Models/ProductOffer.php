<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\ProductCapabilities;
use App\Domain\Licensing\Models\LicenseTerms;
use App\Domain\Pricing\PricingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * العرض: كيف تُباع هذه الصيغة — نموذج البيع والسعر والمخزون والضريبة (§٤١ §٧).
 *
 * الصيغة الواحدة قد تحمل أكثر من عرض: شراءٌ لمرّة، واشتراكٌ شهريّ، ورخصةُ
 * مؤسّسة. ولذلك لا يعيش السعر على المنتج.
 */
class ProductOffer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sale_from' => 'datetime',
            'sale_to' => 'datetime',
            'available_from' => 'datetime',
            'available_to' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(OfferFulfillment::class)->orderBy('position');
    }

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    /** شروط الترخيص المربوطة بالعرض، أو null حين لم يُقرَّر ترخيص. */
    public function licenseTerms(): BelongsTo
    {
        return $this->belongsTo(LicenseTerms::class, 'license_terms_id');
    }

    /**
     * الثمن النافذ الآن بالوحدات الصغرى.
     *
     * التخفيض يسري داخل نافذته وحدها؛ ونافذةٌ بلا طرفٍ مفتوحةٌ من ذلك الطرف.
     * لا قيمة احتياطيّة: الثمن الأصليّ موجودٌ دائمًا بحكم القيد.
     */
    public function effectivePriceMinor(?\DateTimeInterface $at = null): int
    {
        return PricingService::activeSaleWithin($this->sale_minor, $this->sale_from, $this->sale_to, $at)
            ?? (int) $this->regular_minor;
    }

    /** §١٠٠ — هل يوجب هذا العرض عنوان شحن؟ يُقرَّر من الاستراتيجيّات لا من النوع. */
    public function requiresShippingAddress(): bool
    {
        return $this->fulfillments
            ->contains(fn (OfferFulfillment $f) => ProductCapabilities::requiresShipping($f->strategy));
    }
}
