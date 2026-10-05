<?php

declare(strict_types=1);

namespace Tests\Feature\Cases;

use App\Domain\Cases\CaseEscalator;
use App\Domain\Cases\CaseService;
use App\Domain\Cases\Models\WorkCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المهل والتصعيد: التأخّر يُرى لا يُحسب.
 *
 * القضيّة الحرجة بلا مسؤولٍ كانت تجلس إلى الأبد ولا شيء يلاحظ. و«مفتوحة»
 * حالةٌ لا تقول متى كان يجب أن يُردّ عليها.
 */
class CaseEscalationTest extends TestCase
{
    use RefreshDatabase;

    private CaseEscalator $escalator;

    private CaseService $cases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->escalator = app(CaseEscalator::class);
        $this->cases = app(CaseService::class);
    }

    private function open(string $severity = 'CRITICAL'): WorkCase
    {
        [$case] = $this->cases->open('INCIDENT', 'عنوان', ['X'], $severity, 'k-'.uniqid());

        return $case;
    }

    private function age(WorkCase $case, int $minutes): void
    {
        // يُقدَّم الزمن بتقديم المهل: أبسط من تحريك ساعة النظام كلّها.
        DB::table('cases')->where('id', $case->id)->update([
            'first_response_due_at' => now()->subMinutes($minutes),
            'resolution_due_at' => now()->subMinutes($minutes),
        ]);
    }

    // ── المهل تُحسب من الحدّة ────────────────────────────────────────────

    public function test_severity_decides_the_deadline(): void
    {
        $critical = $this->open('CRITICAL');
        $warning = $this->open('WARNING');

        /*
         * مهلةٌ واحدة لكلّ القضايا تعني إمّا إنذارًا مستمرًّا على التافه
         * وإمّا صمتًا عن الحرج.
         */
        $this->assertTrue(
            $critical->first_response_due_at->lt($warning->first_response_due_at),
        );
    }

    public function test_an_informational_case_has_no_deadline(): void
    {
        $info = $this->open('INFO');

        // ليست دعوةً إلى فعل، وفرضُ مهلةٍ عليها يُنتج خرقًا يوميًّا بلا قصد.
        $this->assertNull($info->first_response_due_at);
        $this->assertNull($info->resolution_due_at);
    }

    public function test_an_informational_case_is_never_breached(): void
    {
        $info = $this->open('INFO');
        DB::table('cases')->where('id', $info->id)->update(['opened_at' => now()->subYear()]);

        $this->escalator->run();

        $this->assertNull($info->refresh()->breached_at);
    }

    // ── الخرق ────────────────────────────────────────────────────────────

    public function test_an_unanswered_case_past_its_deadline_is_breached(): void
    {
        $case = $this->open('CRITICAL');
        $this->age($case, 20);

        $result = $this->escalator->run();

        $this->assertSame(1, $result['breached']);
        $this->assertNotNull($case->refresh()->breached_at);
        // تجاوزٌ بمقدار المهلة (١٥د) مرّةً وزيادة ⇦ الدرجة الثانية.
        $this->assertSame(2, (int) $case->refresh()->escalation_level);
    }

    public function test_the_breach_names_the_reason_in_the_thread(): void
    {
        $case = $this->open('CRITICAL');
        $this->age($case, 20);

        $this->escalator->run();

        $event = $case->events()->where('action', 'breached')->firstOrFail();

        // الحرجة بلا مسؤول هي الحالة التي وُجد هذا كلّه لأجلها.
        $this->assertStringContainsString('لا مسؤول', (string) $event->note);
        $this->assertTrue($event->evidence['unassigned']);
    }

    public function test_a_case_answered_in_time_is_not_breached(): void
    {
        $case = $this->open('CRITICAL');
        $this->cases->assign($case->id, 7);
        $this->age($case, 20);

        $this->escalator->run();

        // الإسناد استجابة: مهلةُ الردّ انتهت بها، وتبقى مهلة الحسم وحدها.
        $fresh = $case->refresh();
        $this->assertNotNull($fresh->first_responded_at);
        $this->assertStringContainsString('الحسم', (string) $case->events()->where('action', 'breached')->first()?->note);
    }

    // ── التصعيد درجات ────────────────────────────────────────────────────

    public function test_escalation_rises_with_how_late_it_is(): void
    {
        $case = $this->open('CRITICAL');

        $this->age($case, 20);
        $this->escalator->run();
        $first = (int) $case->refresh()->escalation_level;

        // تجاوزٌ بمقدار المهلة = درجة، وبضعفها = درجتان.
        $this->age($case, 40);
        $this->escalator->run();
        $second = (int) $case->refresh()->escalation_level;

        /*
         * رايةٌ واحدة «مخروقة» تجعل قضيّةً تأخّرت دقيقةً كقضيّةٍ تأخّرت
         * أسبوعًا. الدرجة تفرز القائمة نفسها.
         */
        $this->assertGreaterThan($first, $second);
    }

    public function test_escalation_is_capped(): void
    {
        $case = $this->open('CRITICAL');
        $this->age($case, 100000);

        $this->escalator->run();

        // رقمٌ يرتفع بلا سقفٍ لا يُقرأ ولا يُفرَز به.
        $this->assertSame(3, (int) $case->refresh()->escalation_level);
    }

    public function test_the_breach_time_is_stamped_once(): void
    {
        $case = $this->open('CRITICAL');
        $this->age($case, 20);
        $this->escalator->run();
        $first = $case->refresh()->breached_at;

        $this->age($case, 40);
        $this->escalator->run();

        // وقتُ الخرق حقيقةٌ لا تتكرّر: تحديثُه يمحو متى بدأ التأخّر.
        $this->assertEquals($first, $case->refresh()->breached_at);
    }

    public function test_running_twice_without_change_emits_nothing(): void
    {
        $case = $this->open('CRITICAL');
        $this->age($case, 20);
        $this->escalator->run();

        $events = $case->events()->count();
        $second = $this->escalator->run();

        // آمنٌ للتكرار: لا حدث ولا إصدار ما لم تتغيّر الدرجة.
        $this->assertSame(0, $second['breached']);
        $this->assertSame(0, $second['escalated']);
        $this->assertSame($events, $case->events()->count());
    }

    public function test_a_settled_case_is_not_escalated(): void
    {
        $case = $this->open('CRITICAL');
        $this->cases->transition($case->id, 'INVESTIGATING');
        $this->cases->transition($case->id, 'RESOLVED', null, 'حُسمت', 'DEFECT_FIXED');
        $this->age($case, 10000);

        $this->escalator->run();

        // لا مهلة تجري على محسوم: الإنذار عليه ضجيجٌ خالص.
        $this->assertNull($case->refresh()->breached_at);
    }

    public function test_the_engine_refuses_escalation_without_a_breach(): void
    {
        $case = $this->open('CRITICAL');

        // رقمٌ يرتفع بلا سببٍ مسجَّل لا يُقرأ — والقيد يمنعه بنيويًّا.
        $this->expectException(QueryException::class);
        DB::table('cases')->where('id', $case->id)->update(['escalation_level' => 2]);
    }
}
