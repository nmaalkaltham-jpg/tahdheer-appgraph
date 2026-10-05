<?php

declare(strict_types=1);

namespace Tests\Feature\Cases;

use App\Domain\Cases\CaseService;
use App\Domain\Cases\CaseTransitionRefused;
use App\Domain\Cases\Models\WorkCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * القضيّة: بدائيّةٌ واحدة للاحتيال والحوادث والدعم (§١٩ §٢٨ §٥٤).
 *
 * بناؤها ثلاث مرّات كان سيُنتج ثلاث آلات حالاتٍ تتباعد، وثلاث شاشاتٍ
 * تتصرّف على نحوٍ مختلف أمام الفعل نفسه.
 */
class CaseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private CaseService $cases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cases = app(CaseService::class);
    }

    private function open(string $kind = 'FRAUD', ?string $dedupe = null): WorkCase
    {
        [$case] = $this->cases->open($kind, 'عنوان', ['SHARED_IP'], 'HIGH', $dedupe);

        return $case;
    }

    // ── الفتح ────────────────────────────────────────────────────────────

    public function test_a_new_case_opens_with_a_timeline_entry(): void
    {
        $case = $this->open();

        $this->assertSame('NEW', $case->state);
        $this->assertSame(['SHARED_IP'], $case->reason_codes);
        // الخيط يبدأ من الفتح: قضيّةٌ بلا أوّل حدثٍ تبدأ من مجهول.
        $this->assertSame('opened', $case->events()->first()->action);
    }

    public function test_the_same_condition_does_not_open_a_second_case(): void
    {
        $first = $this->open('INCIDENT', 'gateway-down');
        [$second, $isNew] = $this->cases->open('INCIDENT', 'عنوان', [], 'HIGH', 'gateway-down');

        /*
         * مزوّدٌ يسقط يُنتج خمسمئة خطأ في دقيقة. وقضيّةٌ لكلّ خطأ تُغرق
         * الشاشة فيصير الإنذار ضجيجًا يُتجاهَل — أسوأ من غياب الإنذار.
         */
        $this->assertFalse($isNew);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WorkCase::query()->count());
        // ويُسجَّل التكرار في الخيط: الابتلاع الصامت يُخفي شدّة العطل.
        $this->assertSame(1, $first->events()->where('action', 'recurred')->count());
    }

    public function test_a_resolved_but_unverified_case_still_absorbs_a_recurrence(): void
    {
        $first = $this->open('INCIDENT', 'gateway-down');
        $this->cases->transition($first->id, 'INVESTIGATING');
        $this->cases->transition($first->id, 'RESOLVED', null, 'عاد المزوّد', 'PROVIDER_RECOVERED');

        [$second, $isNew] = $this->cases->open('INCIDENT', 'عنوان', [], 'HIGH', 'gateway-down');

        /*
         * «حُسمت» ليست «أُغلقت»: بينهما التحقّق. وتكرّرُ الشرط قبل التحقّق
         * دليلٌ على أنّ الحسم لم يصمد — فيُضاف إلى خيطها لا يُفتح غيرها.
         */
        $this->assertFalse($isNew);
        $this->assertSame($first->id, $second->id);
    }

    public function test_the_same_key_opens_again_after_the_first_is_verified_and_closed(): void
    {
        $first = $this->open('INCIDENT', 'gateway-down');
        $this->cases->transition($first->id, 'INVESTIGATING');
        $this->cases->transition($first->id, 'RESOLVED', null, 'عاد المزوّد', 'PROVIDER_RECOVERED');
        $this->cases->transition($first->id, 'CLOSED', null, 'تُحقّق منه');

        [$second, $isNew] = $this->cases->open('INCIDENT', 'عنوان', [], 'HIGH', 'gateway-down');

        // العطل نفسه بعد الإغلاق حادثةٌ جديدة لا استمرارٌ للقديمة.
        $this->assertTrue($isNew);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_two_kinds_may_share_a_dedupe_key(): void
    {
        $this->cases->open('INCIDENT', 'عنوان', [], 'HIGH', 'shared');
        [, $isNew] = $this->cases->open('FRAUD', 'عنوان', [], 'HIGH', 'shared');

        // المفتاح يخصّ نوعه: «تعطّل المزوّد» و«إساءة عميل» شرطان مختلفان.
        $this->assertTrue($isNew);
    }

    // ── آلة الحالات ──────────────────────────────────────────────────────

    public function test_the_normal_path_advances_through_verification(): void
    {
        $case = $this->open();

        $this->assertSame('INVESTIGATING', $this->cases->transition($case->id, 'INVESTIGATING')->state);
        $this->assertSame('WAITING', $this->cases->transition($case->id, 'WAITING')->state);
        $this->assertSame('INVESTIGATING', $this->cases->transition($case->id, 'INVESTIGATING')->state);

        $resolved = $this->cases->transition($case->id, 'RESOLVED', null, 'حُسمت', 'CONFIRMED_ABUSE');
        $this->assertSame('RESOLVED', $resolved->state);
        $this->assertNotNull($resolved->resolved_at);

        /*
         * الإغلاق خطوةٌ مستقلّة: من يعالج عيبًا ماليًّا ليس من يشهد أنّه
         * عولج، وطيُّ الخطوتين يجعل الإغلاق ادّعاءً لا إثباتًا.
         */
        $closed = $this->cases->transition($case->id, 'CLOSED', null, 'تُحقّق منه');
        $this->assertSame('CLOSED', $closed->state);
        // والرمز لا يُستبدل عند التحقّق: وُضع عند الحسم ويبقى.
        $this->assertSame('CONFIRMED_ABUSE', $closed->resolution_code);
    }

    public function test_a_terminal_case_refuses_further_transitions(): void
    {
        $case = $this->open();
        $this->cases->transition($case->id, 'INVESTIGATING');
        $this->cases->transition($case->id, 'RESOLVED', null, null, 'CONFIRMED_ABUSE');
        $this->cases->transition($case->id, 'CLOSED');

        // العودة تُفتح قضيّةً جديدة بخيطها — لا تُبعث القديمة.
        $this->expectException(CaseTransitionRefused::class);
        $this->cases->transition($case->id, 'INVESTIGATING');
    }

    public function test_a_case_cannot_be_closed_before_it_is_resolved(): void
    {
        $case = $this->open();

        /*
         * الإغلاق شهادةٌ بأنّ الحسم صحيح. وإغلاقٌ بلا حسمٍ سابق شهادةٌ على
         * لا شيء — ثمّ لا يُصدَّق أيُّ إغلاقٍ بعده.
         */
        $this->expectException(CaseTransitionRefused::class);
        $this->cases->transition($case->id, 'CLOSED');
    }

    public function test_a_dismissal_is_a_resolution_code_not_a_state(): void
    {
        $case = $this->open();
        $this->cases->transition($case->id, 'INVESTIGATING');

        // «لم يكن شيئًا» نتيجةُ تحقيقٍ لا مرحلةٌ منه.
        $resolved = $this->cases->transition($case->id, 'RESOLVED', null, 'راجعتُه', 'FALSE_POSITIVE');

        $this->assertSame('RESOLVED', $resolved->state);
        $this->assertSame('FALSE_POSITIVE', $resolved->resolution_code);
    }

    public function test_closing_without_a_resolution_code_is_refused(): void
    {
        $case = $this->open();
        $this->cases->transition($case->id, 'INVESTIGATING');

        // قضيّةٌ تُغلق بلا سببٍ مكتوب تُفتح مثلُها بعد شهر ويُبدأ من الصفر.
        $this->expectException(CaseTransitionRefused::class);
        $this->cases->transition($case->id, 'RESOLVED');
    }

    public function test_the_refusal_names_what_was_allowed(): void
    {
        $case = $this->open();

        try {
            $this->cases->transition($case->id, 'WAITING', null, null, null);
            $this->fail('مُرّر انتقالٌ ممنوع.');
        } catch (CaseTransitionRefused $e) {
            // المشغّل يقرأ ما يستطيع فعله لا ما مُنع منه فقط.
            $this->assertSame('NEW', $e->from);
            $this->assertContains('INVESTIGATING', $e->allowed);
            $this->assertContains('ASSIGNED', $e->allowed);
        }
    }

    public function test_a_refused_transition_writes_nothing(): void
    {
        $case = $this->open();
        $events = $case->events()->count();

        try {
            $this->cases->transition($case->id, 'RESOLVED', null, null, null);
        } catch (CaseTransitionRefused) {
            // متوقّع
        }

        $this->assertSame('NEW', $case->refresh()->state);
        $this->assertSame($events, $case->events()->count());
        $this->assertSame(0, DB::table('outbox_events')
            ->where('aggregate_type', 'case')->where('event_type', 'case.transitioned')->count());
    }

    // ── الخيط ────────────────────────────────────────────────────────────

    public function test_assignment_counts_as_first_response(): void
    {
        $case = $this->open();
        $updated = $this->cases->assign($case->id, 7);

        /*
         * الإسناد استجابةٌ: أن يتولّاها أحدٌ هو ما تقيسه «أوّل استجابة».
         * وقصرُها على تغيير الحالة كان سيَعدّ قضيّةً أُسندت فورًا متأخّرةً
         * حتّى يضغط مسؤولُها زرًّا ثانيًا.
         */
        $this->assertSame(7, $updated->assigned_to_admin_user_id);
        $this->assertNotNull($updated->first_responded_at);
        $this->assertSame('ASSIGNED', $updated->state);
        $this->assertSame(1, $case->events()->where('action', 'assigned')->count());
    }

    public function test_the_timeline_records_every_step_in_order(): void
    {
        $case = $this->open();
        $this->cases->transition($case->id, 'INVESTIGATING');
        $this->cases->note($case->id, 'راجعتُ السجلّات');
        $this->cases->transition($case->id, 'RESOLVED', null, 'حُسمت', 'CONFIRMED_ABUSE');

        $actions = $case->events()->pluck('action')->all();

        $this->assertSame(['opened', 'transitioned', 'noted', 'transitioned'], $actions);
    }

    public function test_a_closed_case_cannot_be_closed_without_a_time(): void
    {
        $case = $this->open();

        // قيدٌ في المحرّك: إغلاقٌ بلا وقتٍ ولا رمز مستحيلٌ بنيويًّا.
        $this->expectException(QueryException::class);
        DB::table('cases')->where('id', $case->id)->update(['state' => 'RESOLVED']);
    }

    public function test_assignment_that_moves_the_state_is_audited_and_published(): void
    {
        $case = $this->open();

        $this->cases->assign($case->id, 7);

        // كان الانتقال يُكتب مباشرةً بلا تدقيقٍ ولا حدثٍ صادر.
        $this->assertDatabaseHas('outbox_events', ['idempotency_key' => "case:{$case->id}:ASSIGNED"]);
        $this->assertSame(1, DB::table('admin_audit_log')
            ->where('action', 'case.transitioned')->where('entity_id', $case->id)->count());
    }
}
