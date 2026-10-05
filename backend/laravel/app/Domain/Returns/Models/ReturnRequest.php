<?php

namespace App\Domain\Returns\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRequest extends Model
{
    protected $table = 'return_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['policy_snapshot' => 'array', 'requested_at' => 'datetime', 'approved_at' => 'datetime', 'received_at' => 'datetime', 'inspected_at' => 'datetime', 'refunded_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
