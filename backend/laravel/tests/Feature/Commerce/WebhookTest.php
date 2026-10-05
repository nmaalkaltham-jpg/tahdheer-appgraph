<?php

namespace Tests\Feature\Commerce;

use App\Domain\Outbox\OutboxEvent;
use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Webhook signature + idempotency — MIP 4.11 critical-path guarantees. */
class WebhookTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    /** @return array{order: Order, token: string} */
    private function awaitingOrder(string $sku = 'TD-W-001'): array
    {
        $cookie = $this->cartWith([$this->makeProduct($sku, 40000)]);
        $result = $this->checkoutOrder($cookie);

        return ['order' => $result['order'], 'token' => $result['token']];
    }

    private function deliver(Order $order, string $chargeId, ?string $signature = null, string $status = 'CAPTURED'): TestResponse
    {
        $body = json_encode([
            'charge_id' => $chargeId,
            'order_number' => $order->number,
            'status' => $status,
        ], JSON_THROW_ON_ERROR);

        return $this->call(
            'POST',
            '/api/v1/payments/webhook/fake',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_FAKE_SIGNATURE' => $signature ?? FakeProvider::sign($body),
            ],
            content: $body,
        );
    }

    public function test_valid_webhook_finalizes_order_atomically(): void
    {
        ['order' => $order] = $this->awaitingOrder();

        $this->deliver($order, 'chg_1')->assertOk()->assertJsonPath('duplicate', false);

        $order->refresh();
        /*
         * «مكتمل» لا «مدفوع»: الطلب كلُّه رقميّ، فوحداته نُفِّذت في المعاملة
         * نفسها ولا شيء ينتظر. كان يقف عند `PAID` لأنّ `isFullyFulfilled`
         * لم يكن يستدعيها شيء، فيبدو كلّ طلبٍ مسلَّم عملًا مفتوحًا في
         * لوحة المشغّل. و`paid_at` يبقى مكتوبًا — الدفع وقع.
         */
        $this->assertSame('COMPLETED', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(1, PaymentEvent::query()->count());
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'order.paid')->count());
        $this->assertSame('fake:chg_1', Payment::query()
            ->where('order_id', $order->id)->firstOrFail()->idempotency_key);
    }

    public function test_wrong_signature_is_401_and_nothing_is_written(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-W-002');

        $this->deliver($order, 'chg_1', signature: 'forged')
            ->assertStatus(401)
            ->assertJsonPath('message', 'توقيع غير صالح');

        $this->assertSame('AWAITING_PAYMENT', $order->fresh()->status);
        $this->assertSame(0, PaymentEvent::query()->count());
        $this->assertSame(0, Entitlement::query()->count());
        $this->assertSame(0, OutboxEvent::query()->where('event_type', 'order.paid')->count());
    }

    public function test_duplicate_webhook_is_a_no_op(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-W-003');

        $this->deliver($order, 'chg_dup')->assertOk()->assertJsonPath('duplicate', false);
        $this->deliver($order, 'chg_dup')->assertOk()->assertJsonPath('duplicate', true);

        // ONE payment event, ONE entitlement set, ONE order.paid outbox row.
        $this->assertSame(1, PaymentEvent::query()->count());
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, OutboxEvent::query()
            ->where('event_type', 'order.paid')
            ->where('aggregate_id', (string) $order->id)
            ->count());
    }

    public function test_already_paid_order_gets_no_double_grant(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-W-004');
        $this->deliver($order, 'chg_first')->assertOk();

        // A different charge id for a PAID order: no second entitlement set,
        // no second order.paid row, paid state unchanged.
        $this->deliver($order, 'chg_second')->assertOk();

        $order->refresh();
        $this->assertSame('COMPLETED', $order->status);
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'order.paid')->count());
    }

    public function test_non_captured_status_is_ignored(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-W-005');

        $this->deliver($order, 'chg_1', status: 'DECLINED')
            ->assertOk()->assertJsonPath('ignored', true);

        $this->assertSame('AWAITING_PAYMENT', $order->fresh()->status);
        $this->assertSame(0, Entitlement::query()->count());
    }

    public function test_unknown_provider_is_404(): void
    {
        $this->postJson('/api/v1/payments/webhook/stripe')->assertNotFound();
    }
}
