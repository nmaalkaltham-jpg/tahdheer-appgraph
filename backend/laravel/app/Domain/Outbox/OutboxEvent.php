<?php

namespace App\Domain\Outbox;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasUuids;

    protected $table = 'outbox_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_retry_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
