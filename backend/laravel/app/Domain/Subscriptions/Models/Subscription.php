<?php

namespace App\Domain\Subscriptions\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $table = 'subscriptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'current_period_start' => 'datetime', 'current_period_end' => 'datetime', 'cancel_at' => 'datetime', 'canceled_at' => 'datetime', 'paused_at' => 'datetime', 'resumes_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
