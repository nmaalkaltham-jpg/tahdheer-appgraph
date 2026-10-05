<?php

namespace App\Domain\Cart\Models;

use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند سلّة (§٩٨).
 *
 * الهويّة `(السلّة، المنتج، الصيغة، العرض)` لا المنتج وحده: بغير ذلك تبتلع
 * صيغةٌ أختَها في السلّة صامتةً. والصيغة والعرض فارغان للبنود التي سبقت
 * النموذج الكونيّ، ويتلازمان بقيدٍ في قاعدة البيانات.
 */
class CartItem extends Model
{
    protected $table = 'cart_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['selected_options' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ProductOffer::class, 'product_offer_id');
    }
}
