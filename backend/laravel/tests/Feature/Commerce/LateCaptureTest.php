<?php

namespace Tests\Feature\Commerce;

use App\Domain\Cases\CaseScanner;
use App\Domain\Cases\Models\WorkCase;
use App\Domain\Orders\OrderFinalizer;
use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Models\WebhookDelivery;
use App\Domain\Payments\PaymentUnfundable;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * §١١ — رسمٌ مقبوض على طلبٍ لا يُموَّل (مُلغى أو فاشل)، أو رسمٌ يخصّ طلبًا
 * آخر.
 *
 * السياسة (ب): المال يُسجَّل كما وصل ويُربط برسمه، والطلب لا يُموَّل ولا
 * يُمنح استحقاق، ويُفتح حادثٌ حرج ليُردّ المال أو يُعاد فتح الطلب بقرارٍ
 * بشريّ. لا يُهمَل مالٌ ولا يُصلَح صامتًا.
 */
class LateCaptureTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    private function awaitingOrder(string $sku): Order
    {
        $cookie = $this->cartWith([$this->makeProduct($sku, 40000)]);

        return $this->checkoutOrder($cookie)['order'];
    }

    private function deliver(Order $order, string $chargeId, int $amount = 40000): TestResponse
    {
        $body = json_encode([
            'charge_id' => $chargeId, 'order_number' => $order->number, 'status' => 'CAPTURED',
            'amount_minor' => $amount, 'currency' => 'SAR',
        ], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/payments/webhook/fake', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body),
        ], content: $body);
    }

    public function test_a_capture_on_a_cancelled_order_is_recorded_but_does_not_fund_it(): void
    {
        $order = $this->awaitingOrder('TD-LC-001');
        $order->update(['status' => 'CANCELLED']);

        $this->deliver($order, 'chg_late')->assertOk()->assertJsonPath('status', 'MISMATCH');

        $fresh = $order->fresh();
        $this->assertSame('CANCELLED', $fresh->status);
        $this->assertNull($fresh->paid_at);
        $this->assertSame(0, Entitlement::query()->where('order_id', $order->id)->count());

        // المال لا يُخفى: تحصيلٌ مربوط بالرسم وبالطلب، بحدثٍ يقول لماذا لم يُموِّل.
        $payment = Payment::query()->where('idempotency_key', 'fake:chg_late')->firstOrFail();
        $this->assertSame('CAPTURED', $payment->state);
        $this->assertSame($order->id, (int) $payment->order_id);
        $this->assertSame(40000, (int) $payment->amount_minor);
        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $payment->id)->where('event_type', 'charge.unfundable')->count());

        $delivery = WebhookDelivery::query()->where('charge_id', 'chg_late')->firstOrFail();
        $this->assertSame('MISMATCH', $delivery->status);
        $this->assertStringContainsString('ORDER_NOT_FUNDABLE', (string) $delivery->last_error);

        // والحادث يُفتح من التسوية: مالٌ على طلبٍ غير مموَّل.
        app(CaseScanner::class)->scan();
        $case = WorkCase::query()->where('subject_type', 'payment')->where('subject_id', $payment->id)->firstOrFail();
        $this->assertSame('CRITICAL', $case->severity);
        $this->assertSame(['ORPHAN_CAPTURE'], $case->reason_codes);
    }

    public function test_a_duplicate_late_capture_is_idempotent(): void
    {
        $order = $this->awaitingOrder('TD-LC-002');
        $order->update(['status' => 'CANCELLED']);

        $this->deliver($order, 'chg_late2')->assertOk();
        $this->deliver($order, 'chg_late2')->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, Payment::query()->where('idempotency_key', 'fake:chg_late2')->count());
        $this->assertSame('CANCELLED', $order->fresh()->status);
    }

    public function test_a_charge_created_for_another_order_cannot_fund_this_order(): void
    {
        $a = $this->awaitingOrder('TD-LC-003');
        $b = $this->awaitingOrder('TD-LC-004');

        // رسمُ الطلب (أ) — نيّته مسجَّلة عند الدفع — يصل مُشيرًا إلى الطلب (ب).
        $chargeOfA = FakeProvider::chargeId($a);

        $this->deliver($b, $chargeOfA)->assertOk()->assertJsonPath('status', 'MISMATCH');

        $this->assertSame('AWAITING_PAYMENT', $b->fresh()->status);
        $this->assertSame('AWAITING_PAYMENT', $a->fresh()->status);
        $this->assertSame(0, Entitlement::query()->count());

        $delivery = WebhookDelivery::query()->where('charge_id', $chargeOfA)->firstOrFail();
        $this->assertStringContainsString('CHARGE_BELONGS_TO_ANOTHER_ORDER', (string) $delivery->last_error);

        // ويبقى الرسم مسجَّلًا على طلبه الحقيقيّ (أ) لا على (ب).
        $payment = Payment::query()->where('provider', 'fake')->where('intent_id', $chargeOfA)->firstOrFail();
        $this->assertSame($a->id, (int) $payment->order_id);
        $this->assertSame('CAPTURED', $payment->state);
    }

    public function test_the_fundability_check_reads_the_order_under_the_row_lock(): void
    {
        /*
         * سباق «إلغاء ثمّ رسم»: الحالة التي تُقرأ خارج القفل قد تكون قديمة.
         * تُثبت القراءة تحت القفل بتغيير الحالة بين ما يراه المستدعي وما في
         * الصفّ: الكائن الممرَّر يقول «بانتظار الدفع» والصفّ يقول «مُلغى».
         */
        $order = $this->awaitingOrder('TD-LC-005');
        $stale = $order->fresh();
        Order::query()->whereKey($order->id)->update(['status' => 'CANCELLED']);

        try {
            app(OrderFinalizer::class)->finalize($stale, 'fake', 'chg_race', [], 40000, 'SAR');
            $this->fail('رسمٌ على طلبٍ مُلغى مرّ لأنّ الحالة قُرئت خارج القفل.');
        } catch (PaymentUnfundable $e) {
            $this->assertSame('ORDER_NOT_FUNDABLE', $e->reason);
        }

        $this->assertSame('CANCELLED', $order->fresh()->status);
        $this->assertSame(0, Entitlement::query()->count());
        $this->assertDatabaseHas('payments', ['idempotency_key' => 'fake:chg_race', 'state' => 'CAPTURED']);
    }
}
