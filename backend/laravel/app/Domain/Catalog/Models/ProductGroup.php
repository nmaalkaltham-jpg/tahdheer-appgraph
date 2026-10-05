<?php

namespace App\Domain\Catalog\Models;

use App\Models\Grade;
use App\Models\Product;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * المجموعة: ما يراه الزبون منتجًا واحدًا، وتحته صيغةٌ أو أكثر (§٤١).
 *
 * الطبيعة هنا تصف **ما هو المنتج** وحدها. لا تقرّر كيف يُباع ولا كيف يُسلَّم
 * ولا أيُّ عنوانٍ يُطلَب في الدفع — تلك قراراتُ العرض واستراتيجيّات التسليم.
 */
class ProductGroup extends Model
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

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /**
     * الصيغ التي تُعرض للزائر. المسوّدة والمتقاعدة لا تُباع، فلا تُعرض.
     *
     * علاقةٌ مستقلّة لا نطاقٌ على `variants`: تلك تخدم اللوحة وتحتاج رؤية
     * المسوّدات، وإقحامُ التصفية فيها يُخفي عن المحرّر ما يحرّره.
     */
    public function publishedVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)
            ->where('status', 'published')
            ->orderBy('position')
            ->orderBy('id');
    }

    /** مكوّنات الحزمة بترتيبها — فارغةٌ لغير `COMPOSITE`. */
    public function bundleComponents(): HasMany
    {
        return $this->hasMany(BundleComponent::class, 'bundle_product_group_id')->orderBy('position')->orderBy('id');
    }

    public function compatibilities(): HasMany
    {
        return $this->hasMany(ProductCompatibility::class)->orderBy('id');
    }

    public function contentSections(): HasMany
    {
        return $this->hasMany(ProductContentSection::class)->orderBy('position')->orderBy('id');
    }

    /**
     * العلاقات الصادرة عن هذه المجموعة — «يُشترى معه» وأخواتها.
     *
     * الترتيب يقدّم التحريريّ على السلوكيّ عند تساوي الموضع: قرارُ محرّرٍ
     * أعلى مرتبةً في سلّم الحقيقة (§٥٩) من إحصاءٍ آليّ.
     */
    public function relationships(): HasMany
    {
        return $this->hasMany(ProductRelationship::class, 'from_product_group_id')
            ->orderBy('position')
            ->orderByRaw("case when source = 'VERIFIED_HUMAN' then 0 else 1 end")
            ->orderBy('id');
    }

    /** السمات المشتركة بين كلّ الصيغ. ما يميّز صيغةً عن أختها محلُّه الصيغة. */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    /** الجسر إلى النموذج القديم. يسقط في مرحلة التقليص. */
    public function legacyProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'legacy_product_id');
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

    /**
     * كلّ استراتيجيّات التسليم التي تظهر تحت هذه المجموعة، بلا تكرار.
     *
     * هذا ما يجعل السلّة المختلطة ممكنة (§١٠٠): الدفع يسأل الطلب عمّا يحتاجه
     * فعلًا — عنوان شحنٍ أو موعدٍ أو لا شيء — بدل أن يفترض من نوعٍ واحد.
     */
    public function fulfillmentStrategies(): array
    {
        return $this->variants
            ->flatMap(fn (ProductVariant $v) => $v->offers)
            ->flatMap(fn (ProductOffer $o) => $o->fulfillments)
            ->pluck('strategy')
            ->unique()
            ->values()
            ->all();
    }
}
