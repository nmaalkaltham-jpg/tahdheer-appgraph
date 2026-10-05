<?php

namespace App\Models;

use App\Domain\Assets\Models\ProductFile;
use App\Domain\Catalog\Models\BundleComponent;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\I18n\ProductTranslation;
use App\Domain\Reviews\Models\ReviewAggregate;
use App\Domain\SEO\Models\ProductFaq;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function price(): HasOne
    {
        return $this->hasOne(ProductPrice::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(ProductFact::class);
    }

    /**
     * المجموعة المناظِرة في النموذج الكونيّ، عبر جسر التوسيع–الترحيل–التقليص.
     *
     * `legacy_product_id` فريدٌ على `product_groups`، فالعلاقة واحدةٌ لواحد
     * فعلًا لا بالاتّفاق. وهي المدخل إلى صيغ الملفّ وأسعارها وأقسام المحتوى
     * وجدول التوافق — كلّ ما ترسمه صفحة المنتج ولا يسعه صفّ `products`.
     *
     * تبقى `null` لمنتجٍ أُنشئ قبل الردم أو خارجه؛ القارئ يُسقط المفتاح حينئذ
     * ولا يخترع مجموعةً وهميّة.
     */
    public function group(): HasOne
    {
        return $this->hasOne(ProductGroup::class, 'legacy_product_id');
    }

    /**
     * عضويّات هذا المنتج في الحزم: صفوف `bundle_components` التي تشير إلى
     * إحدى صيغه. المنتجُ يدخل الحزمة بصيغته لا بصفّه، فالمسار يمرّ بالصيغة.
     */
    public function bundleComponents(): HasManyThrough
    {
        return $this->hasManyThrough(
            BundleComponent::class,
            ProductVariant::class,
            'legacy_product_id',
            'component_variant_id',
        );
    }

    /**
     * Cover image, when one exists. The card renders no media block without it —
     * a missing cover is an absence, never a placeholder pretending to be art.
     */
    /**
     * Materialized review aggregate. Absent until real approved reviews exist —
     * the card then shows no stars at all rather than an empty five-star row.
     */
    public function reviewAggregate(): HasOne
    {
        return $this->hasOne(ReviewAggregate::class);
    }

    public function cover(): HasOne
    {
        return $this->hasOne(ProductMedium::class)->where('is_cover', true)->orderBy('position');
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedium::class)->orderBy('position');
    }

    /**
     * أسئلة المنتج. الجدول موجود منذ البداية ولم تكن له علاقة على النموذج،
     * فكل قارئ كان يستعلم عنه يدويًّا — أو لا يقرؤه أصلًا.
     */
    public function faqs(): HasMany
    {
        return $this->hasMany(ProductFaq::class)->orderBy('position');
    }

    /** ملفّات المنتج المسلَّمة — محتوياته الفعلية، بأدوارها. */
    public function files(): HasMany
    {
        return $this->hasMany(ProductFile::class)->orderBy('position');
    }

    /** الترجمات المحفوظة — لا تشمل اللغة الافتراضية، فهي أعمدة هذا الصفّ. */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }

    public function seo(): HasOne
    {
        return $this->hasOne(ProductSeo::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }
}
