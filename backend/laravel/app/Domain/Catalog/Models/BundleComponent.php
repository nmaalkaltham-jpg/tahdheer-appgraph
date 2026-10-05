<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BundleComponent extends Model
{
    protected $table = 'bundle_components';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }

    /** الحزمة التي ينتمي إليها هذا المكوّن. */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'bundle_product_group_id');
    }

    /** الصيغة المكوِّنة. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'component_variant_id');
    }
}
