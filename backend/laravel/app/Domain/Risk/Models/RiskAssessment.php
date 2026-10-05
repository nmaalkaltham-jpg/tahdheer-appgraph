<?php

namespace App\Domain\Risk\Models;

use App\Models\Customer;
use App\Models\Entitlement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تقييمٌ محفوظ (§١٠٣ §١٠٥).
 *
 * يُخزَّن التقييم لا الحكم: الإشارات كما رُصدت والقرار وسببه. فحين يشتكي
 * زبونٌ من منعٍ يمكن قراءةُ ما رآه النظام لا تخمينُه.
 */
class RiskAssessment extends Model
{
    protected $table = 'risk_assessments';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'signals' => 'array',
            'created_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class);
    }

    /** ما ينتظر عينًا بشريّة. */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('review_outcome', 'PENDING');
    }
}
