<?php

namespace App\Domain\Fulfillment\Models;

use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قطعةُ تنفيذٍ واحدة تحت بندِ طلب (§١٠).
 *
 * البند قد يُنتج أكثر من وحدة: تنزيلٌ رقميّ وشحنةٌ ماديّة من منتجٍ هجين
 * (§٣٤). ولذلك «اكتمل الطلب» ليست حالةً تُعلن بل خلاصةً تُحسب من الوحدات.
 */
class FulfillmentUnit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['fulfilled_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    /** حالاتٌ لا عمل بعدها. */
    public const TERMINAL = ['FULFILLED', 'CANCELLED', 'REVOKED'];

    /** حالاتٌ تمنع إقفال الطلب. */
    public const OPEN = ['PENDING', 'READY', 'PROCESSING', 'PARTIALLY_FULFILLED', 'FAILED'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }
}
