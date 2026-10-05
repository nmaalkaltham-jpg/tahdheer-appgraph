<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Models;

use App\Domain\Assets\Models\Asset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صفّ استخراجٍ واحد — (بصمة المحتوى · المستخرِج · نسخته).
 *
 * طبقةٌ داخليّة بحتة: لا مسار عامّ يعيد `text` ولا مورد API يعرضه. المحتوى
 * المستخرَج من ملفٍّ مدفوع هو الملفّ المدفوع بصيغةٍ أخرى (البندان ٥٨ و١٢٦)،
 * وكشفُه يُلغي الحاجة إلى شرائه.
 *
 * ولا تحويل `status` إلى `ExtractionStatus` هنا: الجدول يحمل صفوفًا كتبتها
 * شيفرةٌ محذوفة بحالة `ocr_unavailable` ليست من مفردات القائمة، وتحويلٌ
 * يجعل قراءةَ الجدول كلّه ترمي `ValueError` — فيسقط كلّ تقريرٍ بسبب صفوفٍ
 * لا تخصّنا. الحالة تُكتب من `ExtractionStatus` وتُقرأ نصًّا.
 */
class AssetExtraction extends Model
{
    protected $table = 'asset_extractions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'duration_ms' => 'integer',
            'bytes_read' => 'integer',
            'meta' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
