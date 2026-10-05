<?php

declare(strict_types=1);

namespace App\Domain\Cases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قضيّة: احتيالٌ أو حادثةٌ أو دعم — بحالةٍ واحدة وخيطٍ واحد.
 *
 * الاسم `WorkCase` لا `Case`: الأخيرة كلمةٌ محجوزة في PHP، وصنفٌ باسمها
 * لا يُحمَّل أصلًا.
 */
class WorkCase extends Model
{
    protected $table = 'cases';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reason_codes' => 'array',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'breached_at' => 'datetime',
        ];
    }

    /**
     * `CLOSED` وحدها نهائيّة.
     *
     * و`RESOLVED` ليست نهايةً بل **انتظار تحقّق**: من يعالج عيبًا ماليًّا ليس
     * من يشهد أنّه عولج، وطيُّ الخطوتين يجعل الإغلاق ادّعاءً لا إثباتًا.
     */
    public const TERMINAL = ['CLOSED'];

    /** ما يُعدّ «مُغلقًا» لأغراض المهل: لا مهلة تجري على محسوم. */
    public const SETTLED = ['RESOLVED', 'CLOSED'];

    public function isTerminal(): bool
    {
        return in_array((string) $this->state, self::TERMINAL, true);
    }

    public function isSettled(): bool
    {
        return in_array((string) $this->state, self::SETTLED, true);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CaseEvent::class, 'case_id')->orderBy('created_at');
    }
}
