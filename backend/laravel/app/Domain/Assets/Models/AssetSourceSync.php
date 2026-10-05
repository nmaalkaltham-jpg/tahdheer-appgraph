<?php

namespace App\Domain\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * محاولة مزامنةٍ واحدة (§١٦).
 *
 * السجلّ هو ما يحوّل «فشل» من عمودٍ يُكتب فوقه إلى تاريخٍ يُقرأ. هدف §١٦٠
 * صفرُ إخفاقاتٍ **غير مُفسَّرة** — والتفسير يحتاج ما قبل الإخفاق لا حالته.
 */
class AssetSourceSync extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(AssetSource::class, 'asset_source_id');
    }
}
