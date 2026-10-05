<?php

declare(strict_types=1);

namespace Tests\Feature\Cases;

use App\Domain\Admin\AdminRole;
use App\Domain\Cases\CaseRemediation;
use App\Domain\Cases\CaseService;
use App\Domain\Cases\Models\WorkCase;
use App\Domain\Cases\RemediationRefused;
use App\Domain\Operations\Jobs\ReplayWebhookDeliveryJob;
use App\Domain\Operations\Models\OperationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * أفعال الإصلاح: مضبوطة، خاملة التكرار، مُدقَّقة.
 *
 * كان الفرق يحمل `repairAction` **اسمًا** لا أكثر، فيقرؤه المشغّل ثمّ يبحث
 * عن الموضوع في شاشةٍ أخرى — إن عرف أين. فتُفتح تذكرةٌ إلى مهندس، وهو ما
 * وُجدت القضيّة لتمنعه.
 */
class CaseRemediationTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private CaseRemediation $remediation;

    private CaseService $cases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->remediation = app(CaseRemediation::class);
        $this->cases = app(CaseService::class);
    }

    private function delivery(): int
    {
        return (int) DB::table('webhook_deliveries')->insertGetId([
            'provider' => 'fake', 'dedupe_key' => 'd-'.uniqid(),
            'signature_verified' => true, 'succeeded' => true, 'status' => 'FAILED',
            'payload' => json_encode(['charge_id' => 'chg_x']), 'attempts' => 1, 'replay_count' => 0,
            'last_error' => 'تعذّر', 'received_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function webhookCase(): WorkCase
    {
        [$case] = $this->cases->open(
            'INCIDENT', 'خطّاف فشل', ['PROVIDER_SUCCESS_LOCAL_FAILURE', 'REPAIR:REPLAY_WEBHOOK'],
            'CRITICAL', 'k-'.uniqid(), 'webhook_delivery', $this->delivery(),
        );

        return $case;
    }

    // ── ما يُتاح ─────────────────────────────────────────────────────────

    public function test_the_available_actions_come_from_the_reason_codes(): void
    {
        $case = $this->webhookCase();

        $actions = collect($this->remediation->availableFor($case))->pluck('action')->all();

        $this->assertContains('REPLAY_WEBHOOK', $actions);
        // والتسوية متاحةٌ لكلّ حادثة: قراءةٌ تُجيب «هل عولج؟».
        $this->assertContains('RECONCILE', $actions);
    }

    public function test_an_advisory_action_is_declared_but_not_executable(): void
    {
        [$case] = $this->cases->open(
            'INCIDENT', 'فرق مبلغ', ['AMOUNT_MISMATCH', 'REPAIR:REVIEW_WITH_PROVIDER'],
            'CRITICAL', 'k-'.uniqid(),
        );

        $review = collect($this->remediation->availableFor($case))->firstWhere('action', 'REVIEW_WITH_PROVIDER');

        /*
         * «مراجعة مع المزوّد» محادثةٌ لا زرّ. وعرضُه زرًّا يكذب: يُنقر فلا
         * يقع شيء، ثمّ لا يُصدَّق أيّ زرٍّ بعده.
         */
        $this->assertFalse($review['executable']);
    }

    public function test_an_unavailable_action_is_refused(): void
    {
        $case = $this->webhookCase();

        $this->expectException(RemediationRefused::class);
        $this->remediation->execute($case, 'RETRY_FULFILLMENT', 'key-aaaaaaaa');
    }

    public function test_an_advisory_action_cannot_be_executed(): void
    {
        [$case] = $this->cases->open(
            'INCIDENT', 'فرق', ['REPAIR:REVIEW_WITH_PROVIDER'], 'HIGH', 'k-'.uniqid(),
        );

        $this->expectException(RemediationRefused::class);
        $this->remediation->execute($case, 'REVIEW_WITH_PROVIDER', 'key-bbbbbbbb');
    }

    public function test_a_closed_case_cannot_be_remediated(): void
    {
        $case = $this->webhookCase();
        $this->cases->transition($case->id, 'INVESTIGATING');
        $this->cases->transition($case->id, 'RESOLVED', null, 'حُسمت', 'DEFECT_FIXED');
        $this->cases->transition($case->id, 'CLOSED', null, 'تُحقّق منه');

        // الإصلاح بعد الشهادة يُبطل الشهادة.
        $this->expectException(RemediationRefused::class);
        $this->remediation->execute($case->refresh(), 'REPLAY_WEBHOOK', 'key-cccccccc');
    }

    // ── الخمول ───────────────────────────────────────────────────────────

    public function test_a_repeated_click_queues_the_job_once(): void
    {
        Queue::fake();
        $case = $this->webhookCase();

        $first = $this->remediation->execute($case, 'REPLAY_WEBHOOK', 'key-dddddddd');
        $second = $this->remediation->execute($case->refresh(), 'REPLAY_WEBHOOK', 'key-dddddddd');

        /*
         * إعادة تشغيل خطّافٍ أثرٌ خارجيّ. والخمول هنا الفرق بين إصلاحٍ
         * ومضاعفةِ عطل.
         */
        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['operationId'], $second['operationId']);
        Queue::assertPushed(ReplayWebhookDeliveryJob::class, 1);
    }

    public function test_a_deliberate_second_attempt_uses_a_new_key(): void
    {
        Queue::fake();
        $case = $this->webhookCase();

        $this->remediation->execute($case, 'REPLAY_WEBHOOK', 'key-eeeeeeee');
        $second = $this->remediation->execute($case->refresh(), 'REPLAY_WEBHOOK', 'key-ffffffff');

        // محاولةٌ ثانية مقصودة مسموحة؛ النقرة المكرّرة ليست كذلك.
        $this->assertFalse($second['duplicate']);
        Queue::assertPushed(ReplayWebhookDeliveryJob::class, 2);
    }

    public function test_the_remediation_is_written_to_the_thread_and_audited(): void
    {
        Queue::fake();
        $case = $this->webhookCase();

        $this->remediation->execute($case, 'REPLAY_WEBHOOK', 'key-gggggggg', null, 'أعدتُ التشغيل');

        $event = $case->events()->where('action', 'remediated')->firstOrFail();

        $this->assertSame('REPLAY_WEBHOOK', $event->evidence['remediation']);
        $this->assertSame('أعدتُ التشغيل', $event->note);
        $this->assertSame(1, DB::table('admin_audit_log')->where('action', 'case.remediated')->count());
    }

    public function test_reconcile_reports_the_current_state_without_changing_anything(): void
    {
        $case = $this->webhookCase();
        $before = DB::table('orders')->count();

        $result = $this->remediation->execute($case, 'RECONCILE', 'key-hhhhhhhh');

        // قراءةٌ تُجيب «هل عولج؟» — وبدونها يُغلق المشغّل القضيّة على ظنّ.
        $this->assertArrayHasKey('clean', $result['detail']);
        $this->assertSame($before, DB::table('orders')->count());
    }

    // ── خطوة التعلّم ─────────────────────────────────────────────────────

    public function test_resolution_records_the_root_cause_and_the_preventive_action(): void
    {
        $case = $this->webhookCase();
        $this->cases->transition($case->id, 'INVESTIGATING');

        $resolved = $this->cases->transition(
            $case->id, 'RESOLVED', null, 'أُعيد التشغيل', 'DEFECT_FIXED',
            'مهلة المزوّد انتهت أثناء المعالجة', 'رفع المهلة ومحاولتان',
        );

        /*
         * قضيّةٌ تُغلق بلا سببٍ جذريّ تُغلق الحادثة ولا تُغلق البابَ الذي
         * دخلت منه.
         */
        $this->assertSame('مهلة المزوّد انتهت أثناء المعالجة', $resolved->root_cause);
        $this->assertSame('رفع المهلة ومحاولتان', $resolved->preventive_action);

        // وتبقى عند الإغلاق: التحقّق يشهد ولا يمحو.
        $closed = $this->cases->transition($case->id, 'CLOSED', null, 'تُحقّق منه');
        $this->assertSame('رفع المهلة ومحاولتان', $closed->preventive_action);
    }

    // ── العقد ────────────────────────────────────────────────────────────

    public function test_the_endpoint_requires_operations_manage(): void
    {
        $case = $this->webhookCase();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $this->postJson("/api/v1/admin/cases/{$case->id}/remediations", [
            'action' => 'RECONCILE', 'idempotencyKey' => 'key-iiiiiiii',
        ])->assertStatus(403);
    }

    public function test_the_endpoint_returns_what_is_available_when_refusing(): void
    {
        $case = $this->webhookCase();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson("/api/v1/admin/cases/{$case->id}/remediations", [
            'action' => 'RETRY_FULFILLMENT', 'idempotencyKey' => 'key-jjjjjjjj',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'REMEDIATION_REFUSED')
            ->assertJsonPath('available.0.action', 'REPLAY_WEBHOOK');
    }

    public function test_the_detail_carries_the_available_remediations(): void
    {
        $case = $this->webhookCase();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson("/api/v1/admin/cases/{$case->id}")
            ->assertOk()
            ->assertJsonPath('data.remediations.0.action', 'REPLAY_WEBHOOK')
            ->assertJsonPath('data.remediations.0.executable', true);
    }

    public function test_a_queued_remediation_is_not_sealed_before_its_job_runs(): void
    {
        Queue::fake();
        $case = $this->webhookCase();

        $result = $this->remediation->execute($case, 'REPLAY_WEBHOOK', 'key-iiiiiiii');

        /*
         * كان الطلب يختم العمليّة «ناجحة» فورًا، فتجدها المهمّة منتهيةً وتخرج
         * بلا عمل — والسجلّ يقول إنّ الخطّاف أُعيد ولم يقع شيء.
         */
        $this->assertNotSame('SUCCEEDED', $result['outcome']);
        $this->assertSame('RESERVED', OperationRecord::query()->findOrFail($result['operationId'])->status);
        Queue::assertPushed(ReplayWebhookDeliveryJob::class, fn ($job) => $job->operationId === $result['operationId']);
    }
}
