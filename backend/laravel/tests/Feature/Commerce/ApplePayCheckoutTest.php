<?php

namespace Tests\Feature\Commerce;

use App\Domain\Cart\CartService;
use App\Domain\Payments\Models\Payment;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Apple Pay عبر صفحة Tap المستضافة — المسار نفسه، مصدرٌ آخر.
 *
 * `paymentMethod: apple_pay` يغيّر `source.id` في طلب التحصيل وحده؛ ولكلّ
 * طريقةٍ مفتاحُ نيّتها فلا يُعاد رابطُ الأخرى؛ وحين لا يقبل المزوّد Apple Pay
 * يُرفض الطلب بـ422 ولا يسقط إلى البطاقة بصمت.
 */
class ApplePayCheckoutTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    private int $charges = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials(); // ما يفعله setUp في CommerceFixtures الذي يُحجب هنا
        config([
            'services.payments.provider' => 'tap',
            'services.tap.apple_pay' => true,
            'services.tap.secret_key' => 'sk_test_secret',
            // عقد Tap: مفتاحٌ بلا معرّف تاجر يُرفض (TapCredentials).
            'services.tap.merchant_id' => '68052232',
        ]);

        // كلّ تحصيلٍ معرّفُه ورابطُه، ومصدرُه في الرابط ليُقرأ من الجواب.
        Http::fake([
            /*
             * قبل فتح رسمٍ بطريقةٍ أخرى يُسأل Tap عن الرسم القائم: ما زال
             * مفتوحًا (الزبون ترك الصفحة) — فالتبديل مسموح.
             */
            'api.tap.company/v2/charges/*' => fn (HttpRequest $request) => Http::response([
                'id' => basename($request->url()), 'status' => 'INITIATED', 'amount' => 400, 'currency' => 'SAR',
            ]),
            'api.tap.company/v2/charges' => function (HttpRequest $request) {
                $n = ++$this->charges;
                $source = $request['source']['id'];

                return Http::response([
                    'id' => "chg_{$n}",
                    'status' => 'INITIATED',
                    'transaction' => ['url' => "https://checkout.tap.company/pay/chg_{$n}?{$source}"],
                ]);
            },
        ]);
    }

    private function sentSources(): array
    {
        return Http::recorded()
            ->filter(fn ($pair) => $pair[0]->method() === 'POST')
            ->map(fn ($pair) => $pair[0]['source']['id'])->values()->all();
    }

    public function test_apple_pay_order_sends_src_apple_pay_and_its_own_key(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-AP-001', 40000)]);
        $response = $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/checkout/orders', [
                'name' => 'نورة العتيبي', 'email' => 'noura@example.com', 'phone' => '512345678',
                'policyConsent' => true, 'paymentMethod' => 'apple_pay',
            ])
            ->assertCreated()
            ->assertJsonPath('payment.provider', 'tap')
            ->assertJsonPath('payment.method', 'apple_pay')
            ->assertJsonPath('payment.payUrl', 'https://checkout.tap.company/pay/chg_1?src_apple_pay');

        Http::assertSent(fn ($r) => $r['source']['id'] === 'src_apple_pay');
        $this->assertSame(['src_apple_pay'], $this->sentSources());

        $orderId = Payment::query()->value('order_id');
        $this->assertSame("tap:intent:{$orderId}:apple_pay", Payment::query()->value('idempotency_key'));
        $this->assertNotNull($response->json('orderToken'));
    }

    public function test_default_order_keeps_src_all_and_the_legacy_key(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-AP-002', 40000)]);
        $result = $this->checkoutOrder($cookie);

        $this->assertSame(['src_all'], $this->sentSources());
        $this->assertSame("tap:intent:{$result['order']->id}", Payment::query()->value('idempotency_key'));

        $this->postJson("/api/v1/checkout/orders/{$result['token']}/payment")
            ->assertOk()->assertJsonPath('payment.method', null);
    }

    public function test_switching_methods_both_ways_never_reuses_the_other_pay_url(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-AP-003', 40000)]);
        $result = $this->checkoutOrder($cookie, ['paymentMethod' => 'apple_pay']);
        $token = $result['token'];
        $orderId = $result['order']->id;

        $card = $this->postJson("/api/v1/checkout/orders/{$token}/payment", ['paymentMethod' => null])
            ->assertOk()->assertJsonPath('payment.method', null)->json('payment.payUrl');
        $this->assertSame('https://checkout.tap.company/pay/chg_2?src_all', $card);

        $this->travel(2)->seconds();
        $apple = $this->postJson("/api/v1/checkout/orders/{$token}/payment", ['paymentMethod' => 'apple_pay'])
            ->assertOk()->assertJsonPath('payment.method', 'apple_pay')->json('payment.payUrl');
        $this->assertSame('https://checkout.tap.company/pay/chg_1?src_apple_pay', $apple, 'نيّة Apple Pay القائمة تُعاد لا تُنشأ.');

        // تحصيلان فقط: لكلّ طريقةٍ نيّةٌ واحدة، والرجوع لا يُنشئ ثالثًا.
        $this->assertSame(['src_apple_pay', 'src_all'], $this->sentSources());
        $this->assertEqualsCanonicalizing(
            ["tap:intent:{$orderId}:apple_pay", "tap:intent:{$orderId}"],
            Payment::query()->where('order_id', $orderId)->pluck('idempotency_key')->all(),
        );

        // صفحة الطلب تعرض آخر طريقةٍ اختيرت.
        $this->getJson("/api/v1/orders/{$token}")->assertOk()->assertJsonPath('payment.payUrl', $apple);

        $this->travel(2)->seconds();
        $this->postJson("/api/v1/checkout/orders/{$token}/payment")
            ->assertOk()->assertJsonPath('payment.payUrl', $card);
        $this->getJson("/api/v1/orders/{$token}")->assertOk()->assertJsonPath('payment.payUrl', $card);
    }

    public function test_apple_pay_is_refused_when_the_provider_does_not_support_it(): void
    {
        config(['services.tap.apple_pay' => false]);
        $cookie = $this->cartWith([$this->makeProduct('TD-AP-004', 40000)]);

        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/checkout/orders', [
                'name' => 'نورة العتيبي', 'email' => 'noura@example.com', 'phone' => '512345678',
                'policyConsent' => true, 'paymentMethod' => 'apple_pay',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.paymentMethod.0', 'الدفع عبر Apple Pay غير متاح حاليًّا');

        $this->assertSame(0, Order::query()->count(), 'الرفض قبل إنشاء الطلب.');
        Http::assertNothingSent();

        $result = $this->checkoutOrder($cookie);
        $this->postJson("/api/v1/checkout/orders/{$result['token']}/payment", ['paymentMethod' => 'apple_pay'])
            ->assertStatus(422)
            ->assertJsonPath('errors.paymentMethod.0', 'الدفع عبر Apple Pay غير متاح حاليًّا');
        $this->assertSame(['src_all'], $this->sentSources(), 'لا سقوطَ صامتٌ إلى البطاقة.');
    }

    public function test_fake_provider_refuses_apple_pay_too(): void
    {
        config(['services.payments.provider' => 'fake', 'services.tap.apple_pay' => true]);
        $cookie = $this->cartWith([$this->makeProduct('TD-AP-005', 40000)]);
        $result = $this->checkoutOrder($cookie);

        $this->postJson("/api/v1/checkout/orders/{$result['token']}/payment", ['paymentMethod' => 'apple_pay'])
            ->assertStatus(422);
    }

    public function test_unknown_method_is_a_validation_error(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-AP-006', 40000)]);
        $result = $this->checkoutOrder($cookie);

        $this->postJson("/api/v1/checkout/orders/{$result['token']}/payment", ['paymentMethod' => 'stc_pay'])
            ->assertStatus(422)
            ->assertJsonPath('errors.paymentMethod.0', 'طريقة الدفع غير معروفة');
    }
}
