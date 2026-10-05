<?php

namespace Tests\Feature\Commerce;

use App\Domain\Entitlements\EntitlementService;
use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Orders\OrderFinalizer;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Models\WebhookDelivery;
use App\Domain\Payments\WebhookIntake;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * §٨ — انهيارٌ عند كلّ حدٍّ داخل التمويل: لا نصفُ حالة، والإعادة تتقارب.
 *
 * الحدود بترتيب وقوعها في `OrderFinalizer`:
 *   ١) كتابة التحصيل  ٢) انتقال الطلب ثمّ المخزون  ٣) الاستحقاق/التنفيذ
 *   ٤) حدث الصادر (بعد الاستحقاق، قبل الالتزام)
 */
class FundingCrashPointTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    private function awaitingOrder(string $sku): Order
    {
        $cookie = $this->cartWith([$this->makeProduct($sku, 40000)]);

        return $this->checkoutOrder($cookie)['order'];
    }

    private function deliver(Order $order, string $charge): TestResponse
    {
        $body = json_encode(['charge_id' => $charge, 'order_number' => $order->number, 'status' => 'CAPTURED', 'amount_minor' => 40000, 'currency' => 'SAR'], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/payments/webhook/fake', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body)], content: $body);
    }

    private function assertNothingPartial(Order $order, string $point): void
    {
        $this->assertSame('AWAITING_PAYMENT', $order->fresh()->status, $point);
        $this->assertNull($order->fresh()->paid_at, $point);
        $this->assertSame(0, Payment::query()->where('order_id', $order->id)->where('state', 'CAPTURED')->count(), $point);
        $this->assertSame(0, PaymentEvent::query()->count(), $point);
        $this->assertSame(0, Entitlement::query()->where('order_id', $order->id)->count(), $point);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', 'order.paid')->count(), $point);
    }

    private function assertConvergesOnRetry(Order $order, string $charge, string $point): void
    {
        $delivery = WebhookDelivery::query()->where('charge_id', $charge)->firstOrFail();
        $this->assertSame('FAILED', $delivery->status, "{$point}: the failure is recorded, not swallowed");
        $this->assertNotNull($delivery->last_error);

        // المزوّد يعيد الإرسال (أو المشغّل يعيد التشغيل): يتقارب إلى تمويلٍ واحد.
        $this->deliver($order, $charge)->assertOk();
        $this->deliver($order, $charge)->assertOk();

        $this->assertSame('COMPLETED', $order->fresh()->status, $point);
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('funding', 'FUNDED')->count(), $point);
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count(), $point);
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'order.paid')->count(), $point);
    }

    public function test_crash_while_writing_the_capture(): void
    {
        $order = $this->awaitingOrder('TD-CP-1');
        $armed = true;
        PaymentEvent::creating(function () use (&$armed) {
            if ($armed) {
                $armed = false;
                throw new \RuntimeException('crash: capture write');
            }
        });

        $this->deliver($order, 'chg_cp1')->assertStatus(500);
        $this->assertNothingPartial($order, 'capture write');
        $this->assertConvergesOnRetry($order, 'chg_cp1', 'capture write');
    }

    public function test_crash_after_the_order_transition_before_entitlement(): void
    {
        $order = $this->awaitingOrder('TD-CP-2');
        $real = app(FulfillmentOrchestrator::class);
        $armed = true;
        $mock = \Mockery::mock($real)->makePartial();
        $mock->shouldReceive('fulfill')->andReturnUsing(function ($o) use (&$armed, $real) {
            if ($armed) {
                $armed = false;
                throw new \RuntimeException('crash: before entitlement');
            }

            return $real->fulfill($o);
        });
        $this->app->instance(FulfillmentOrchestrator::class, $mock);
        $this->app->forgetInstance(OrderFinalizer::class);
        $this->app->forgetInstance(WebhookIntake::class);

        $this->deliver($order, 'chg_cp2')->assertStatus(500);
        $this->assertNothingPartial($order, 'before entitlement');
        $this->assertConvergesOnRetry($order, 'chg_cp2', 'before entitlement');
    }

    public function test_crash_after_entitlement_before_commit(): void
    {
        $order = $this->awaitingOrder('TD-CP-3');
        $real = app(OutboxWriter::class);
        $armed = true;
        $this->app->instance(OutboxWriter::class, new class($real, $armed) extends OutboxWriter
        {
            public function __construct(private OutboxWriter $inner, private bool &$armed) {}

            public function write(string $aggregateType, string $aggregateId, string $eventType, array $payload, string $idempotencyKey, ?string $correlationId = null): OutboxEvent
            {
                if ($eventType === 'order.paid' && $this->armed) {
                    $this->armed = false;
                    throw new \RuntimeException('crash: before commit');
                }

                return $this->inner->write($aggregateType, $aggregateId, $eventType, $payload, $idempotencyKey, $correlationId);
            }
        });
        foreach ([OrderFinalizer::class, WebhookIntake::class, EntitlementService::class, FulfillmentOrchestrator::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $this->deliver($order, 'chg_cp3')->assertStatus(500);
        // الاستحقاق كُتب ثمّ تراجع مع المعاملة: لا وصولٌ بلا مالٍ مُلتزَم.
        $this->assertNothingPartial($order, 'after entitlement');
        $this->assertConvergesOnRetry($order, 'chg_cp3', 'after entitlement');
    }

    public function test_a_lost_acknowledgement_is_a_harmless_redelivery(): void
    {
        // التزم كلّ شيء، ثمّ ضاع ردّنا على المزوّد: يعيد الإرسال.
        $order = $this->awaitingOrder('TD-CP-4');
        $this->deliver($order, 'chg_cp4')->assertOk();
        $this->deliver($order, 'chg_cp4')->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('funding', 'FUNDED')->count());
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
    }
}
