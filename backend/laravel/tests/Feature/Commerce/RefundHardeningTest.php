<?php

namespace Tests\Feature\Commerce;

use App\Domain\Admin\AdminRole;
use App\Domain\Cases\CaseScanner;
use App\Domain\Cases\Models\WorkCase;
use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Finance\Reconciliation;
use App\Domain\Orders\Models\Refund;
use App\Domain\Orders\RefundExceedsCaptured;
use App\Domain\Orders\RefundIdempotencyConflict;
use App\Domain\Orders\RefundService;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\RefundOutcomeUnknown;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * الاسترداد تحت الإعادة والتعارض والانهيار ويد المشغّل في لوحة المزوّد.
 */
class RefundHardeningTest extends TestCase
{
    use AdminFixtures, CommerceFixtures {
        CommerceFixtures::makeProduct insteadof AdminFixtures;
    }
    use RefreshDatabase;

    private function paidOrder(string $sku, int $minor = 40000): Order
    {
        FakeProvider::resetRefunds();
        config()->set('services.fake.refund_mode', 'succeed');
        $cookie = $this->cartWith([$this->makeProduct($sku, $minor)]);
        $order = $this->checkoutOrder($cookie)['order'];
        $body = json_encode(['charge_id' => 'chg_'.$sku, 'order_number' => $order->number, 'status' => 'CAPTURED', 'amount_minor' => $minor, 'currency' => 'SAR'], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/v1/payments/webhook/fake', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body)], content: $body)->assertOk();

        return $order->fresh();
    }

    private function refundWebhook(string $refundId, string $chargeId, string $status, ?int $amount = null): TestResponse
    {
        $body = json_encode(['object' => 'refund', 'refund_id' => $refundId, 'charge_id' => $chargeId, 'status' => $status] + ($amount === null ? [] : ['amount_minor' => $amount, 'currency' => 'SAR']), JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/payments/webhook/fake', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body)], content: $body);
    }

    // ── §٦ المفتاح نفسه بجسمٍ آخر ─────────────────────────────────────────

    public function test_the_same_key_with_a_different_amount_is_an_explicit_conflict(): void
    {
        $order = $this->paidOrder('TD-RH-001');
        $service = app(RefundService::class);
        $service->refund($order, 10000, 'x', RevocationPolicy::Keep, 'rk-1');

        try {
            $service->refund($order->fresh(), 15000, 'x', RevocationPolicy::Keep, 'rk-1');
            $this->fail('مبلغٌ آخر بالمفتاح نفسه أُرجع صامتًا.');
        } catch (RefundIdempotencyConflict) {
        }

        try {
            $service->refund($order->fresh(), 10000, 'x', RevocationPolicy::Immediate, 'rk-1');
            $this->fail('سياسةٌ أخرى بالمفتاح نفسه أُرجعت صامتًا.');
        } catch (RefundIdempotencyConflict) {
        }

        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
        $this->assertSame(10000, (int) $order->fresh()->refunded_total_minor);
        $this->assertCount(1, FakeProvider::$executedRefunds);
    }

    public function test_the_admin_endpoint_answers_409_on_a_key_conflict(): void
    {
        $order = $this->paidOrder('TD-RH-002');
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", ['amountMinor' => 10000, 'policy' => 'keep', 'idempotencyKey' => 'rk-admin-1'])->assertCreated();
        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", ['amountMinor' => 12000, 'policy' => 'keep', 'idempotencyKey' => 'rk-admin-1'])
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
    }

    // ── §٧ PENDING يُستعلم قبل أيّ ردٍّ جديد ─────────────────────────────

    public function test_a_pending_refund_is_queried_before_a_new_refund(): void
    {
        $order = $this->paidOrder('TD-RH-003');
        $service = app(RefundService::class);

        config()->set('services.fake.refund_mode', 'pending');
        $pending = $service->refund($order, 10000, 'x', RevocationPolicy::Keep, 'rk-p1');
        FakeProvider::$refunds[$pending->provider_refund_id] = 'REFUNDED';

        config()->set('services.fake.refund_mode', 'succeed');
        $service->refund($order->fresh(), 5000, 'x', RevocationPolicy::Keep, 'rk-p2');

        $this->assertSame('SUCCEEDED', $pending->fresh()->status, 'المعلَّق حُسم بسؤال المزوّد قبل الجديد');
        $this->assertSame(15000, (int) $order->fresh()->refunded_total_minor);
        $this->assertSame(0, (int) $order->fresh()->refund_reserved_minor);
    }

    // ── §٨ الانهيار بعد أن نفّذ المزوّد ─────────────────────────────────

    public function test_provider_executed_then_response_lost_converges_to_one_refund(): void
    {
        $order = $this->paidOrder('TD-RH-004');
        config()->set('services.fake.refund_mode', 'timeout_after_execute');

        try {
            app(RefundService::class)->refund($order, 40000, 'x', RevocationPolicy::Immediate, 'rk-crash');
            $this->fail('الغموض لم يُرمَ');
        } catch (RefundOutcomeUnknown) {
        }
        $this->assertCount(1, FakeProvider::$executedRefunds, 'المال خرج مرّةً عند المزوّد');
        $this->assertSame('UNKNOWN', Refund::query()->where('order_id', $order->id)->value('status'));
        $this->assertSame('ACTIVE', Entitlement::query()->where('order_id', $order->id)->value('status'), 'لا سحب قبل التأكيد');

        config()->set('services.fake.refund_mode', 'succeed');
        Refund::query()->where('order_id', $order->id)->update(['updated_at' => now()->subHour()]);
        $this->artisan('payments:requery')->assertSuccessful();

        $this->assertCount(1, FakeProvider::$executedRefunds, 'الإعادة بالمفتاح نفسه لم تُخرج مالًا ثانيًا');
        $this->assertSame('SUCCEEDED', Refund::query()->where('order_id', $order->id)->value('status'));
        $this->assertSame('REFUNDED', $order->fresh()->status);
        $this->assertSame('REVOKED', Entitlement::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_a_crash_after_provider_success_before_local_commit_converges(): void
    {
        $order = $this->paidOrder('TD-RH-005');

        // المزوّد يؤكّد، ثمّ تنهار معاملة الحسم عندنا (حدث الصادر يفشل مرّةً).
        $real = app(OutboxWriter::class);
        $failOnce = true;
        $this->app->instance(OutboxWriter::class, new class($real, $failOnce) extends OutboxWriter
        {
            public function __construct(private OutboxWriter $inner, private bool &$fail) {}

            public function write(string $aggregateType, string $aggregateId, string $eventType, array $payload, string $idempotencyKey, ?string $correlationId = null): OutboxEvent
            {
                if ($eventType === 'refund.completed' && $this->fail) {
                    $this->fail = false;
                    throw new \RuntimeException('simulated crash before commit');
                }

                return $this->inner->write($aggregateType, $aggregateId, $eventType, $payload, $idempotencyKey, $correlationId);
            }
        });
        $this->app->forgetInstance(RefundService::class);

        try {
            app(RefundService::class)->refund($order, 40000, 'x', RevocationPolicy::Immediate, 'rk-crash2');
            $this->fail('الانهيار لم يظهر');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated crash before commit', $e->getMessage());
        }

        $refund = Refund::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('PENDING', $refund->status, 'الحسم تراجع كاملًا');
        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame(40000, (int) $order->fresh()->refund_reserved_minor, 'الحجز باقٍ فلا يُردّ مرّتين');
        $this->assertSame(0, OutboxEvent::query()->where('event_type', 'refund.completed')->count());

        Refund::query()->whereKey($refund->id)->update(['updated_at' => now()->subHour()]);
        $this->artisan('payments:requery')->assertSuccessful();

        $this->assertCount(1, FakeProvider::$executedRefunds);
        $this->assertSame('SUCCEEDED', $refund->fresh()->status);
        $this->assertSame('REFUNDED', $order->fresh()->status);
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'refund.completed')->count());
    }

    // ── §٧ استردادٌ من لوحة المزوّد ─────────────────────────────────────

    public function test_a_dashboard_refund_is_recorded_once_and_opens_a_case(): void
    {
        $order = $this->paidOrder('TD-RH-006');

        for ($i = 0; $i < 5; $i++) {
            $this->refundWebhook('re_dash_1', 'chg_TD-RH-006', 'REFUNDED', 15000)->assertOk();
        }

        $refunds = Refund::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $refunds);
        $this->assertSame(['SUCCEEDED', 'provider', 'order', 15000], [$refunds[0]->status, $refunds[0]->source, $refunds[0]->scope, (int) $refunds[0]->amount_minor]);
        $this->assertSame('PARTIALLY_REFUNDED', $order->fresh()->status);
        $this->assertSame(15000, (int) $order->fresh()->refunded_total_minor);
        $this->assertSame('ACTIVE', Entitlement::query()->where('order_id', $order->id)->value('status'), 'لا سحب تلقائيّ — القرار للمشغّل');

        // والمتبقّي القابل للردّ من التطبيق يعرف ما خرج من اللوحة.
        $this->expectException(RefundExceedsCaptured::class);
        try {
            app(RefundService::class)->refund($order->fresh(), 30000, 'x', RevocationPolicy::Keep, 'rk-after-dash');
        } finally {
            app(CaseScanner::class)->scan();
            $this->assertSame(1, WorkCase::query()->where('subject_type', 'refund')->where('subject_id', $refunds[0]->id)->count());
        }
    }

    public function test_a_dashboard_refund_larger_than_the_ledger_allows_is_flagged_not_recorded(): void
    {
        $order = $this->paidOrder('TD-RH-007');

        $this->refundWebhook('re_dash_big', 'chg_TD-RH-007', 'REFUNDED', 50000)->assertOk()->assertJsonPath('status', 'UNMATCHED');

        $this->assertSame(0, Refund::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, (int) $order->fresh()->refunded_total_minor);
    }

    // ── ردُّ مالٍ لم يُموِّل طلبًا ─────────────────────────────────────────

    public function test_an_unfunded_capture_can_be_refunded_without_touching_the_order(): void
    {
        FakeProvider::resetRefunds();
        config()->set('services.fake.refund_mode', 'succeed');
        $cookie = $this->cartWith([$this->makeProduct('TD-RH-008', 40000)]);
        $order = $this->checkoutOrder($cookie)['order'];
        $body = json_encode(['charge_id' => 'chg_short', 'order_number' => $order->number, 'status' => 'CAPTURED', 'amount_minor' => 100, 'currency' => 'SAR'], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/v1/payments/webhook/fake', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body)], content: $body)->assertOk();
        $payment = Payment::query()->where('idempotency_key', 'fake:chg_short')->firstOrFail();

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->postJson("/api/v1/admin/payments/transactions/{$payment->id}/refund", ['amountMinor' => 101, 'reason' => 'ردّ الفرق', 'idempotencyKey' => 'cap-rk-0'])
            ->assertStatus(422)->assertJsonPath('code', 'REFUND_EXCEEDS_CAPTURED');
        $this->postJson("/api/v1/admin/payments/transactions/{$payment->id}/refund", ['amountMinor' => 100, 'reason' => 'ردّ الفرق', 'idempotencyKey' => 'cap-rk-1'])
            ->assertCreated()->assertJsonPath('data.status', 'SUCCEEDED')->assertJsonPath('data.payment.refundableMinor', 0);
        $this->postJson("/api/v1/admin/payments/transactions/{$payment->id}/refund", ['amountMinor' => 100, 'reason' => 'ردّ الفرق', 'idempotencyKey' => 'cap-rk-1'])
            ->assertCreated();

        $this->assertCount(1, FakeProvider::$executedRefunds);
        $this->assertSame(100, (int) $payment->fresh()->refunded_minor);
        $fresh = $order->fresh();
        $this->assertSame(['AWAITING_PAYMENT', 0, 0], [$fresh->status, (int) $fresh->refunded_total_minor, (int) $fresh->refund_reserved_minor]);
        $this->assertDatabaseHas('admin_audit_log', ['action' => 'payment.capture_refunded', 'entity_id' => $payment->id]);

        // والتسوية لا تعدّه مالًا يتيمًا بعد أن رُدّ كاملًا.
        $this->assertSame(0, app(Reconciliation::class)->run()['counts']['orphanCapture']);
    }

    public function test_a_funded_capture_cannot_be_refunded_through_the_capture_path(): void
    {
        $order = $this->paidOrder('TD-RH-009');
        $payment = Payment::query()->where('order_id', $order->id)->where('funding', 'FUNDED')->firstOrFail();

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->postJson("/api/v1/admin/payments/transactions/{$payment->id}/refund", ['amountMinor' => 100, 'reason' => 'خطأ', 'idempotencyKey' => 'cap-rk-2'])
            ->assertStatus(422)->assertJsonPath('code', 'REFUND_PROVIDER_REJECTED');
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_the_engine_refuses_a_capture_ledger_overrun_even_by_raw_write(): void
    {
        $order = $this->paidOrder('TD-RH-010');
        $payment = Payment::query()->where('order_id', $order->id)->where('state', 'CAPTURED')->firstOrFail();

        $this->expectException(QueryException::class);
        DB::table('payments')->where('id', $payment->id)->update(['refunded_minor' => 40001]);
    }

    public function test_the_capture_refund_requires_the_refund_permission(): void
    {
        $order = $this->paidOrder('TD-RH-011');
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ReadOnly));

        $this->postJson("/api/v1/admin/payments/transactions/{$payment->id}/refund", ['amountMinor' => 1, 'reason' => 'x', 'idempotencyKey' => 'cap-rk-3'])
            ->assertForbidden();
    }
}
