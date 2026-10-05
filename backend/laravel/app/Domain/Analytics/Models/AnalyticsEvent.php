<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use Illuminate\Database\Eloquent\Model;

/** حدث قياسٍ واحد. المعرّفان فارغان بلا موافقة — انظر `EventIngest`. */
class AnalyticsEvent extends Model
{
    protected $table = 'analytics_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'meta' => 'array', 'is_logged_in' => 'boolean'];
    }
}
