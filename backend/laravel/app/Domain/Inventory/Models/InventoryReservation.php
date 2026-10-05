<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * حجزٌ بأجل (§٩).
 *
 * بلا أجل، سلّةٌ مهجورة تُجمّد مخزونًا إلى الأبد فينفد المتجر بطلباتٍ لم
 * تُدفع. الانتهاء يُفرج تلقائيًّا.
 */
class InventoryReservation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'settled_at' => 'datetime'];
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'HELD')->whereNotNull('expires_at')->where('expires_at', '<', now());
    }

    public function isOpen(): bool
    {
        return $this->status === 'HELD';
    }
}
