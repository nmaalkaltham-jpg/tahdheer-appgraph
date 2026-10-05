<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * اقتراحٌ ينتظر قرار إنسان.
 *
 * ─── القاعدة التي يفرضها هذا الصفّ ───
 *
 * لا اقتراحٍ يصير بياناتٍ قانونيّة بلا اعتماد. `source` يبقى خارج
 * `AttributeValue::CANONICAL_SOURCES` دائمًا، والاعتماد فعلٌ صريحٌ من مشرفٍ
 * مسمًّى بوقتٍ مسجَّل — يفرضه قيدٌ في قاعدة البيانات لا عادةٌ في الشيفرة.
 */
class AiSuggestion extends Model
{
    protected $table = 'ai_suggestions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array', 'approved_at' => 'datetime'];
    }

    /** @param Builder<self> $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'PENDING');
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }
}
