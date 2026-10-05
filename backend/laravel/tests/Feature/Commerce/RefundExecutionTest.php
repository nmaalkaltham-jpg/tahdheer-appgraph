<?php

namespace Tests\Feature\Commerce;

use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Orders\Models\Refund;
use App\Domain\Orders\RefundFailed;
use App\Domain\Orders\RefundService;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\RefundOutcomeUnknown;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الاسترداد يقع عند المزوّد لا في الدفتر وحده.
 *
 * كان صفّ الاسترداد يُكتب محلّيًّا ويُعلَّم الطلب «مستردًّا» ولا يُطلب من
 * البوّابة شيء: الدفتر يقول «رُدّ المال» والزبون لم يستلم هللة. الآن: حجزٌ
 * محلّيّ ← نداءُ المزوّد بمفتاح خمول ← تسوية. وكلّ انقطاعٍ بينها له حالةٌ
 * مكتوبة وطريقُ تعافٍ حتميّ.
 */
class RefundExecutionTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    /* لا `setUp` هنا: الصنف يحجب `CommerceFixtures::setUp` فتُترك التجهيزات. */
    private function paidOrder(string $sku): Order
    {
        FakeProvider::resetRefunds();
        config()->set('services.fake.refund_mode', 'succeed');

        $cookie = $this->cartWith([$this->makeProduct($sku, 40000)]);
        $result = $this->checkoutOrder($cookie);
        $this->postJson("/api/v1/payments/fake/{$result['token']}/confirm")->assertOk();

        return $result['order']->fresh();
    }

    public function test_a_refund_is_executed_at_the_provider_with_an_idempotency_key(): void
    {
        $order = $this->paidOrder('TD-RX-001');

        $refund = app(RefundService::class)->refund($order, 40000, 'ملف تالف', RevocationPolicy::Immediate, 'k:1');

        $this->assertSame('SUCCEEDED', $refund->status);
        $this->assertSame('fake', $refund->provider);
        $this->assertNotNull($refund->provider_refund_id);
        $this->assertNotNull($refund->settled_at);

        $this->assertCount(1, FakeProvider::$refundCalls);
        $call = FakeProvider::$refundCalls[0];
        $this->assertSame(40000, $call['amountMinor']);
        $this->assertSame('SAR', $call['currency']);
        $this->assertSame("refund:{$refund->id}", $call['idempotencyKey']);
        $this->assertSame(FakeProvider::chargeId($order), $call['chargeId']);

        $this->assertSame('REFUNDED', $order->fresh()->status);
        $this->assertSame('REVOKED', Entitlement::query()->where('order_id', $order->id)->firstOrFail()->status);
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'refund.completed')->count());
    }

    public function test_a_provider_rejection_releases_the_reservation_and_revokes_nothing(): void
    {
        $order = $this->paidOrder('TD-RX-002');
        config()->set('services.fake.refund_mode', 'reject');

        try {
            app(RefundService::class)->refund($order, 40000, 'x', RevocationPolicy::Immediate, 'k:2');
            $this->fail('الرفض لم يُرمَ.');
        } catch (RefundFailed) {
        }

        $refund = Refund::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('FAILED', $refund->status);
        $this->assertNotNull($refund->provider_error);

        $fresh = $order->fresh();
        $this->assertSame('COMPLETED', $fresh->status);
        $this->assertSame(0, (int) $fresh->refunded_total_minor);
        $this->assertSame('ACTIVE', Entitlement::query()->where('order_id', $order->id)->firstOrFail()->status);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', 'refund.completed')->count());

        // وبعد الرفض يمكن المحاولة من جديد بمفتاحٍ آخر.
        config()->set('services.fake.refund_mode', 'succeed');
        $again = app(RefundService::class)->refund($fresh, 40000, 'x', RevocationPolicy::Immediate, 'k:2b');
        $this->assertSame('SUCCEEDED', $again->status);
        $this->assertSame('REFUNDED', $order->fresh()->status);
    }

    public function test_a_timeout_holds_the_reservation_and_is_re_driven_with_the_same_key(): void
    {
        $order = $this->paidOrder('TD-RX-003');
        config()->set('services.fake.refund_mode', 'timeout');

        try {
            app(RefundService::class)->refund($order, 40000, 'x', RevocationPolicy::Immediate, 'k:3');
            $this->fail('الغموض لم يُرمَ.');
        } catch (RefundOutcomeUnknown) {
        }

        $refund = Refund::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('UNKNOWN', $refund->status);

        // المال ربّما خرج: الحجز يبقى فلا يُردّ مرّتين، والسحب لا يقع بعد.
        $this->assertSame(40000, (int) $order->fresh()->refund_reserved_minor);
        $this->assertSame(0, (int) $order->fresh()->refunded_total_minor);
        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame('ACTIVE', Entitlement::query()->where('order_id', $order->id)->firstOrFail()->status);

        // الإعادة بالمفتاح نفسه تعيد النداء بمفتاح المزوّد نفسه — لا استرداد ثانٍ.
        config()->set('services.fake.refund_mode', 'succeed');
        $resolved = app(RefundService::class)->refund($order->fresh(), 40000, 'x', RevocationPolicy::Immediate, 'k:3');

        $this->assertSame($refund->id, $resolved->id);
        $this->assertSame('SUCCEEDED', $resolved->status);
        $this->assertSame(0, (int) $order->fresh()->refund_reserved_minor);
        $this->assertSame(40000, (int) $order->fresh()->refunded_total_minor);
        $this->assertCount(2, FakeProvider::$refundCalls);
        $this->assertSame(FakeProvider::$refundCalls[0]['idempotencyKey'], FakeProvider::$refundCalls[1]['idempotencyKey']);
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
        $this->assertSame('REFUNDED', $order->fresh()->status);
        $this->assertSame('REVOKED', Entitlement::query()->where('order_id', $order->id)->firstOrFail()->status);
    }

    public function test_an_unresolved_refund_is_re_driven_before_any_new_refund_on_the_order(): void
    {
        $order = $this->paidOrder('TD-RX-004');
        config()->set('services.fake.refund_mode', 'timeout');

        try {
            app(RefundService::class)->refund($order, 10000, 'x', RevocationPolicy::Keep, 'k:4a');
        } catch (RefundOutcomeUnknown) {
        }

        // مزوّدٌ ما زال صامتًا: لا استرداد جديد فوق غامض.
        try {
            app(RefundService::class)->refund($order->fresh(), 5000, 'y', RevocationPolicy::Keep, 'k:4b');
            $this->fail('استردادٌ جديد مرّ فوق استردادٍ غامض.');
        } catch (RefundOutcomeUnknown) {
        }
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());

        // وحين يجيب المزوّد يُحسم القديم أوّلًا ثمّ يمرّ الجديد.
        config()->set('services.fake.refund_mode', 'succeed');
        $second = app(RefundService::class)->refund($order->fresh(), 5000, 'y', RevocationPolicy::Keep, 'k:4b');

        $this->assertSame('SUCCEEDED', $second->status);
        $this->assertSame('SUCCEEDED', Refund::query()->where('idempotency_key', 'k:4a')->firstOrFail()->status);
        $this->assertSame(15000, (int) $order->fresh()->refunded_total_minor);
        $this->assertSame('PARTIALLY_REFUNDED', $order->fresh()->status);
    }

    public function test_a_pending_refund_is_settled_by_the_provider_webhook(): void
    {
        $order = $this->paidOrder('TD-RX-005');
        config()->set('services.fake.refund_mode', 'pending');

        $refund = app(RefundService::class)->refund($order, 40000, 'x', RevocationPolicy::Immediate, 'k:5');
        $this->assertSame('PENDING', $refund->status);
        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame('ACTIVE', Entitlement::query()->where('order_id', $order->id)->firstOrFail()->status);

        $this->refundWebhook($refund->provider_refund_id, FakeProvider::chargeId($order), 'REFUNDED')
            ->assertOk()->assertJsonPath('status', 'PROCESSED');

        $this->assertSame('SUCCEEDED', $refund->fresh()->status);
        $this->assertSame('REFUNDED', $order->fresh()->status);
        $this->assertSame('REVOKED', Entitlement::query()->where('order_id', $order->id)->firstOrFail()->status);
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'refund.completed')->count());

        // التكرار بلا أثر.
        $this->refundWebhook($refund->provider_refund_id, FakeProvider::chargeId($order), 'REFUNDED')
            ->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'refund.completed')->count());
    }

    public function test_a_failed_refund_webhook_releases_the_reservation(): void
    {
        $order = $this->paidOrder('TD-RX-006');
        config()->set('services.fake.refund_mode', 'pending');

        $refund = app(RefundService::class)->refund($order, 40000, 'x', RevocationPolicy::Immediate, 'k:6');

        $this->refundWebhook($refund->provider_refund_id, FakeProvider::chargeId($order), 'FAILED')
            ->assertOk()->assertJsonPath('status', 'PROCESSED');

        $this->assertSame('FAILED', $refund->fresh()->status);
        $this->assertSame(0, (int) $order->fresh()->refunded_total_minor);
        $this->assertSame('COMPLETED', $order->fresh()->status);
    }

    public function test_a_refund_webhook_for_an_unknown_refund_is_unmatched(): void
    {
        $order = $this->paidOrder('TD-RX-007');

        $this->refundWebhook('fake_re_ghost', FakeProvider::chargeId($order), 'REFUNDED')
            ->assertOk()->assertJsonPath('status', 'UNMATCHED');

        $this->assertSame(0, (int) $order->fresh()->refunded_total_minor);
    }

    public function test_an_order_without_a_captured_payment_is_recorded_as_a_manual_refund(): void
    {
        FakeProvider::resetRefunds();
        $order = Order::create([
            'number' => 'TD-M-'.uniqid(), 'email' => 'a@b.test', 'status' => 'PAID',
            'totals_gross_minor' => 10000, 'total_minor' => 10000, 'order_key_hash' => hash('sha256', uniqid()),
        ]);

        $refund = app(RefundService::class)->refund($order, 10000, 'x', RevocationPolicy::Keep);

        $this->assertSame('MANUAL', $refund->status);
        $this->assertNull($refund->provider);
        $this->assertCount(0, FakeProvider::$refundCalls);
        $this->assertSame('REFUNDED', $order->fresh()->status);
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'refund.completed')->count());
    }

    private function refundWebhook(string $refundId, string $chargeId, string $status)
    {
        $body = json_encode([
            'object' => 'refund',
            'refund_id' => $refundId,
            'charge_id' => $chargeId,
            'status' => $status,
        ], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/payments/webhook/fake', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body),
        ], content: $body);
    }
}
