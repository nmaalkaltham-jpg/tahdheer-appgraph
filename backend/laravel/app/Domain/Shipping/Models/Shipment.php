<?php

namespace App\Domain\Shipping\Models;

use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * شحنةٌ واحدة (§٢٦).
 *
 * كيانٌ لا حقلٌ على الطلب: طلبٌ واحد قد يُشحن على دفعتين من موقعين، ولكلٍّ
 * تتبّعُها وحالتها. ولذلك «شُحن نصف الطلب» حالةٌ تُمثَّل لا عطلٌ يُخفى.
 */
class Shipment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'estimated_delivery_at' => 'datetime',
        ];
    }

    /** حالاتٌ لا عمل بعدها. */
    public const TERMINAL = ['DELIVERED', 'RETURNED', 'CANCELLED'];

    /** حالاتٌ تعني أنّ الطرد خرج من أيدينا. */
    public const IN_FLIGHT = ['PICKED_UP', 'IN_TRANSIT', 'OUT_FOR_DELIVERY'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(FulfillmentUnit::class, 'fulfillment_unit_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ShipmentPackage::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /**
     * رابط التتبّع مبنيٌّ من قالب الناقل.
     *
     * فارغٌ حين لا رقم أو لا قالب — ولا يُبنى رابطٌ مُلفَّق يقود الزبون إلى
     * صفحة خطأ عند الناقل.
     */
    public function trackingUrl(): ?string
    {
        $template = $this->carrier?->tracking_url_template;

        if ($template === null || $this->tracking_number === null) {
            return null;
        }

        return str_replace('{tracking}', rawurlencode($this->tracking_number), $template);
    }
}
