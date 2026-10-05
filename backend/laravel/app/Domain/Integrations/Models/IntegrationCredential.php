<?php

namespace App\Domain\Integrations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * اعتمادُ تكاملٍ واحد.
 *
 * `value_encrypted` **مخفيّ** في التسلسل: `$hidden` ليست تجميلًا هنا، بل
 * الحاجز الأخير. مستدعٍ يكتب `return response()->json($credential)` بحسن
 * نيّة كان سيُسرّب النصّ المعمّى إلى المتصفّح — ومعمّى اليوم لا يعني معمّى
 * إلى الأبد.
 */
class IntegrationCredential extends Model
{
    protected $guarded = [];

    protected $hidden = ['value_encrypted'];

    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
            'rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
