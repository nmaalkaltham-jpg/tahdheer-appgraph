<?php

declare(strict_types=1);

namespace App\Domain\Cases;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Cases\Models\CaseEvent;
use App\Domain\Cases\Models\WorkCase;
use App\Domain\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * آلة حالات القضيّة — واحدةٌ للاحتيال والحوادث والدعم (§١٩ §٢٨ §٥٤).
 *
 * ─── الترتيب الملزم ───
 *
 *   اقفل · تحقّق · عدّل · دقّق · أصدر · التزم.
 *
 * وهو ترتيب `ShipmentTransitionService` نفسه: آلةُ حالاتٍ ثانية بقواعد
 * أخرى تعني أنّ فعلًا واحدًا يتصرّف تصرّفين حسب الشاشة التي بدأ منها.
 *
 * ─── لماذا الخريطة هنا لا في وحدة التحكّم ───
 *
 * الانتقال المسموح قرارُ نطاق. ووضعُه في وحدة التحكّم يجعل كلّ مستدعٍ آخر
 * — مهمّة، أو قاعدة آليّة، أو عمليّة جماعيّة — يتجاوزه.
 */
final class CaseService
{
    /**
     * ما يُسمح الانتقال منه إلى كلّ حالة.
     *
     *   NEW → ASSIGNED → INVESTIGATING → WAITING → RESOLVED → CLOSED
     *
     * و`CLOSED` من `RESOLVED` وحدها: بينهما **التحقّق**. من يعالج عيبًا
     * ماليًّا ليس من يشهد أنّه عولج، وطيُّ الخطوتين يجعل الإغلاق ادّعاءً.
     */
    private const TRANSITIONS = [
        'ASSIGNED' => ['NEW'],
        'INVESTIGATING' => ['NEW', 'ASSIGNED', 'WAITING'],
        'WAITING' => ['ASSIGNED', 'INVESTIGATING'],
        /*
         * الحسم من التحقيق لا من الفتح مباشرةً: إعلانُ نتيجةٍ قبل النظر هو
         * الطريق إلى تجاهل الإنذارات كلّها.
         */
        'RESOLVED' => ['ASSIGNED', 'INVESTIGATING', 'WAITING'],
        'CLOSED' => ['RESOLVED'],
    ];

    /**
     * رموز القرار المسموحة عند الإغلاق — قائمةٌ مغلقة في النطاق.
     *
     * ─── لماذا هنا لا في الواجهة ───
     *
     * رمزُ القرار هو ما يُقرأ بعد شهرٍ حين تتكرّر القضيّة نفسها، وما تُبنى
     * عليه تقارير «كم منها كان إنذارًا كاذبًا». ونصٌّ حرّ يُنتج عشرين صياغةً
     * للمعنى الواحد فلا يُجمَع منها شيء.
     *
     * والقائمة تُرسَل مع القضيّة: الواجهة تعرض ولا تُعرّف.
     */
    public const RESOLUTION_CODES = [
        'CONFIRMED_ABUSE' => 'إساءةٌ مؤكّدة',
        'NOT_ABUSE' => 'ليست إساءة',
        'DEFECT_FIXED' => 'عُولج العيب',
        'PROVIDER_RECOVERED' => 'عاد المزوّد',
        'DATA_CORRECTED' => 'صُحّحت البيانات',
        'DUPLICATE' => 'مكرّرة',
        'NO_ACTION_NEEDED' => 'لا إجراء لازم',
        // «لم يكن شيئًا» نتيجةُ تحقيقٍ لا مرحلةٌ منه — فهي رمزٌ لا حالة.
        'FALSE_POSITIVE' => 'إنذار كاذب',
    ];

    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @return list<string> */
    public static function allowedFrom(string $state): array
    {
        return array_keys(array_filter(
            self::TRANSITIONS,
            static fn (array $from) => in_array($state, $from, true),
        ));
    }

