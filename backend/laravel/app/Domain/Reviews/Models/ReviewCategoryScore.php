<?php

namespace App\Domain\Reviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the 6 criteria of MIP §4.7, scored 1–5. New reviews only — older
 * reviews carry no scores and the UI omits the block instead of inventing it.
 */
class ReviewCategoryScore extends Model
{
    /** criterion key => Arabic label shown to customers. */
    public const CRITERIA = [
        'content_quality' => 'جودة المحتوى',
        'edit_ease' => 'سهولة التعديل',
        'design_quality' => 'جودة التصميم',
        'worksheets_value' => 'فائدة أوراق العمل',
        'description_match' => 'مطابقة الوصف',
        'download_ease' => 'سهولة التحميل',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['score' => 'integer'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
