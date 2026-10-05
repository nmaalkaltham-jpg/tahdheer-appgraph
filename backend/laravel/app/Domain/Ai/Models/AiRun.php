<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use Illuminate\Database\Eloquent\Model;

/** أثر نداءٍ واحد. بصمةُ مدخلٍ لا نصّه — انظر هجرة `create_ai_tables`. */
class AiRun extends Model
{
    protected $table = 'ai_runs';

    protected $guarded = [];

    /** لا `updated_at`: الصفّ يُكتب مرّةً ولا يُعدَّل. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['cached' => 'boolean', 'created_at' => 'datetime'];
    }
}
