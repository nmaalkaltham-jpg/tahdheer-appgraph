<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * نسخةُ موجّهٍ واحدة — مُصدَّرةٌ لا مُعدَّلة.
 *
 * تعديل موجّهٍ في مكانه يجعل اقتراحًا قديمًا يبدو كأنّه خرج من الموجّه
 * الجديد، فلا يُفسَّر تدهورٌ ولا يُنسب تحسّن. النسخة صفٌّ جديد.
 */
class AiPromptVersion extends Model
{
    protected $table = 'ai_prompt_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'output_schema' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /** الحرارة تُخزَّن بالآلاف صحيحةً وتُقرأ عشريّة. */
    public function temperature(): float
    {
        return ((int) $this->temperature_milli) / 1000;
    }
}
