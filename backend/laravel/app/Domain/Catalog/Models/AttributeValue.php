<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قيمة سمةٍ مكتوبةُ النوع، لصاحبٍ واحدٍ حصرًا: مجموعةٌ أو صيغة (§٤٤ §٥٠).
 *
 * الوحدة عمودٌ مستقلّ لا لاحقةٌ داخل نصّ، فيبقى الرقم رقمًا يُفرز ويُقارن:
 * «١٢٠ صفحة» تُخزَّن `int_value = 120` و`unit = 'page'`، لا `'120 صفحة'`.
 *
 * القيود في قاعدة البيانات تضمن عمودَ قيمةٍ واحدًا مملوءًا وصاحبًا واحدًا —
 * وقد جُرّبت ورُفضت فعلًا، لا ادّعاءً.
 */
class AttributeValue extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'json_value' => 'array',
            'bool_value' => 'boolean',
            'date_value' => 'date',
            'datetime_value' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** §٥٩ — ما يجوز أن يصير حقيقةً قانونيّة بلا مراجعةٍ بشريّة. */
    public const CANONICAL_SOURCES = [
        'VERIFIED_HUMAN', 'AUTHORITATIVE_SOURCE', 'CANONICAL_TAXONOMY', 'VERIFIED_SYSTEM',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class, 'attribute_definition_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** القيمة من عمودها الصحيح بحسب نوع السمة. */
    public function typedValue(): mixed
    {
        return $this->{$this->definition->valueColumn()};
    }

    /**
     * §٥٩ — هل هذه القيمة حقيقةٌ قانونيّة أم مرشَّحة؟
     *
     * اقتراح الذكاء الاصطناعيّ واستخراج OCR يبقيان مرشَّحَين حتّى يعتمدهما
     * إنسان. الهدف الصارم: صفر ترقيةٍ غير مُتحقَّقة إلى القانونيّ (§١٦٠).
     */
    public function isCanonical(): bool
    {
        return in_array($this->source, self::CANONICAL_SOURCES, true);
    }
}
