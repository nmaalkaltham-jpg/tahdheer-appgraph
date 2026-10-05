<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $table = 'refunds';

    protected $guarded = [];

    /** الحالات التي خرج فيها المال فعلًا (أو أُقرّ خروجه يدويًّا). */
    public const SETTLED = ['SUCCEEDED', 'MANUAL'];

    /** الحالات التي ما زال الحجز فيها قائمًا وقد يخرج المال. */
    public const OPEN = ['PENDING', 'UNKNOWN'];

    protected function casts(): array
    {
        return ['settled_at' => 'datetime'];
    }
}
