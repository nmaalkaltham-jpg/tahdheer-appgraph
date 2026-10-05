<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * تعريف السمة: مرّةً واحدة، بنوعها ووحدتها وسلوكها كاملًا (§٤٣ §٤٤).
 *
 * هذا ما يفرق السجلّ عن EAV النصّيّ: السمة تعلن نوعها، فيُعرف أين تُخزَّن
 * قيمتها، وهل تُفرز، وهل تُفلتَر، وهل تُعرِّف صيغةً، وبأيّ وزنٍ تدخل البحث.
 */
class AttributeDefinition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'allowed_values' => 'array',
            'validation_rule' => 'array',
            'category_scope' => 'array',
            'required' => 'boolean',
            'variant_defining' => 'boolean',
            'searchable' => 'boolean',
            'filterable' => 'boolean',
            'facetable' => 'boolean',
            'sortable' => 'boolean',
            'comparison_enabled' => 'boolean',
            'seo_exposable' => 'boolean',
        ];
    }

    /** العمود الذي تسكنه قيمة كلّ نوع. مصدر الحقيقة الوحيد لهذا التعيين. */
    public const VALUE_COLUMN = [
        'TEXT' => 'text_value',
        'LONG_TEXT' => 'long_text_value',
        'INTEGER' => 'int_value',
        'DECIMAL' => 'decimal_value',
        'BOOLEAN' => 'bool_value',
        'DATE' => 'date_value',
        'DATETIME' => 'datetime_value',
        'ENUM' => 'text_value',
        'MULTI_ENUM' => 'json_value',
        'MEASUREMENT' => 'decimal_value',
        'MONEY' => 'int_value',
        'URL' => 'text_value',
        'REFERENCE' => 'text_value',
        'ENTITY_REFERENCE' => 'int_value',
        'JSON_STRUCTURED' => 'json_value',
    ];

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function valueColumn(): string
    {
        return self::VALUE_COLUMN[$this->data_type];
    }
}