    /**
     * يفتح قضيّةً أو يُعيد المفتوحة بالمفتاح نفسه.
     *
     * ─── لماذا الخمول هنا ضرورة لا رفاهية ───
     *
     * العطل يتكرّر: مزوّدٌ يسقط فيُنتج خمسمئة خطأ في دقيقة. وقضيّةٌ لكلّ
     * خطأ تُغرق الشاشة فيصير الإنذار ضجيجًا يُتجاهَل — وهو أسوأ من غياب
     * الإنذار. والتكرار يُلتقط بالفهرس الفريد لا بفحصٍ قبل الإدراج.
     *
     * @param  list<string>  $reasonCodes
     * @return array{0: WorkCase, 1: bool} [القضيّة، جديدة؟]
     */
    public function open(
        string $kind,
        string $title,
        array $reasonCodes = [],
        string $severity = 'WARNING',
        ?string $dedupeKey = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $customerId = null,
        ?AdminUser $actor = null,
    ): array {
        if ($dedupeKey !== null) {
            $existing = WorkCase::query()
                ->where('kind', $kind)
                ->where('recurrence_key', $dedupeKey)
                ->whereNotIn('state', WorkCase::TERMINAL)
                ->first();

            if ($existing !== null) {
                $this->append($existing, $actor, 'recurred', null, null, 'تكرّر الشرط نفسه.');

                return [$existing, false];
            }
        }

        try {
            return [$this->create($kind, $title, $reasonCodes, $severity, $dedupeKey,
                $subjectType, $subjectId, $customerId, $actor), true];
        } catch (QueryException $e) {
            // خسر السباق: الفهرس الفريد هو ما التقطه، لا فحصٌ كان سيفوته.
            $winner = WorkCase::query()
                ->where('kind', $kind)->where('recurrence_key', $dedupeKey)
                ->whereNotIn('state', WorkCase::TERMINAL)->first();

            if ($winner === null) {
                throw $e;
            }

            return [$winner, false];
        }
    }

    /** @param list<string> $reasonCodes */
    private function create(
        string $kind, string $title, array $reasonCodes, string $severity, ?string $dedupeKey,
        ?string $subjectType, ?int $subjectId, ?int $customerId, ?AdminUser $actor,
    ): WorkCase {
        return DB::transaction(function () use (
            $kind, $title, $reasonCodes, $severity, $dedupeKey, $subjectType, $subjectId, $customerId, $actor
        ) {
            $due = CaseSla::dueFrom($severity, CarbonImmutable::now());

            $case = WorkCase::query()->create([
                'kind' => $kind,
                'state' => 'NEW',
                'severity' => $severity,
                'title' => mb_substr($title, 0, 191),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'customer_id' => $customerId,
                'reason_codes' => $reasonCodes,
                'recurrence_key' => $dedupeKey,
                /*
                 * المهل تُحفظ لا تُحسب عند القراءة: تعديل السياسة غدًا لا
                 * يُعيد كتابة حكم أمس، فيبقى قياس الأداء ذا معنى.
                 */
                'first_response_due_at' => $due['firstResponse'],
                'resolution_due_at' => $due['resolution'],
                'correlation_id' => (string) Str::uuid(),
                'opened_by_admin_user_id' => $actor?->getKey(),
                'opened_at' => now(),
            ]);

            $this->append($case, $actor, 'opened', null, 'OPEN', null, ['reasonCodes' => $reasonCodes]);

            $this->outbox->write(
                aggregateType: 'case',
                aggregateId: (string) $case->id,
                eventType: 'case.opened',
                payload: ['caseId' => $case->id, 'kind' => $kind, 'severity' => $severity],
                idempotencyKey: "case:{$case->id}:opened",
                correlationId: $case->correlation_id,
            );

            return $case;
        });
    }

