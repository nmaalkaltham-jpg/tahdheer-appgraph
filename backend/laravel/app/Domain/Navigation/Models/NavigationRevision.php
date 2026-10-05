<?php

declare(strict_types=1);

namespace App\Domain\Navigation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * إصدار محفوظ من شجرة قائمة — وهو مخزن اللقطات.
 *
 * كل حفظ مسوّدة يكتب صفًّا هنا ببصمته. النشر لا «يأخذ لقطة» بفعل منفصل: يسجّل
 * أيّ إصدار كان منشورًا قبله، فالتراجع يستعيد شجرةً محفوظة فعلًا لا إعادةَ
 * بناءٍ من ذاكرة.
 *
 * @property int $menu_id
 * @property int $revision
 * @property array<int, array<string, mixed>> $nodes
 * @property string $fingerprint
 */
class NavigationRevision extends Model
{
    protected $table = 'navigation_revisions';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'nodes' => 'array',
            'revision' => 'integer',
            'node_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
