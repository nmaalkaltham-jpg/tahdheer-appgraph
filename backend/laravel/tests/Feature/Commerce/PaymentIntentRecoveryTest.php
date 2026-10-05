<?php

namespace Tests\Feature\Commerce;

use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\PaymentIntent;
use App\Domain\Payments\ProviderRegistry;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * §٢٢ — فشلُ إنشاء النيّة لا يُميت الطلب.
 *
 * `POST /checkout/orders/{token}/payment` يُعيد رابط الدفع القائم أو يُنشئ
 * نيّةً حين لا نيّة صالحة — بمفتاح النيّة نفسه، فلا نيّتان متنافستان.
 */
class PaymentIntentRecoveryTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    /** مزوّدٌ يفشل إنشاء نيّته أوّلًا ثمّ ينجح — أو حسب الأمر. */
    private function flakyProvider(array $script): void
    {
        $provider = Mockery::mock(FakeProvider::class)->makePartial();
        $calls = 0;
        $provider->shouldReceive('createIntent')->andReturnUsing(function (Order $order) use (&$calls, $script) {
            $step = $script[min($calls, count($script) - 1)];
            $calls++;
            if ($step === 'fail') {
                throw new \RuntimeException('provider down');
            }

            return new PaymentIntent('fake', 'fake_chg_'.$order->id, 'requires_payment', 'https://pay.test/'.$order->id);
        });

        $registry = Mockery::mock(ProviderRegistry::class)->makePartial();
        $registry->shouldReceive('default')->andReturn($provider);
        $registry->shouldReceive('resolve')->with('fake')->andReturn($provider);
        $this->instance(ProviderRegistry::class, $registry);
    }

    public function test_an_order_whose_intent_failed_can_be_paid_after_retry(): void
    {
        $this->flakyProvider(['fail', 'ok']);
        $cookie = $this->cartWith([$this->makeProduct('TD-IR-001', 40000)]);

        $result = $this->checkoutOrder($cookie);
        $order = $result['order'];

        $this->assertSame('INTENT_FAILED', Payment::query()->where('order_id', $order->id)->value('state'));

        $this->postJson("/api/v1/checkout/orders/{$result['token']}/payment")
            ->assertOk()
            ->assertJsonPath('payment.provider', 'fake')
            ->assertJsonPath('payment.payUrl', 'https://pay.test/'.$order->id);

        $payments = Payment::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $payments, 'نيّةٌ واحدة لا اثنتان.');
        $this->assertSame('CREATED', $payments[0]->state);
        $this->assertSame('fake:intent:'.$order->id, $payments[0]->idempotency_key);
    }

    public function test_a_live_intent_is_returned_not_recreated(): void
    {
        $this->flakyProvider(['ok']);
        $cookie = $this->cartWith([$this->makeProduct('TD-IR-002', 40000)]);
        $result = $this->checkoutOrder($cookie);

        $first = $this->postJson("/api/v1/checkout/orders/{$result['token']}/payment")->assertOk()->json('payment.payUrl');
        $second = $this->postJson("/api/v1/checkout/orders/{$result['token']}/payment")->assertOk()->json('payment.payUrl');

        $this->assertSame($first, $second);
        $this->assertSame(1, Payment::query()->where('order_id', $result['order']->id)->count());
    }

    public function test_a_paid_order_refuses_a_new_intent(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-IR-003', 40000)]);
        $result = $this->checkoutOrder($cookie);
        $this->postJson("/api/v1/payments/fake/{$result['token']}/confirm")->assertOk();

        $this->postJson("/api/v1/checkout/orders/{$result['token']}/payment")
            ->assertStatus(409)
            ->assertJsonPath('code', 'ORDER_NOT_PAYABLE');
    }

    public function test_a_received_but_unmatched_payment_blocks_a_new_intent(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-IR-005', 40000)]);
        $result = $this->checkoutOrder($cookie);
        $order = $result['order'];

        // تحصيلٌ موقّع بمبلغٍ خاطئ: يُسجَّل ولا يُموِّل.
        $body = json_encode(['charge_id' => 'chg_short', 'order_number' => $order->number, 'status' => 'CAPTURED', 'amount_minor' => 100, 'currency' => 'SAR'], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/v1/payments/webhook/fake', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body)], content: $body)->assertOk();

        $this->postJson("/api/v1/checkout/orders/{$result['token']}/payment")
            ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_UNRESOLVED');

        // وصفحة الطلب تقول الحقيقة: لا «لم يُخصم شيء».
        $this->getJson("/api/v1/orders/{$result['token']}")
            ->assertOk()
            ->assertJsonPath('paymentIssue', 'MISMATCH')
            ->assertJsonPath('payment.canRetry', false)
            ->assertJsonPath('payment.payUrl', null);
    }

    public function test_the_order_view_exposes_the_live_pay_url_only_while_awaiting(): void
    {
        $this->flakyProvider(['ok']);
        $cookie = $this->cartWith([$this->makeProduct('TD-IR-006', 40000)]);
        $result = $this->checkoutOrder($cookie);

        $this->getJson("/api/v1/orders/{$result['token']}")
            ->assertOk()
            ->assertJsonPath('payment.state', 'CREATED')
            ->assertJsonPath('payment.payUrl', 'https://pay.test/'.$result['order']->id)
            ->assertJsonPath('payment.canRetry', true)
            ->assertJsonPath('paymentIssue', null);

        $this->postJson("/api/v1/payments/fake/{$result['token']}/confirm")->assertOk();

        $this->getJson("/api/v1/orders/{$result['token']}")
            ->assertOk()
            ->assertJsonPath('payment.state', 'CAPTURED')
            ->assertJsonPath('payment.payUrl', null)
            ->assertJsonPath('payment.canRetry', false);
    }

    public function test_a_wrong_token_is_404(): void
    {
        $this->postJson('/api/v1/checkout/orders/not-a-token/payment')->assertNotFound();
    }
}
