<?php

declare(strict_types=1);

namespace App\Domain\Navigation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * سجلّ عملية نشر — دائم، ويجيب سؤالًا لا تجيبه حالة القائمة وحدها:
 * «ماذا حدث في تلك المحاولة، وأين توقّفت؟».
 *
 * بدونه يفقد انقطاعُ الشبكة بعد الالتزام معناه: المتصفّح يعيد المحاولة
 * والخادم لا يعرف أنه أتمّ العمل.
 *
 * @property string $id
 * @property string $status
 * @property string $current_step
 */
class NavigationPublishOperation extends Model
{
    /** الحالات — كل واحدة تقابل خطوة تقع فعلًا، لا خطوة موصوفة. */
    public const CREATED = 'CREATED';

    public const LOCKED = 'LOCKED';

    public const PREFLIGHT_PASSED = 'PREFLIGHT_PASSED';

    public const SNAPSHOTTED = 'SNAPSHOTTED';

    public const COMMITTED = 'COMMITTED';

    public const INVALIDATING = 'INVALIDATING';

    public const VERIFIED = 'VERIFIED';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    public const ROLLING_BACK = 'ROLLING_BACK';

    public const ROLLED_BACK = 'ROLLED_BACK';

    public const ROLLBACK_FAILED = 'ROLLBACK_FAILED';

    protected $table = 'navigation_publish_operations';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'base_revision' => 'integer',
            'target_revision' => 'integer',
            'snapshot_revision' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
            'steps' => 'array',
        ];
    }

    /**
     * يقيّد خطوةً في دفتر العمليّة.
     *
     * الدفتر يُلحَق ولا يُستبدَل: خطوةٌ فشلت ثمّ أُعيدت تبقى الاثنتان فيه،
     * لأنّ سؤال «ماذا حدث» لا يجيبه آخرُ سطرٍ وحده.
     */
    public function record(string $step, string $status, ?string $reason = null): void
    {
        $journal = (array) ($this->steps ?? []);
        $journal[] = array_filter([
            'id' => $step,
            'status' => $status,
            'at' => now()->toIso8601String(),
            'reason' => $reason,
        ], static fn ($v): bool => $v !== null);

        $this->forceFill(['steps' => array_values($journal)])->save();
    }

    /**
     * ينتقل إلى الخطوة التالية.
     *
     * الحالة والخطوة يتحرّكان معًا: خطوةٌ تتقدّم بلا حالة تجعل السجلّ يقول
     * «أين» ولا يقول «كيف انتهى».
     */
    public function advance(string $step): void
    {
        $this->forceFill(['status' => $step, 'current_step' => $step])->save();
    }

    public function complete(): void
    {
        $this->forceFill([
            'status' => self::COMPLETED,
            'current_step' => self::COMPLETED,
            'completed_at' => now(),
        ])->save();
    }
}
