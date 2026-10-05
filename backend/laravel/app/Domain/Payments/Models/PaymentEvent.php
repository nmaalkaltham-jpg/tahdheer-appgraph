<?php

namespace App\Domain\Payments\Models;

use App\Domain\Privacy\RedactedJson;
use Illuminate\Database\Eloquent\Model;

/** Append-only payment history row. */
class PaymentEvent extends Model
{
    public $timestamps = false;

    protected $table = 'payment_events';

    protected $guarded = [];

    protected function casts(): array
    {
        // Provider payloads echo the buyer's `/orders/{token}` link; RedactedJson keeps everything else.
        return ['payload' => RedactedJson::class, 'created_at' => 'datetime'];
    }
}