    /**
     * ينقل القضيّة تحت قفل صفّها.
     *
     * الإغلاق يشترط رمز قرار: قضيّةٌ تُغلق بلا سببٍ مكتوب لا تُعلّم أحدًا
     * شيئًا، وتُفتح مثلُها بعد شهر فيُبدأ التحقيق من الصفر.
     *
     * @throws CaseTransitionRefused
     */
    public function transition(
        int $caseId,
        string $to,
        ?AdminUser $actor = null,
        ?string $note = null,
        ?string $resolutionCode = null,
        ?string $rootCause = null,
        ?string $preventiveAction = null,
    ): WorkCase {
        return DB::transaction(function () use ($caseId, $to, $actor, $note, $resolutionCode, $rootCause, $preventiveAction) {
            /** @var WorkCase $case */
            $case = WorkCase::query()->whereKey($caseId)->lockForUpdate()->firstOrFail();

            $from = (string) $case->state;
            $allowedFrom = self::TRANSITIONS[$to] ?? null;

            if ($allowedFrom === null || ! in_array($from, $allowedFrom, true)) {
                throw new CaseTransitionRefused($from, $to, self::allowedFrom($from));
            }

            $closing = in_array($to, WorkCase::SETTLED, true);

            /*
             * الإغلاق يشترط رمزًا **من القائمة**: رمزٌ مخترع يمرّ اليوم ثمّ
             * لا يُجمَع في أيّ تقرير غدًا، فيصير السجلّ نصًّا لا بيانات.
             */
            /*
             * الحسم يشترط رمزًا **من القائمة**: رمزٌ مخترع يمرّ اليوم ثمّ لا
             * يُجمَع في أيّ تقرير غدًا، فيصير السجلّ نصًّا لا بيانات.
             *
             * والإغلاق لا يُعيد طلبه: الرمز وُضع عند الحسم، والتحقّق يشهد
             * عليه ولا يستبدله.
             */
            if ($to === 'RESOLVED' && ! array_key_exists((string) $resolutionCode, self::RESOLUTION_CODES)) {
                throw new CaseTransitionRefused($from, $to, self::allowedFrom($from));
            }

            /*
             * أوّل خروجٍ من `NEW` هو **أوّل استجابة**: أن ينظر إنسان. ولا
             * تُسجَّل ثانيةً — المقياس هو متى بدأ لا كم مرّةً تحرّك.
             */
            $firstResponded = $case->first_responded_at ?? ($from === 'NEW' ? now() : null);

            $case->forceFill([
                'state' => $to,
                'first_responded_at' => $firstResponded,
                // الحسم يضع الوقت والرمز، والإغلاق يُبقيهما: التحقّق لا يُعيد الحسم.
                'resolved_at' => $closing ? ($case->resolved_at ?? now()) : null,
                'resolution_code' => $closing ? ($resolutionCode ?? $case->resolution_code) : null,
                'resolution_note' => $closing ? ($note ?? $case->resolution_note) : $case->resolution_note,
                /*
                 * خطوة التعلّم: لماذا وقع، وما يمنع تكراره.
                 *
                 * تُكتب عند الحسم وتبقى عند الإغلاق. وقضيّةٌ تُغلق بلا سببٍ
                 * جذريّ تُغلق الحادثة ولا تُغلق البابَ الذي دخلت منه.
                 */
                'root_cause' => $rootCause ?? $case->root_cause,
                'preventive_action' => $preventiveAction ?? $case->preventive_action,
            ])->save();

            $this->append($case, $actor, 'transitioned', $from, $to, $note);

            $this->audit->log(
                $actor, 'case.transitioned', 'case', $case->id,
                ['state' => $from], ['state' => $to, 'resolutionCode' => $resolutionCode],
                $note, null,
            );

            $this->outbox->write(
                aggregateType: 'case',
                aggregateId: (string) $case->id,
                eventType: 'case.transitioned',
                payload: ['caseId' => $case->id, 'from' => $from, 'to' => $to],
                idempotencyKey: "case:{$case->id}:{$to}",
                correlationId: $case->correlation_id,
            );

            return $case;
        });
    }

    /**
     * يُسنِد القضيّة.
     *
     * الإسناد لا ينقل الحالة: «من ينظر فيها» و«أين وصلت» سؤالان مختلفان،
     * ودمجُهما يجعل تغيير المسؤول يبدو تقدّمًا في التحقيق.
     */
    public function assign(int $caseId, ?int $adminUserId, ?AdminUser $actor = null): WorkCase
    {
        return DB::transaction(function () use ($caseId, $adminUserId, $actor) {
            /** @var WorkCase $case */
            $case = WorkCase::query()->whereKey($caseId)->lockForUpdate()->firstOrFail();

            $before = $case->assigned_to_admin_user_id;

            /*
             * الإسناد استجابةٌ أيضًا: أن يتولّاها أحدٌ هو ما تقيسه «أوّل
             * استجابة». وقصرُها على تغيير الحالة كان سيَعدّ قضيّةً أُسندت
             * فورًا متأخّرةً حتّى يضغط مسؤولُها زرًّا ثانيًا.
             */
            $case->forceFill([
                'assigned_to_admin_user_id' => $adminUserId,
                'first_responded_at' => $case->first_responded_at
                    ?? ($adminUserId === null ? null : now()),
            ])->save();

            $this->append($case, $actor, 'assigned', null, null, null, [
                'from' => $before, 'to' => $adminUserId,
            ]);

            /*
             * انتقالُ الحالة يمرّ بالخريطة نفسها: كان يُكتب هنا مباشرةً بلا
             * تدقيقٍ ولا حدثٍ صادر، فيختلف ما يراه السجلّ عمّا يراه الجدول.
             */
            if ($case->state === 'NEW' && $adminUserId !== null) {
                return $this->transition($case->id, 'ASSIGNED', $actor);
            }

            return $case;
        });
    }

    /** ملاحظةٌ في الخيط — تُلحق ولا تُعدّل. */
    public function note(int $caseId, string $note, ?AdminUser $actor = null): CaseEvent
    {
        $case = WorkCase::query()->findOrFail($caseId);

        return $this->append($case, $actor, 'noted', null, null, $note);
    }

    /** @param array<string, mixed>|null $evidence */
    private function append(
        WorkCase $case, ?AdminUser $actor, string $action,
        ?string $from = null, ?string $to = null, ?string $note = null, ?array $evidence = null,
    ): CaseEvent {
        return CaseEvent::query()->create([
            'case_id' => $case->id,
            'actor_admin_user_id' => $actor?->getKey(),
            'action' => $action,
            'from_state' => $from,
            'to_state' => $to,
            'note' => $note === null ? null : mb_substr($note, 0, 2000),
            'evidence' => $evidence,
            'created_at' => now(),
        ]);
    }
}
