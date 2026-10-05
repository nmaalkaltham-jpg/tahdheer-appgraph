<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** موقع مخزون (§٣٠): مستودعٌ أو متجر أو مورّد أو مجمّع افتراضيّ. */
class InventoryLocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sellable' => 'boolean', 'active' => 'boolean'];
    }

    /** المواقع التي يُعرض مخزونها للبيع، بترتيب الأولويّة. */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('active', true)->where('sellable', true)->orderBy('priority');
    }
}
