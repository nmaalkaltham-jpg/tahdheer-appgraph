<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * الرصيد المُشتقّ — **ليس مصدر الحقيقة** (§٢٩).
 *
 * يُقرأ كثيرًا فيُحتفظ به مادّةً، ويُعاد بناؤه من الدفتر متى شُكّ فيه.
 * كلّ تغييرٍ هنا يقابله سطرٌ في `stock_ledger`؛ تعديلُه مباشرةً يكسر
 * المراجعة ويجعل الفرق بين الجرد والنظام غير قابلٍ للتفسير.
 */
class StockLevel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['counted_at' => 'datetime'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    /** ما يمكن بيعه الآن: الموجود ناقص المحجوز. قد يكون سالبًا مع الطلب المسبق. */
    public function available(): int
    {
        return $this->on_hand - $this->reserved;
    }

    public function needsReorder(): bool
    {
        return $this->reorder_point !== null && $this->available() <= $this->reorder_point;
    }
}
