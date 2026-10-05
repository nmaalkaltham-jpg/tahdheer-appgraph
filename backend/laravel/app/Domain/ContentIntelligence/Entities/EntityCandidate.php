<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Entities;

use App\Domain\Assets\Models\Asset;
use App\Domain\ContentIntelligence\Models\AssetExtraction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * اقتراحُ كيانٍ واحد لبُعدٍ واحد، بشاهده وموقفه من الفهرس.
 *
 * طبقةٌ داخليّة: لا مسار عامّ يعيدها. الشاهد مقطعٌ من نصّ ملفٍّ مدفوع
 * (البندان ٥٨ و١٢٦)، وكشفُه للمشتري يعطيه بعضَ ما لم يشترِه.
 *
 * ولا تحويل `dimension`/`agreement` إلى تعدادَيهما هنا رغم أنّ القاعدة
 * تفرضهما بقيد CHECK: الجدول قد يُقرأ بعد إضافة بُعدٍ جديد في نسخةٍ أحدث
 * من الاشتقاق، وتحويلٌ يجعل قراءة الجدول كلّه ترمي `ValueError` على صفٍّ
 * واحدٍ لا يخصّ القارئ — الدرس نفسه المكتوب في `AssetExtraction`.
 */
class EntityCandidate extends Model
{
    protected $table = 'entity_candidates';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'catalog_entity_id' => 'integer',
            'support' => 'integer',
            'conflicts' => 'integer',
            'confidence' => 'float',
            'alternatives' => 'array',
            'derived_at' => 'datetime',
        ];
    }

    public function extraction(): BelongsTo
    {
        return $this->belongsTo(AssetExtraction::class, 'asset_extraction_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
