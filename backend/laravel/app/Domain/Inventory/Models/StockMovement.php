<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * سطرُ دفتر — يُضاف ولا يُعدَّل ولا يُحذف (§٢٩).
 *
 * تصحيح الخطأ حركةٌ معاكسة بسببٍ مكتوب، لا حذفُ سطر. `balance_after`
 * مخزَّنٌ لا محسوب: مراجعةُ نقطةٍ بعينها لا تحتاج جمعَ الدفتر كلّه.
 */
class StockMovement extends Model
{
    protected $table = 'stock_ledger';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
