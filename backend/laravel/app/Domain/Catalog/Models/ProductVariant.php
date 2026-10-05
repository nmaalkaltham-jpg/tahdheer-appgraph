<?php

namespace App\Domain\Catalog\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * الصيغة: وحدة المخزون والهويّة القابلة للبيع (§٤١).
 *
 * المعرّفات التجاريّة (GTIN·MPN·ISBN·barcode) تبقى فارغةً حتّى تُعرف.
 * الفراغ هنا يعني «مجهول»، ولا يُملأ بقيمةٍ مخترعة أبدًا (§١٥٨).
 */
class ProductVariant extends Model
{
    protected $guarded = [];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class);
    }

    /**
     * العرض الفعّال الذي يُسعَّر به المتجر.
     *
     * `hasOne` لا `hasMany` عمدًا: عرضان فعّالان لصيغةٍ واحدة يعنيان سعرين
     * لشيءٍ واحد — حالةٌ تُصلَح في البيانات لا تُلطَّف هنا باختيارٍ ضمنيّ.
     * والترتيب بالمعرّف يجعل الاختيار حتميًّا لا عشوائيًّا حتّى في تلك الحال.
     */
    public function offer(): HasOne
    {
        return $this->hasOne(ProductOffer::class)->where('status', 'active')->orderBy('id');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function legacyProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'legacy_product_id');
    }

    /** العنوان المعروض: عنوان الصيغة إن وُجد، وإلّا عنوان المجموعة. */
    public function displayTitle(): string
    {
        return $this->title ?? $this->group->title;
    }
}
