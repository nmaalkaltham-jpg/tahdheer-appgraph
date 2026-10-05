<?php

namespace Tests\Feature\Commerce;

use App\Domain\Orders\OrderFinalizer;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\WebhookDelivery;
use App\Domain\Payments\PaymentUnfundable;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * المبلغ والعملة حقيقةٌ موقّعة من المزوّد — ومطابقتُهما للطلب شرطُ التمويل.
 *
 * رسمٌ موقّع بمبلغٍ أقلّ من الطلب (أو بعملةٍ أخرى) كان يُموِّل الطلب ويمنح
 * الاستحقاق، ويُترك للتسوية أن تكتشفه بعد أن سُلِّم الملفّ. المال الواصل
 * يُسجَّل — وقع فعلًا — لكنّ الطلب لا يُموَّل به.
 */
class WebhookAmountGuardTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    private function awaitingOrder(string $sku): Order
    {
        $cookie = $this->cartWith([$this->makeProduct($sku, 40000)]);

        return $this->checkoutOrder($cookie)['order'];
    }

    private function deliver(Order $order, string $chargeId, array $extra = []): TestResponse
    {
        $body = json_encode($extra + [
            'charge_id' => $chargeId,
            'order_number' => $order->number,
            'status' => 'CAPTURED',
        ], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/payments/webhook/fake', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body),
        ], content: $body);
    }

    public function test_a_capture_for_less_than_the_order_total_does_not_fund_the_order(): void
    {
        $order = $this->awaitingOrder('TD-AG-001');

        $this->deliver($order, 'chg_short', ['amount_minor' => 100, 'currency' => 'SAR'])
            ->assertOk()->assertJsonPath('status', 'MISMATCH');

        $this->assertSame('AWAITING_PAYMENT', $order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(0, Entitlement::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, OutboxEvent::query()->where('event_type', 'order.paid')->count());

        // المال وصل: يُسجَّل بمبلغه الحقيقيّ ليراه الدفتر والتسوية.
        $payment = Payment::query()->where('idempotency_key', 'fake:chg_short')->firstOrFail();
        $this->assertSame('CAPTURED', $payment->state);
        $this->assertSame(100, (int) $payment->amount_minor);

        $delivery = WebhookDelivery::query()->where('charge_id', 'chg_short')->firstOrFail();
        $this->assertSame('MISMATCH', $delivery->status);
        $this->assertNotNull($delivery->processed_at);
    }

    public function test_a_capture_in_another_currency_does_not_fund_the_order(): void
    {
        $order = $this->awaitingOrder('TD-AG-002');

        $this->deliver($order, 'chg_usd', ['amount_minor' => 40000, 'currency' => 'USD'])
            ->assertOk()->assertJsonPath('status', 'MISMATCH');

        $this->assertSame('AWAITING_PAYMENT', $order->fresh()->status);
        $this->assertSame(0, Entitlement::query()->where('order_id', $order->id)->count());
    }

    public function test_a_mismatched_delivery_redelivered_is_settled_and_not_reprocessed(): void
    {
        $order = $this->awaitingOrder('TD-AG-003');

        $this->deliver($order, 'chg_short2', ['amount_minor' => 100, 'currency' => 'SAR'])->assertOk();
        $this->deliver($order, 'chg_short2', ['amount_minor' => 100, 'currency' => 'SAR'])
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('status', 'MISMATCH');

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('state', 'CAPTURED')->count());
        $this->assertSame('AWAITING_PAYMENT', $order->fresh()->status);
    }

    public function test_an_exact_capture_still_funds_the_order(): void
    {
        $order = $this->awaitingOrder('TD-AG-004');

        $this->deliver($order, 'chg_exact', ['amount_minor' => (int) $order->total_minor, 'currency' => 'SAR'])
            ->assertOk()->assertJsonPath('status', 'PROCESSED');

        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
    }

    public function test_a_capture_with_no_reported_amount_is_still_honoured(): void
    {
        // مزوّدٌ لا يُبلّغ المبلغ: لا يُختلق ولا يُرفض — التسوية تعدّه «غير معروف».
        $order = $this->awaitingOrder('TD-AG-005');

        $this->deliver($order, 'chg_noamt')->assertOk()->assertJsonPath('status', 'PROCESSED');
        $this->assertSame('COMPLETED', $order->fresh()->status);
    }

    public function test_an_unreadable_provider_amount_never_funds_the_order(): void
    {
        foreach (['AMOUNT_MISSING', 'AMOUNT_PRECISION', 'AMOUNT_NOT_POSITIVE', 'CURRENCY_MISSING'] as $i => $problem) {
            $order = $this->awaitingOrder('TD-AG-1'.$i);

            try {
                app(OrderFinalizer::class)->finalize($order, 'fake', 'chg_bad_'.$i, [], null, null, $problem);
                $this->fail("{$problem} funded the order");
            } catch (PaymentUnfundable $e) {
                $this->assertSame('AMOUNT_MISMATCH', $e->reason);
            }

            $this->assertSame('AWAITING_PAYMENT', $order->fresh()->status, $problem);
            $this->assertSame(0, Entitlement::query()->where('order_id', $order->id)->count(), $problem);
            $this->assertDatabaseHas('payments', ['idempotency_key' => 'fake:chg_bad_'.$i, 'state' => 'CAPTURED']);
        }
    }
}
