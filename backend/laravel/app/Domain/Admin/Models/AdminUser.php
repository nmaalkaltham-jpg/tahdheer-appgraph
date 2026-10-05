<?php

namespace App\Domain\Admin\Models;

use App\Domain\Admin\AdminRole;
use Illuminate\Database\Eloquent\Model;

/**
 * An operations-console account. Separate identity space from `customers`
 * (passwordless by design) — the hash never leaves the server: it is hidden
 * from array/JSON casting and no endpoint ever returns it.
 */
class AdminUser extends Model
{
    protected $guarded = [];

    /*
     * التفضيلات شخصيّة لا سرّيّة — لكنّ قائمة الحسابات تمرّ على كلّ من
     * يملك `admins.manage`، وتخطيطُ زميلٍ ليس ممّا يُعرض عليه. لا تخرج إلّا
     * من `GET /admin/me/preferences` لصاحبها.
     */
    protected $hidden = ['password_hash', 'preferences', 'preferences_version'];

    protected function casts(): array
    {
        return [
            'role' => AdminRole::class,
            'last_login_at' => 'datetime',
            'preferences' => 'array',
            'preferences_version' => 'integer',
        ];
    }

    public function allows(string $permission): bool
    {
        return $this->role->allows($permission);
    }

    /** camelCase identity payload (API convention). */
    public function toIdentityArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'roleLabel' => $this->role->labelAr(),
            'lastLoginAt' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
