<?php

namespace Tests\Feature\Commerce;

use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Orders\Models\Refund;
use App\Domain\Orders\OrderToken;
use App\Domain\Orders\RefundExceedsCaptured;
use App\Domain\Orders\RefundService;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\RefundOutcomeUnknown;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خطّافٌ ضاع، واستردادٌ لم يُحسم — والتعافي آليّ بسؤال المزوّد.
 */
class ProviderRequeryTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    /** طلبٌ بانتظار الدفع ونيّتُه أُنشئت قبل ربع ساعة. */
    private function staleOrder(string $sku): Order
    {
        FakeProvider::resetRefunds();
        config()->set('services.fake.refund_mode', 'succeed');
        $cookie = $this->cartWith([$this->makeProduct($sku, 40000)]);
        $order = $this->checkoutOrder($cookie)['order'];
        Payment::query()->where('order_id', $order->id)->update(['created_at' => now()->subMinutes(15)]);

        return $order;
    }

    private function requery(): void
    {
        $this->artisan('payments:requery')->assertSuccessful();
    }

    public function test_a_capture_whose_webhook_was_lost_funds_the_order(): void
    {
        $order = $this->staleOrder('TD-RQ-001');
        FakeProvider::$charges[FakeProvider::chargeId($order)] = ['status' => 'CAPTURED', 'amount_minor' => 40000, 'currency' => 'SAR'];

        $this->requery();

        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
        $this->assertSame(40000, (int) Payment::query()->where('order_id', $order->id)->value('amount_minor'));

        // سؤالٌ ثانٍ، ثمّ الخطّاف المتأخّر: لا أثر ثانٍ.
        $this->requery();
        $body = json_encode(['charge_id' => FakeProvider::chargeId($order), 'order_number' => $order->number, 'status' => 'CAPTURED', 'amount_minor' => 40000, 'currency' => 'SAR'], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/v1/payments/webhook/fake', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body)], content: $body)
            ->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('state', 'CAPTURED')->count());
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'order.paid')->where('aggregate_id', (string) $order->id)->count());
    }

    public function test_a_requeried_capture_with_the_wrong_amount_is_recorded_but_not_funded(): void
    {
        $order = $this->staleOrder('TD-RQ-002');
        FakeProvider::$charges[FakeProvider::chargeId($order)] = ['status' => 'CAPTURED', 'amount_minor' => 100, 'currency' => 'SAR'];

        $this->requery();

        $this->assertSame('AWAITING_PAYMENT', $order->fresh()->status);
        $this->assertSame(0, Entitlement::query()->count());
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'state' => 'CAPTURED', 'amount_minor' => 100]);
    }

    public function test_a_dead_charge_is_marked_failed_and_retry_creates_a_fresh_one(): void
    {
        $order = $this->staleOrder('TD-RQ-003');
        FakeProvider::$charges[FakeProvider::chargeId($order)] = ['status' => 'DECLINED'];

        $this->requery();

        $this->assertSame('FAILED', Payment::query()->where('order_id', $order->id)->value('state'));
        $this->assertSame('AWAITING_PAYMENT', $order->fresh()->status);

        $token = OrderToken::make($order->number, $order->email);
        $this->postJson("/api/v1/checkout/orders/{$token}/payment")->assertOk();
        $this->assertSame('CREATED', Payment::query()->where('order_id', $order->id)->value('state'));
    }

    public function test_an_open_charge_and_an_unreachable_provider_change_nothing(): void
    {
        $open = $this->staleOrder('TD-RQ-004');
        FakeProvider::$charges[FakeProvider::chargeId($open)] = ['status' => 'INITIATED'];
        $silent = $this->staleOrder('TD-RQ-005'); // المزوّد لا يعرفه ⇒ لا جواب

        $this->requery();

        $this->assertSame('CREATED', Payment::query()->where('order_id', $open->id)->value('state'));
        $this->assertSame('CREATED', Payment::query()->where('order_id', $silent->id)->value('state'));
        $this->assertSame('AWAITING_PAYMENT', $open->fresh()->status);
        $this->assertSame('AWAITING_PAYMENT', $silent->fresh()->status);
    }

    public function test_a_fresh_intent_is_not_queried_while_the_customer_may_still_be_paying(): void
    {
        FakeProvider::resetRefunds();
        $cookie = $this->cartWith([$this->makeProduct('TD-RQ-006', 40000)]);
        $order = $this->checkoutOrder($cookie)['order'];
        FakeProvider::$charges[FakeProvider::chargeId($order)] = ['status' => 'DECLINED'];

        $this->requery();

        $this->assertSame('CREATED', Payment::query()->where('order_id', $order->id)->value('state'));
    }

    public function test_an_unknown_refund_converges_without_a_human(): void
    {
        $order = $this->staleOrder('TD-RQ-007');
        $this->postJson('/api/v1/payments/fake/'.OrderToken::make($order->number, $order->email).'/confirm')->assertOk();

        config()->set('services.fake.refund_mode', 'timeout');
        try {
            app(RefundService::class)->refund($order->fresh(), 40000, 'x', RevocationPolicy::Immediate, 'k-rq');
        } catch (RefundOutcomeUnknown) {
        }
        $this->assertSame('UNKNOWN', Refund::query()->where('order_id', $order->id)->value('status'));

        config()->set('services.fake.refund_mode', 'succeed');
        $this->requery();

        $refund = Refund::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('SUCCEEDED', $refund->status);
        $this->assertSame('REFUNDED', $order->fresh()->status);
        $this->assertCount(1, array_unique(array_column(FakeProvider::$refundCalls, 'idempotencyKey')));
    }

    public function test_a_stale_pending_refund_is_settled_by_asking_the_provider(): void
    {
        $order = $this->staleOrder('TD-RQ-008');
        $this->postJson('/api/v1/payments/fake/'.OrderToken::make($order->number, $order->email).'/confirm')->assertOk();

        config()->set('services.fake.refund_mode', 'pending');
        $refund = app(RefundService::class)->refund($order->fresh(), 40000, 'x', RevocationPolicy::Immediate, 'k-rq2');
        Refund::query()->whereKey($refund->id)->update(['updated_at' => now()->subMinutes(30)]);
        FakeProvider::$refunds[$refund->provider_refund_id] = 'REFUNDED';

        $this->requery();

        $this->assertSame('SUCCEEDED', $refund->fresh()->status);
        $this->assertSame('REFUNDED', $order->fresh()->status);
        $this->assertCount(1, FakeProvider::$refundCalls, 'السؤال لا يُنشئ استردادًا ثانيًا.');
    }

    public function test_a_refund_reserved_but_never_sent_is_sent_once_with_its_key(): void
    {
        // مات العامل بين الحجز والنداء: صفٌّ PENDING بلا هويّةٍ عند المزوّد.
        $order = $this->staleOrder('TD-RQ-009');
        $this->postJson('/api/v1/payments/fake/'.OrderToken::make($order->number, $order->email).'/confirm')->assertOk();
        $payment = Payment::query()->where('order_id', $order->id)->where('state', 'CAPTURED')->firstOrFail();

        $refund = Refund::query()->create([
            'order_id' => $order->id, 'amount_minor' => 40000, 'status' => 'PENDING', 'provider' => 'fake',
            'payment_id' => $payment->id, 'revocation_policy' => 'immediate', 'idempotency_key' => 'k-crash',
        ]);
        Order::query()->whereKey($order->id)->update(['refund_reserved_minor' => 40000]);
        Refund::query()->whereKey($refund->id)->update(['updated_at' => now()->subMinutes(30)]);

        $this->requery();

        $this->assertSame('SUCCEEDED', $refund->fresh()->status);
        $this->assertCount(1, FakeProvider::$refundCalls);
        $this->assertSame("refund:{$refund->id}", FakeProvider::$refundCalls[0]['idempotencyKey']);
        $this->assertSame(0, (int) $order->fresh()->refund_reserved_minor);
        $this->assertSame(40000, (int) $order->fresh()->refunded_total_minor);
    }

    public function test_a_refund_made_outside_the_app_is_discovered_once_and_caps_further_refunds(): void
    {
        $order = $this->staleOrder('TD-RQ-DASH');
        $this->postJson('/api/v1/payments/fake/'.OrderToken::make($order->number, $order->email).'/confirm')->assertOk();
        $capture = Payment::query()->where('order_id', $order->id)->where('state', 'CAPTURED')->firstOrFail();
        Payment::query()->whereKey($capture->id)->update(['amount_minor' => 40000, 'currency' => 'SAR']);

        // Tap يعرض استردادًا لم يطلبه التطبيق (لوحة المزوّد) — بالشكل المرصود.
        FakeProvider::$charges[(string) $capture->intent_id] = ['status' => 'CAPTURED', 'amount_minor' => 40000, 'currency' => 'SAR',
            'refunds' => [['id' => 're_dash_x', 'amountMinor' => 15000, 'currency' => 'SAR', 'status' => 'REFUNDED']]];

        $this->artisan('payments:requery --refunds')->assertSuccessful();
        $this->artisan('payments:requery --refunds')->assertSuccessful();

        $refunds = Refund::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $refunds);
        $this->assertSame(['provider', 'SUCCEEDED', 15000], [$refunds[0]->source, $refunds[0]->status, (int) $refunds[0]->amount_minor]);
        $this->assertSame(15000, (int) $order->fresh()->refunded_total_minor);
        $this->assertSame('PARTIALLY_REFUNDED', $order->fresh()->status);

        $this->expectException(RefundExceedsCaptured::class);
        app(RefundService::class)->refund($order->fresh(), 30000, 'x', RevocationPolicy::Keep, 'after-dash');
    }
}
