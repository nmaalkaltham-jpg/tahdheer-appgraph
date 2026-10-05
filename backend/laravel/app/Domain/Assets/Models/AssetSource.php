<?php

namespace App\Domain\Assets\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * من أين جاء هذا الأصل، وهل ما يزال هناك (§١٣ §١٦).
 *
 * الهويّة `(provider, external_id)` لا اسم الملفّ. حالة المزامنة مُشتقّة من
 * مقارنة المراجعتين لا مُعلنة يدويًّا، و`SyncGovernor` وحده يكتبها.
 */
class AssetSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_modified_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'version_frozen' => 'boolean',
        ];
    }

    /** حالاتٌ تمنع البيع: لا يُعرض للشراء ما لا يمكن تسليمه (§١٥). */
    public const BLOCKING_STATES = ['SOURCE_MISSING', 'ACCESS_REVOKED', 'SYNC_FAILED'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function syncs(): HasMany
    {
        return $this->hasMany(AssetSourceSync::class)->latest('created_at');
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('sync_state', self::BLOCKING_STATES);
    }

    public function scopeNeedingSync(Builder $query): Builder
    {
        return $query->where('sync_state', 'SOURCE_CHANGED')->where('version_frozen', false);
    }

    /** هل يمكن تسليم هذا المصدر الآن؟ */
    public function isDeliverable(): bool
    {
        if (in_array($this->sync_state, self::BLOCKING_STATES, true)) {
            return false;
        }

        // الوسيط لا يملك نسخةً، فالتسليم منه لا يحتاج أصلًا داخليًّا.
        return $this->delivery_mode === 'EXTERNAL_ACCESS_BROKER' || $this->asset_id !== null;
    }

    /**
     * مصدرٌ تغيّر ونسخته مجمّدة: الزبون يأخذ ما اشتراه لا ما صار عليه
     * الملفّ بعده (§١٦). ليست حالة عطل.
     */
    public function isServingFrozenCopy(): bool
    {
        return $this->version_frozen && $this->sync_state === 'SOURCE_CHANGED';
    }
}
