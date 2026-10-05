<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Outbox\OutboxRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * التشغيل والطوابير — والفرق بين «فشل» و«لا أحد يستمع».
 *
 * صندوق الصادر كان يعمل بلا سطحٍ يقول ماذا يجري فيه، فبقي عطلان صامتان:
 * حدثٌ يموت بعد خمس محاولات فلا يعلم أحد، ونوعُ حدثٍ بلا مستهلك يُعالَج
 * «بنجاح» ولا يفعل شيئًا — والثاني أخبث لأنّ العدّادات تبقى خضراء.
 */
class SystemHealthTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OutboxRegistry::flush();
    }

    private function event(array $overrides = []): OutboxEvent
    {
        return OutboxEvent::create($overrides + [
            'aggregate_type' => 'order',
            'aggregate_id' => '1',
            'event_type' => 'order.paid',
            'payload' => ['x' => 1],
            'idempotency_key' => 'k-'.uniqid(),
            'status' => 'PENDING',
            'attempts' => 0,
        ]);
    }

    // ── الصلاحيات ────────────────────────────────────────────────────────

    public function test_health_requires_audit_read(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));
        $this->getJson('/api/v1/admin/system')->assertStatus(403);
    }

    public function test_replay_requires_the_system_permission(): void
    {
        $event = $this->event(['status' => 'DEAD', 'attempts' => 5]);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));

        // موظّف الطلبات يدير التنفيذ ولا يُعيد بثّ أحداث النطاق.
        $this->postJson("/api/v1/admin/system/outbox/{$event->id}/replay")->assertStatus(403);
    }

    // ── القياس ───────────────────────────────────────────────────────────

    public function test_health_counts_every_outbox_state(): void
    {
        $this->event(['status' => 'PENDING']);
        $this->event(['status' => 'PROCESSED', 'processed_at' => now()]);
        $this->event(['status' => 'DEAD', 'attempts' => 5]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/system')
            ->assertOk()
            ->assertJsonPath('outbox.pending', 1)
            ->assertJsonPath('outbox.processed', 1)
            ->assertJsonPath('outbox.dead', 1);
    }

    public function test_an_empty_outbox_reports_no_oldest_instead_of_zero(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        /*
         * صفرٌ هنا يعني «أقدم معلَّقٍ عمرُه صفر» — أي أنّ ثمّة معلَّقًا وصل
         * للتوّ. و`null` تعني «لا معلَّق». الخلط يُقلق المشغّل بلا سبب.
         */
        $this->getJson('/api/v1/admin/system')
            ->assertOk()
            ->assertJsonPath('outbox.oldestPendingSeconds', null)
            ->assertJsonPath('outbox.stalled', false);
    }

    public function test_an_old_pending_event_marks_the_outbox_stalled(): void
    {
        $event = $this->event(['status' => 'PENDING']);
        OutboxEvent::query()->whereKey($event->id)->update(['created_at' => now()->subHour()]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // المجدول يُصرّف كلّ دقيقة؛ ساعةٌ من التعليق تعني أنّه لا يعمل.
        $this->getJson('/api/v1/admin/system')
            ->assertOk()
            ->assertJsonPath('outbox.stalled', true);
    }

    public function test_the_queue_is_measured_under_redis(): void
    {
        config(['queue.default' => 'redis']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        /*
         * كان هذا يحرس «غير مقيس» تحت Redis — وهو سائق الإنتاج. أي أنّ العمق
         * كان يُخفى في البيئة الوحيدة التي يهمّ فيها، بينما لوحة الذكاء تعرض
         * رقمًا من المخزن نفسه: شاشتان تتناقضان في السؤال نفسه.
         *
         * وLaravel يخزّن الطابور في قائمةٍ ومجموعتين مرتّبتين، والثلاث معًا
         * هي العمق. `QueueDepth` يقرؤها لكلّ من يسأل.
         */
        $this->getJson('/api/v1/admin/system')
            ->assertOk()
            ->assertJsonPath('queue.status', 'MEASURED')
            ->assertJsonPath('queue.failed', 0)
            ->assertJsonStructure(['queue' => ['driver', 'status', 'pending', 'failed', 'monitored']]);
    }

    public function test_an_uncountable_driver_still_declares_itself(): void
    {
        config(['queue.default' => 'sqs']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // ما لا يُعرف عدّه يُقال — لا يُرَدّ صفرًا يُقرأ «لا شيء عالق».
        $this->getJson('/api/v1/admin/system')
            ->assertOk()
            ->assertJsonPath('queue.status', 'NOT_MEASURABLE')
            ->assertJsonPath('queue.pending', null);
    }

    public function test_an_event_type_without_a_consumer_is_reported(): void
    {
        $this->event(['event_type' => 'nobody.listens']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $response = $this->getJson('/api/v1/admin/system')->assertOk();

        /*
         * هذا هو الاكتشاف الذي تُوجد الشاشة لأجله: حدثٌ يُكتب ويُعالَج
         * «بنجاح» ولا يفعل شيئًا، والعدّادات خضراء طوال الوقت.
         */
        $row = collect($response->json('consumers'))->firstWhere('eventType', 'nobody.listens');
        $this->assertSame(0, $row['consumers']);
        $this->assertSame(1, $row['written']);
    }

    public function test_a_registered_consumer_is_counted(): void
    {
        OutboxRegistry::register('order.paid', fn () => null);
        $this->event(['event_type' => 'order.paid']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $row = collect($this->getJson('/api/v1/admin/system')->assertOk()->json('consumers'))
            ->firstWhere('eventType', 'order.paid');

        $this->assertSame(1, $row['consumers']);
    }

    // ── الموتى والإحياء ──────────────────────────────────────────────────

    public function test_dead_events_are_listed_oldest_first(): void
    {
        $recent = $this->event(['status' => 'DEAD', 'attempts' => 5]);
        $old = $this->event(['status' => 'DEAD', 'attempts' => 5]);
        OutboxEvent::query()->whereKey($old->id)->update(['created_at' => now()->subDay()]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // الأقدم أطول تأخّرًا في أثره، فهو أوّل ما يُنظر فيه.
        $this->getJson('/api/v1/admin/system/outbox/dead')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $old->id)
            ->assertJsonPath('data.1.id', (string) $recent->id)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_a_dead_event_declares_whether_anyone_listens(): void
    {
        OutboxRegistry::register('heard.event', fn () => null);
        $this->event(['status' => 'DEAD', 'attempts' => 5, 'event_type' => 'heard.event']);
        $this->event(['status' => 'DEAD', 'attempts' => 5, 'event_type' => 'unheard.event']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $rows = collect($this->getJson('/api/v1/admin/system/outbox/dead')->assertOk()->json('data'));

        $this->assertTrue($rows->firstWhere('eventType', 'heard.event')['hasConsumer']);
        $this->assertFalse($rows->firstWhere('eventType', 'unheard.event')['hasConsumer']);
    }

    public function test_reviving_a_dead_event_returns_it_to_pending(): void
    {
        OutboxRegistry::register('order.paid', fn () => null);
        $event = $this->event(['status' => 'DEAD', 'attempts' => 5, 'next_retry_at' => now()]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson("/api/v1/admin/system/outbox/{$event->id}/replay")
            ->assertOk()
            ->assertJsonPath('status', 'PENDING');

        $fresh = $event->fresh();
        $this->assertSame('PENDING', $fresh->status);
        // العدّاد يُصفَّر وإلّا مات فورًا عند أوّل التقاط.
        $this->assertSame(0, (int) $fresh->attempts);
        $this->assertNull($fresh->next_retry_at);

        $this->assertSame(1, DB::table('admin_audit_log')
            ->where('action', 'system.outbox.replayed')->count());
    }

    public function test_reviving_an_event_without_a_consumer_is_refused(): void
    {
        $event = $this->event(['status' => 'DEAD', 'attempts' => 5, 'event_type' => 'nobody.listens']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        /*
         * سيُعالَج «بنجاح» ولن يفعل شيئًا، فيختفي من قائمة الموتى ويبدو أنّ
         * المشكلة حُلّت. والمشكلة عيبُ تسجيلٍ في الشيفرة لا عطلُ تشغيل.
         */
        $this->postJson("/api/v1/admin/system/outbox/{$event->id}/replay")
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_CONSUMER');

        $this->assertSame('DEAD', $event->fresh()->status);
    }

    public function test_reviving_a_live_event_is_refused(): void
    {
        OutboxRegistry::register('order.paid', fn () => null);
        $event = $this->event(['status' => 'PENDING']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // الحيّ يُعالَج في دوره؛ إعادتُه تعني معالجتين متوازيتين عليه.
        $this->postJson("/api/v1/admin/system/outbox/{$event->id}/replay")->assertStatus(422);
        $this->assertSame('PENDING', $event->fresh()->status);
    }

    public function test_reviving_a_missing_event_is_404(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        // معرّفٌ صالح الشكل ولا صفَّ له — لا معرّفٌ مشوّه يردّه الموجّه.
        $this->postJson('/api/v1/admin/system/outbox/'.Str::uuid().'/replay')
            ->assertStatus(404);
    }
}
