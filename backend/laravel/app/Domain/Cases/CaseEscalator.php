<?php

declare(strict_types=1);

namespace App\Domain\Cases;

use App\Domain\Cases\Models\CaseEvent;
use App\Domain\Cases\Models\WorkCase;
use App\Domain\Outbox\OutboxWriter;
use Illuminate\Support\Facades\DB;

/**
 * يرصد خرق المهل ويرفع التصعيد.
 *
 * ─── العيب الذي يصلحه ───
 *
 * القضيّة الحرجة بلا مسؤولٍ كانت تجلس إلى الأبد ولا شيء يلاحظ. و«مفتوحة»
 * حالةٌ لا تقول متى كان يجب أن يُردّ عليها، فلا يُعرف المتأخّر من الجاري.
 *
 * ─── لماذا التصعيد درجات لا راية ───
 *
 * رايةٌ واحدة «مخروقة» تجعل قضيّةً تأخّرت دقيقةً كقضيّةٍ تأخّرت أسبوعًا.
 * والدرجة ترتفع مع الزمن، فتفرز القائمة نفسها ما يحتاج تدخّلًا أعلى.
 *
 * ─── ولماذا لا يُغلق ولا يُسنِد شيئًا ───
 *
 * التصعيد يجعل التأخّر **مرئيًّا**؛ ولا يُسند القضيّة إلى أحد ولا يرفع
 * حدّتها. إسنادٌ آليّ يضع مسؤوليّةً على من لم يقبلها، ورفعُ الحدّة يُفسد
 * المقياس الذي بُنيت عليه المهلة أصلًا.
 */
final class CaseEscalator
{
    /** كلّ ضعفِ المهلة يرفع درجة — والسقف يمنع رقمًا بلا معنى. */
    private const MAX_LEVEL = 3;

    public function __construct(private readonly OutboxWriter $outbox) {}

    /**
     * @return array{scanned: int, breached: int, escalated: int}
     */
    public function run(int $limit = 500): array
    {
        $now = now();
        $breached = 0;
        $escalated = 0;

        $candidates = WorkCase::query()
            ->whereNotIn('state', WorkCase::SETTLED)
            ->where(function ($q) use ($now) {
                $q->where(fn ($inner) => $inner->whereNull('first_responded_at')
                    ->whereNotNull('first_response_due_at')
                    ->where('first_response_due_at', '<', $now))
                    ->orWhere(fn ($inner) => $inner->whereNotNull('resolution_due_at')
                        ->where('resolution_due_at', '<', $now));
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($candidates as $case) {
            $level = $this->levelFor($case, $now);

            if ($level === (int) $case->escalation_level && $case->breached_at !== null) {
                continue; // لا تغيير — لا حدث ولا ضجيج
            }

            DB::transaction(function () use ($case, $level, $now, &$breached, &$escalated) {
                /** @var WorkCase $locked */
                $locked = WorkCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

                if ($locked->isSettled()) {
                    return; // حُسمت بين الفحص والقفل
                }

                $wasBreached = $locked->breached_at !== null;

                $locked->forceFill([
                    // أوّل خرقٍ يُختم مرّةً: وقتُ الخرق حقيقةٌ لا تتكرّر.
                    'breached_at' => $locked->breached_at ?? $now,
                    'escalation_level' => $level,
                ])->save();

                CaseEvent::query()->create([
                    'case_id' => $locked->id,
                    'actor_admin_user_id' => null,
                    'action' => $wasBreached ? 'escalated' : 'breached',
                    'note' => $this->reason($locked, $now),
                    'evidence' => ['escalationLevel' => $level, 'unassigned' => $locked->assigned_to_admin_user_id === null],
                    'created_at' => $now,
                ]);

                $this->outbox->write(
                    aggregateType: 'case',
                    aggregateId: (string) $locked->id,
                    eventType: $wasBreached ? 'case.escalated' : 'case.sla_breached',
                    payload: [
                        'caseId' => $locked->id,
                        'severity' => $locked->severity,
                        'escalationLevel' => $level,
                        'assigned' => $locked->assigned_to_admin_user_id !== null,
                    ],
                    idempotencyKey: "case:{$locked->id}:escalation:{$level}",
                    correlationId: $locked->correlation_id,
                );

                $wasBreached ? $escalated++ : $breached++;
            });
        }

        return ['scanned' => $candidates->count(), 'breached' => $breached, 'escalated' => $escalated];
    }

    /**
     * الدرجة من مقدار التجاوز لا من وقوعه.
     *
     * تجاوزٌ بمقدار المهلة = درجة، وبضعفها = درجتان. فالقائمة تفرز نفسها.
     */
    private function levelFor(WorkCase $case, \DateTimeInterface $now): int
    {
        /*
         * الفرق يُحسب من الطوابع لا من `diffInMinutes`.
         *
         * إشارةُ `diffInMinutes` ودلالتُها تغيّرتا بين إصدارات Carbon، وقد
         * أعادت صفرًا لفارقٍ حقيقيّ مقداره عشرون دقيقة. والطابع عددٌ لا
         * يتأوّل، والتأخّر حسابٌ تُبنى عليه إنذارات.
         */
        $overdue = 0;

        if ($case->first_responded_at === null && $case->first_response_due_at !== null) {
            $overdue = max($overdue, self::minutesLate($case->first_response_due_at, $now));
        }

        if ($case->resolution_due_at !== null) {
            $overdue = max($overdue, self::minutesLate($case->resolution_due_at, $now));
        }

        if ($overdue <= 0) {
            return (int) $case->escalation_level;
        }

        $window = max(1, $this->windowMinutes($case));

        return (int) min(self::MAX_LEVEL, 1 + intdiv((int) $overdue, $window));
    }

    /** دقائق التأخّر عن موعدٍ — سالبها صفر. */
    private static function minutesLate(\DateTimeInterface $due, \DateTimeInterface $now): int
    {
        return (int) max(0, intdiv($now->getTimestamp() - $due->getTimestamp(), 60));
    }

    private function windowMinutes(WorkCase $case): int
    {
        $targets = CaseSla::targets()[$case->severity] ?? CaseSla::targets()['WARNING'];

        return (int) ($targets[0] ?? 60);
    }

    private function reason(WorkCase $case, \DateTimeInterface $now): string
    {
        if ($case->first_responded_at === null) {
            /*
             * الحرجة بلا مسؤول هي الحالة التي وُجد هذا كلّه لأجلها: تجلس
             * ولا أحد يعلم أنّها تجلس.
             */
            return 'لم تُستجَب بعد'.($case->assigned_to_admin_user_id === null ? ' ولا مسؤول لها' : '');
        }

        return 'تجاوزت مهلة الحسم';
    }
}
