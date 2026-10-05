<?php

namespace App\Domain\Operations\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * عمليّةٌ ذات أثرٍ خارجيّ، محجوزةٌ بمفتاح (§٢١).
 *
 * الحالة النهائيّة لا يُكتب فوقها: تسليمٌ مكرّر من الطابور أو طلبٌ ثانٍ
 * يقرأ النتيجة المحفوظة ولا يُنفّذ ثانيةً.
 */
class OperationRecord extends Model
{
    protected $table = 'operation_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['SUCCEEDED', 'FAILED'], true);
    }

    /**
     * النتيجة مجهولة: نُودي المزوّد ولم يُعرف جوابه.
     *
     * هذه ليست حالةً وسطى تجميليّة — هي **المنع**: لا يُعاد الفعل وهي
     * قائمة، لأنّ إعادة ما قد يكون نجح تُنفّذه مرّتين.
     */
    public function isUncertain(): bool
    {
        return $this->status === 'UNCERTAIN';
    }
}
