<?php

namespace Tests\Feature\Commerce;

use App\Domain\Admin\AdminRole;
use App\Domain\Cases\CaseScanner;
use App\Domain\Cases\Models\WorkCase;
use App\Domain\Finance\Reconciliation;
use App\Domain\Orders\Models\Refund;
use App\Domain\Orders\OrderToken;
use App\Domain\Orders\RefundService;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Models\WebhookDelivery;
use App\Domain\Payments\PaymentHealth;
use App\Domain\Payments\TapProvider;
use App\Domain\Payments\WebhookIntake;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * المصفوفة عبر مُحوِّل Tap الحقيقيّ: توقيع hashstring ← الاستقبال ← المُنهي
 * الوحيد. لا مزوّد مزيَّف في هذا المسار — ما يُثبت هنا يُثبت لما يرسله Tap.
 *
 * الطلب ٤٠٠ ريال (٤٠٠٠٠ هللة)، ونيّةُ Tap مسجَّلةٌ له كما يُنشئها الدفع.
 */
class TapFundingMatrixTest extends TestCase
{
    use AdminFixtures, CommerceFixtures {
        CommerceFixtures::makeProduct insteadof \Tests\Feature\Admin\AdminFixtures;
    }
    use RefreshDatabase;

    private const SECRET = 'sk_test_matrix01';

    private const MERCHANT = '68052232';

    private int $n = 0;

    private function tapConfig(): void
    {
        config([
            'services.tap.mode' => 'test',
            'services.tap.merchant_id' => self::MERCHANT,
            'services.tap.test.secret_key' => self::SECRET,
            'services.tap.webhook_secret' => null,
        ]);
    }

    /** طلبٌ بانتظار الدفع ونيّةُ Tap له. @return array{Order, string} */
    private function orderWithTapIntent(): array
    {
        $this->tapConfig();
        $this->n++;
        $cookie = $this->cartWith([$this->makeProduct('TD-TM-'.$this->n.'-'.uniqid(), 40000)]);
        $order = $this->checkoutOrder($cookie)['order'];
        $charge = 'chg_TS_'.$this->n.'_'.substr(uniqid(), -6);

        // متجرٌ بمزوّدٍ واحد: نيّةُ المزيَّف التي أنشأها الدفع تُستبدل بنيّة Tap.
        Payment::query()->where('order_id', $order->id)->where('provider', 'fake')->delete();

        Payment::query()->create([
            'order_id' => $order->id, 'provider' => 'tap', 'intent_id' => $charge,
            'state' => 'CREATED', 'idempotency_key' => "tap:intent:{$order->id}",
        ]);

        return [$order, $charge];
    }

    /** @param  array<string, mixed>  $overrides */
    private function tapPayload(Order $order, string $charge, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $charge,
            'object' => 'charge',
            'amount' => 400,
            'currency' => 'SAR',
            'status' => 'CAPTURED',
            'merchant' => ['id' => self::MERCHANT],
            'reference' => ['gateway' => 'gw_1', 'payment' => 'pay_1', 'order' => $order->number],
            'transaction' => ['created' => '1727200000000'],
        ], $overrides);
    }

    /** @param  array<string, mixed>  $payload */
    private function deliver(array $payload, ?string $signature = null, bool $sign = true): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($sign) {
            $server['HTTP_HASHSTRING'] = $signature ?? TapProvider::hashstring($payload, self::SECRET);
        }

        return $this->call('POST', '/api/v1/payments/webhook/tap', server: $server, content: $body);
    }

    private function assertNotFunded(Order $order, string $why): void
    {
        $this->assertSame('AWAITING_PAYMENT', $order->fresh()->status, $why);
        $this->assertNull($order->fresh()->paid_at, $why);
        $this->assertSame(0, Entitlement::query()->where('order_id', $order->id)->count(), $why);
    }

    public function test_400_sar_for_400_sar_funds_exactly_once(): void
    {
        [$order, $charge] = $this->orderWithTapIntent();

        $this->deliver($this->tapPayload($order, $charge))->assertOk()->assertJsonPath('status', 'PROCESSED');

        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
        $payment = Payment::query()->where('intent_id', $charge)->firstOrFail();
        $this->assertSame(['CAPTURED', 'FUNDED', 40000, 'SAR'], [$payment->state, $payment->funding, (int) $payment->amount_minor, $payment->currency]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function rejected(): iterable
    {
        yield '1 SAR' => [['amount' => 1], 'AMOUNT_MISMATCH'];
        yield '399.99 SAR' => [['amount' => '399.99'], 'AMOUNT_MISMATCH'];
        yield '400.01 SAR' => [['amount' => 400.01], 'AMOUNT_MISMATCH'];
        yield '400 USD' => [['currency' => 'USD'], 'CURRENCY_MISMATCH'];
        yield 'EUR' => [['currency' => 'EUR'], 'CURRENCY_MISMATCH'];
        yield 'zero' => [['amount' => 0], 'AMOUNT_MISMATCH'];
        yield 'negative' => [['amount' => -400], 'AMOUNT_MISMATCH'];
        yield 'malformed' => [['amount' => '4OO'], 'AMOUNT_MISMATCH'];
        yield 'three decimals' => [['amount' => '400.001'], 'AMOUNT_MISMATCH'];
        yield 'extreme' => [['amount' => '99999999999999999.99'], 'AMOUNT_MISMATCH'];
        yield 'other merchant' => [['merchant' => ['id' => '11111111']], 'MERCHANT_MISMATCH'];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('rejected')]
    public function test_a_signed_capture_that_does_not_match_never_funds(array $overrides, string $reason): void
    {
        [$order, $charge] = $this->orderWithTapIntent();

        $this->deliver($this->tapPayload($order, $charge, $overrides))->assertOk()->assertJsonPath('status', 'MISMATCH');

        $this->assertNotFunded($order, $reason);
        $payment = Payment::query()->where('intent_id', $charge)->firstOrFail();
        $this->assertSame('CAPTURED', $payment->state, 'المال وصل ويُسجَّل');
        $this->assertSame('UNFUNDABLE', $payment->funding);
        $this->assertSame($reason, $payment->funding_reason);
    }

    public function test_missing_amount_and_missing_currency_never_fund(): void
    {
        foreach (['amount', 'currency'] as $field) {
            [$order, $charge] = $this->orderWithTapIntent();
            $payload = $this->tapPayload($order, $charge);
            unset($payload[$field]);

            $this->deliver($payload)->assertOk()->assertJsonPath('status', 'MISMATCH');
            $this->assertNotFunded($order, "missing {$field}");
        }
    }

    public function test_a_charge_the_store_never_created_cannot_fund_any_order(): void
    {
        // `reference.order` خارج التوقيع: رسمٌ صالح بهويّة طلبٍ مكتوبة في الحمولة لا يكفي.
        [$order] = $this->orderWithTapIntent();

        $this->deliver($this->tapPayload($order, 'chg_TS_not_ours'))->assertOk()->assertJsonPath('status', 'MISMATCH');

        $this->assertNotFunded($order, 'unknown charge');
        $this->assertSame('UNKNOWN_CHARGE', Payment::query()->where('intent_id', 'chg_TS_not_ours')->value('funding_reason'));
    }

    public function test_order_binding_holds_in_both_directions(): void
    {
        [$a, $chargeA] = $this->orderWithTapIntent();
        [$b, $chargeB] = $this->orderWithTapIntent();

        // رسم A يدّعي B، ورسم B يدّعي A.
        $this->deliver($this->tapPayload($b, $chargeA))->assertOk()->assertJsonPath('status', 'MISMATCH');
        $this->deliver($this->tapPayload($a, $chargeB))->assertOk()->assertJsonPath('status', 'MISMATCH');

        $this->assertNotFunded($a, 'A');
        $this->assertNotFunded($b, 'B');
        // والمال مسجَّلٌ على صاحبه الحقيقيّ، لا على من ادّعاه.
        $this->assertSame($a->id, (int) Payment::query()->where('intent_id', $chargeA)->where('state', 'CAPTURED')->value('order_id'));
        $this->assertSame($b->id, (int) Payment::query()->where('intent_id', $chargeB)->where('state', 'CAPTURED')->value('order_id'));
    }

    public function test_an_unknown_order_number_is_unmatched_and_stored(): void
    {
        [$order, $charge] = $this->orderWithTapIntent();

        $this->deliver($this->tapPayload($order, $charge, ['reference' => ['order' => 'TD-NOPE']]))
            ->assertOk()->assertJsonPath('status', 'UNMATCHED');

        $this->assertNotFunded($order, 'unknown order');
        $this->assertSame(1, WebhookDelivery::query()->where('charge_id', $charge)->count());
    }

    public function test_forged_missing_and_corrupted_signatures_store_nothing(): void
    {
        [$order, $charge] = $this->orderWithTapIntent();
        $payload = $this->tapPayload($order, $charge);

        $this->deliver($payload, signature: str_repeat('0', 64))->assertStatus(401);
        $this->deliver($payload, sign: false)->assertStatus(401);
        $this->deliver($payload, signature: 'not-hex')->assertStatus(401);

        // توقيعٌ صحيح لحمولةٍ ثمّ عُدِّل مبلغها.
        $signature = TapProvider::hashstring($payload, self::SECRET);
        $this->deliver(array_replace($payload, ['amount' => 1]), signature: $signature)->assertStatus(401);

        $this->assertSame(0, WebhookDelivery::query()->count());
        $this->assertNotFunded($order, 'forged');
    }

    public function test_duplicate_replayed_and_out_of_order_events_are_harmless(): void
    {
        [$order, $charge] = $this->orderWithTapIntent();
        $captured = $this->tapPayload($order, $charge);

        for ($i = 0; $i < 20; $i++) {
            $this->deliver($captured)->assertOk();
        }
        // حدثٌ أقدم يصل بعد الأحدث: «بدأ» ثمّ «رُفض» بعد القبض.
        $this->deliver($this->tapPayload($order, $charge, ['status' => 'INITIATED']))->assertOk()->assertJsonPath('status', 'IGNORED');
        $this->deliver($this->tapPayload($order, $charge, ['status' => 'DECLINED']))->assertOk()->assertJsonPath('status', 'IGNORED');

        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame(1, Payment::query()->where('intent_id', $charge)->count());
        $this->assertSame('CAPTURED', Payment::query()->where('intent_id', $charge)->value('state'), 'حدثٌ متأخّر لا يُرجع التحصيل');
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
    }

    public function test_an_unknown_status_is_ignored_not_funded(): void
    {
        [$order, $charge] = $this->orderWithTapIntent();

        $this->deliver($this->tapPayload($order, $charge, ['status' => 'SOMETHING_NEW']))->assertOk()->assertJsonPath('status', 'IGNORED');

        $this->assertNotFunded($order, 'unknown status');
        $this->assertSame('CREATED', Payment::query()->where('intent_id', $charge)->value('state'));
    }

    public function test_a_declined_charge_closes_its_intent_so_retry_opens_a_fresh_charge(): void
    {
        [$order, $charge] = $this->orderWithTapIntent();

        $this->deliver($this->tapPayload($order, $charge, ['status' => 'DECLINED']))->assertOk()->assertJsonPath('status', 'IGNORED');

        $this->assertSame('FAILED', Payment::query()->where('intent_id', $charge)->value('state'));
        $this->assertNotFunded($order, 'declined');

        // صفحة الطلب: فشلٌ صريح قابل للإعادة، بلا رابطٍ ميت.
        $token = OrderToken::make($order->number, $order->email);
        $this->getJson("/api/v1/orders/{$token}")->assertOk()
            ->assertJsonPath('payment.state', 'FAILED')
            ->assertJsonPath('payment.payUrl', null)
            ->assertJsonPath('payment.canRetry', true)
            ->assertJsonPath('paymentIssue', null);
    }

    public function test_a_rejected_capture_funds_once_after_legitimate_resolution(): void
    {
        [$order, $charge] = $this->orderWithTapIntent();
        $order->update(['status' => 'CANCELLED']);

        $this->deliver($this->tapPayload($order, $charge))->assertOk()->assertJsonPath('status', 'MISMATCH');
        $this->assertSame('ORDER_NOT_FUNDABLE', Payment::query()->where('intent_id', $charge)->value('funding_reason'));

        // المشغّل أعاد فتح الطلب بحقّ، ثمّ أعاد تشغيل التسليم المحفوظ.
        $order->update(['status' => 'AWAITING_PAYMENT']);
        $delivery = WebhookDelivery::query()->where('charge_id', $charge)->firstOrFail();
        $this->assertTrue($delivery->isReplayable());
        app(WebhookIntake::class)->process($delivery, $delivery->toEvent());

        $this->assertSame('COMPLETED', $order->fresh()->status);
        $this->assertSame('FUNDED', Payment::query()->where('intent_id', $charge)->value('funding'));
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());

        // وإعادةٌ ثانية لا تمويل ثانٍ.
        app(WebhookIntake::class)->process($delivery->fresh(), $delivery->toEvent());
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'order.paid')->where('aggregate_id', (string) $order->id)->count());
    }

    public function test_a_rejected_capture_stays_rejected_while_the_cause_remains(): void
    {
        [$order, $charge] = $this->orderWithTapIntent();

        $this->deliver($this->tapPayload($order, $charge, ['amount' => 1]))->assertOk();
        $delivery = WebhookDelivery::query()->where('charge_id', $charge)->firstOrFail();

        app(WebhookIntake::class)->process($delivery, $delivery->toEvent());

        $this->assertNotFunded($order, 'replay of the same wrong amount');
        $this->assertSame('UNFUNDABLE', Payment::query()->where('intent_id', $charge)->value('funding'));
    }

    public function test_the_engine_refuses_two_funding_captures_for_one_order(): void
    {
        [$order, $charge] = $this->orderWithTapIntent();
        $this->deliver($this->tapPayload($order, $charge))->assertOk();

        $this->expectException(QueryException::class);
        Payment::query()->create([
            'order_id' => $order->id, 'provider' => 'tap', 'intent_id' => 'chg_raw', 'state' => 'CAPTURED',
            'idempotency_key' => 'tap:chg_raw', 'funding' => 'FUNDED', 'amount_minor' => 40000, 'currency' => 'SAR',
        ]);
    }

    public function test_charge_creation_sends_the_idempotent_reference_and_merchant(): void
    {
        $this->tapConfig();
        config(['services.payments.provider' => 'tap']);
        Http::fake(['api.tap.company/v2/charges' => Http::response([
            'id' => 'chg_TS_idem', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.tap.company/x'],
        ])]);
        $cookie = $this->cartWith([$this->makeProduct('TD-TM-IDEM', 40000)]);
        $order = $this->checkoutOrder($cookie)['order'];

        Http::assertSent(fn ($r) => $r['reference']['idempotent'] === "tahdheer:tap:intent:{$order->id}:1"
            && $r['merchant']['id'] === self::MERCHANT
            && $r['amount'] === '400.00');
    }

    /** طلبٌ بنيّة بطاقةٍ قائمة عند Tap، ومزوّدُ الدفع Tap بـApple Pay. @return array{Order, string, string} */
    private function orderWithLiveCardCharge(string $lookupStatus, int $lookupHttp = 200): array
    {
        [$order, $charge] = $this->orderWithTapIntent();
        config(['services.payments.provider' => 'tap', 'services.tap.apple_pay' => true]);
        $token = OrderToken::make($order->number, $order->email);

        Http::fake([
            'api.tap.company/v2/charges/*' => Http::response(
                ['id' => $charge, 'status' => $lookupStatus, 'amount' => 400, 'currency' => 'SAR', 'merchant' => ['id' => self::MERCHANT]],
                $lookupHttp,
            ),
            'api.tap.company/v2/charges' => Http::response([
                'id' => 'chg_TS_apple', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.tap.company/apple'],
            ]),
        ]);

        return [$order, $charge, $token];
    }

    private function chargeCreations(): int
    {
        return Http::recorded()->filter(fn ($p) => $p[0]->method() === 'POST')->count();
    }

    public function test_switching_method_over_a_card_charge_that_was_already_paid_funds_and_refuses(): void
    {
        [$order, , $token] = $this->orderWithLiveCardCharge('CAPTURED');

        $this->postJson("/api/v1/checkout/orders/{$token}/payment", ['paymentMethod' => 'apple_pay'])
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_PAYABLE');

        $this->assertSame(0, $this->chargeCreations(), 'لا رسمٌ ثانٍ فوق مالٍ وصل');
        $this->assertSame('COMPLETED', $order->fresh()->status, 'المال الذي وصل موَّل عبر المسار المحروس');
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
    }

    public function test_switching_method_while_tap_cannot_answer_is_refused(): void
    {
        [$order, , $token] = $this->orderWithLiveCardCharge('CAPTURED', 503);

        $this->postJson("/api/v1/checkout/orders/{$token}/payment", ['paymentMethod' => 'apple_pay'])
            ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_UNRESOLVED');

        $this->assertSame(0, $this->chargeCreations());
        $this->assertNotFunded($order, 'no answer');
    }

    public function test_switching_method_over_an_abandoned_card_charge_is_allowed(): void
    {
        [, , $token] = $this->orderWithLiveCardCharge('INITIATED');

        $this->postJson("/api/v1/checkout/orders/{$token}/payment", ['paymentMethod' => 'apple_pay'])
            ->assertOk()->assertJsonPath('payment.payUrl', 'https://checkout.tap.company/apple');

        $this->assertSame(1, $this->chargeCreations());
    }

    public function test_the_provider_key_advances_only_after_a_confirmed_failure(): void
    {
        [$order, $charge] = $this->orderWithTapIntent();
        config(['services.payments.provider' => 'tap']);
        $token = OrderToken::make($order->number, $order->email);
        $sent = [];
        Http::fake(['api.tap.company/v2/charges' => function ($r) use (&$sent) {
            $sent[] = $r['reference']['idempotent'];

            return Http::response(['id' => 'chg_TS_retry_'.count($sent), 'status' => 'INITIATED', 'transaction' => ['url' => 'https://x/'.count($sent)]]);
        }]);

        // مهلةٌ في الإنشاء: المحاولة التالية بالمفتاح نفسه (Tap يعيد الرسم نفسه).
        Payment::query()->where('intent_id', $charge)->update(['state' => 'INTENT_FAILED', 'intent_id' => null]);
        $this->postJson("/api/v1/checkout/orders/{$token}/payment")->assertOk();

        // رُفض الرسم عند Tap: الآن فقط محاولةٌ جديدة بمفتاحٍ جديد.
        Payment::query()->where('order_id', $order->id)->where('provider', 'tap')->update(['state' => 'FAILED']);
        $this->postJson("/api/v1/checkout/orders/{$token}/payment")->assertOk();

        $this->assertSame(["tahdheer:tap:intent:{$order->id}:1", "tahdheer:tap:intent:{$order->id}:2"], $sent);
    }

    // ── §٥ بطاقة وApple Pay: رسمان قائمان، وتمويلٌ واحد ─────────────────────

    /**
     * يفتح رسمين حقيقيّين بالتبديل عبر نقطة الدفع نفسها، بالترتيب المعطى.
     *
     * @param  list<string|null>  $methods
     * @return array{Order, list<string>}
     */
    private function orderSwitchingMethods(array $methods): array
    {
        $this->tapConfig();
        config(['services.payments.provider' => 'tap', 'services.tap.apple_pay' => true]);
        $created = 0;
        Http::fake([
            // الرسم السابق ما زال مفتوحًا عند Tap (الزبون ترك الصفحة): التبديل مسموح.
            'api.tap.company/v2/charges/*' => fn ($r) => Http::response(['id' => basename($r->url()), 'status' => 'INITIATED', 'amount' => 400, 'currency' => 'SAR']),
            'api.tap.company/v2/charges' => function ($r) use (&$created) {
                $created++;

                return Http::response(['id' => "chg_TS_sw_{$created}_".substr(md5((string) $r['reference']['idempotent']), 0, 6), 'status' => 'INITIATED', 'transaction' => ['url' => "https://checkout.tap.company/{$created}"]]);
            },
        ]);
        $cookie = $this->cartWith([$this->makeProduct('TD-SW-'.uniqid(), 40000)]);
        $result = $this->checkoutOrder($cookie, ['paymentMethod' => $methods[0]]);
        foreach (array_slice($methods, 1) as $method) {
            $this->postJson("/api/v1/checkout/orders/{$result['token']}/payment", ['paymentMethod' => $method])->assertOk();
        }

        $charges = Payment::query()->where('order_id', $result['order']->id)->where('provider', 'tap')->orderBy('id')->pluck('intent_id')->all();
        $this->assertCount(count($methods), $charges, 'كلّ طريقةٍ رسمُها');

        return [$result['order']->fresh(), $charges];
    }

    private function assertOneFundingOneExtra(Order $order, string $why): void
    {
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('funding', 'FUNDED')->count(), $why);
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->where('funding', 'EXTRA')->count(), $why);
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count(), $why);
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'order.paid')->where('aggregate_id', (string) $order->id)->count(), $why);
        $this->assertSame('COMPLETED', $order->fresh()->status, $why);
    }

    public function test_card_then_apple_pay_both_paid_funds_once_and_the_extra_is_a_critical_case(): void
    {
        [$order, [$card, $apple]] = $this->orderSwitchingMethods([null, 'apple_pay']);

        $this->deliver($this->tapPayload($order, $card))->assertOk()->assertJsonPath('status', 'PROCESSED');
        $this->deliver($this->tapPayload($order, $apple))->assertOk()->assertJsonPath('status', 'PROCESSED');

        $this->assertOneFundingOneExtra($order, 'card→apple');
        $extra = Payment::query()->where('order_id', $order->id)->where('funding', 'EXTRA')->firstOrFail();
        $this->assertSame($apple, $extra->intent_id, 'الأوّل وصولًا يموّل، والثاني زائد');

        // حالةٌ حرجة للمشغّل، وإنذار صحّةٍ حرج — ولا ردٌّ آليّ.
        app(CaseScanner::class)->scan();
        $case = WorkCase::query()->where('subject_type', 'order')->where('subject_id', $order->id)->firstOrFail();
        $this->assertSame(['DUPLICATE_CAPTURE'], $case->reason_codes);
        $this->assertSame('CRITICAL', $case->severity);
        $this->assertSame(0, Refund::query()->count(), 'لا ردّ آليّ');
        $health = app(PaymentHealth::class);
        $this->assertContains(['code' => 'captures_extra', 'severity' => 'CRITICAL', 'value' => 1], $health->alerts($health->snapshot()));
    }

    public function test_apple_pay_then_card_both_paid_funds_once(): void
    {
        [$order, [$apple, $card]] = $this->orderSwitchingMethods(['apple_pay', null]);

        // الأحدث يصل أوّلًا هذه المرّة.
        $this->deliver($this->tapPayload($order, $card))->assertOk();
        $this->deliver($this->tapPayload($order, $apple))->assertOk();

        $this->assertOneFundingOneExtra($order, 'apple→card');
    }

    public function test_the_extra_capture_is_returned_by_the_one_click_remediation(): void
    {
        [$order, [$card, $apple]] = $this->orderSwitchingMethods([null, 'apple_pay']);
        $this->deliver($this->tapPayload($order, $card))->assertOk();
        $this->deliver($this->tapPayload($order, $apple))->assertOk();
        $extra = Payment::query()->where('order_id', $order->id)->where('funding', 'EXTRA')->firstOrFail();

        Http::fake(['api.tap.company/v2/refunds' => Http::response(['id' => 're_TS_extra', 'status' => 'REFUNDED'])]);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->postJson("/api/v1/admin/payments/transactions/{$extra->id}/refund", ['amountMinor' => 40000, 'reason' => 'دُفع الطلب مرّتين', 'idempotencyKey' => 'extra-refund-1'])
            ->assertCreated()->assertJsonPath('data.status', 'SUCCEEDED');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/refunds') && $r['charge_id'] === $apple && $r['amount'] === '400.00');
        $this->assertSame('COMPLETED', $order->fresh()->status, 'الطلب الموَّل لم يُمسّ');
        $this->assertSame(0, (int) $order->fresh()->refunded_total_minor);
        $this->assertSame('ACTIVE', Entitlement::query()->where('order_id', $order->id)->value('status'));
        $this->assertSame(0, app(Reconciliation::class)->run()['counts']['duplicateCapture'], 'المال المزدوج رُدّ فانحلّت الحالة');
    }

    // ── §٧ ربط الطلب لا يُختار بحقلٍ غير موقَّع ────────────────────────────

    public function test_a_validly_signed_charge_with_a_tampered_reference_order_cannot_redirect_funding(): void
    {
        [$a, $chargeA] = $this->orderWithTapIntent();
        [$b] = $this->orderWithTapIntent();

        $genuine = $this->tapPayload($a, $chargeA);
        $signature = TapProvider::hashstring($genuine, self::SECRET);
        $tampered = array_replace_recursive($genuine, ['reference' => ['order' => $b->number]]);

        // التوقيع لا يغطّي reference.order: التزوير «صالح التوقيع».
        $this->assertSame($signature, TapProvider::hashstring($tampered, self::SECRET));

        $this->deliver($tampered, signature: $signature)->assertOk()->assertJsonPath('status', 'MISMATCH');

        $this->assertNotFunded($b, 'tampered target');
        $this->assertNotFunded($a, 'real owner is not funded by a tampered event either');
        $payment = Payment::query()->where('intent_id', $chargeA)->where('state', 'CAPTURED')->firstOrFail();
        $this->assertSame($a->id, (int) $payment->order_id, 'المال على صاحبه القانونيّ');
        $this->assertSame('CHARGE_BELONGS_TO_ANOTHER_ORDER', $payment->funding_reason);
    }

    // ── §٨ مالٌ رُدّ منه لا يُموِّل أبدًا ──────────────────────────────────

    public function test_a_rejected_capture_with_a_refund_against_it_never_funds_later(): void
    {
        [$order, $charge] = $this->orderWithTapIntent();
        $order->update(['status' => 'CANCELLED']);
        $this->deliver($this->tapPayload($order, $charge))->assertOk()->assertJsonPath('status', 'MISMATCH');
        $payment = Payment::query()->where('intent_id', $charge)->where('state', 'CAPTURED')->firstOrFail();

        // جزءٌ رُدّ من هذا المال.
        Http::fake(['api.tap.company/v2/refunds' => Http::response(['id' => 're_TS_part', 'status' => 'REFUNDED'])]);
        app(RefundService::class)->refundCapture($payment, 100, 'جزء', 'cap-part-1');
        $this->assertSame(100, (int) $payment->fresh()->refunded_minor);

        // ثمّ «حُلّ» السبب وأُعيد التشغيل: لا تمويل.
        $order->update(['status' => 'AWAITING_PAYMENT']);
        $delivery = WebhookDelivery::query()->where('charge_id', $charge)->firstOrFail();
        app(WebhookIntake::class)->process($delivery, $delivery->toEvent());

        $this->assertNotFunded($order, 'refunded capture');
        $this->assertSame(['UNFUNDABLE', 'CAPTURE_REFUNDED'], [$payment->fresh()->funding, $payment->fresh()->funding_reason]);
    }

    // ── §٦ المفتاح نفسه بجسمٍ ماليٍّ آخر ────────────────────────────────────

    public function test_the_same_intent_key_with_a_changed_amount_is_refused_before_calling_tap(): void
    {
        $this->tapConfig();
        config(['services.payments.provider' => 'tap']);
        Http::fake(['api.tap.company/v2/charges' => Http::response(['id' => 'chg_TS_fp', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://x']])]);
        $cookie = $this->cartWith([$this->makeProduct('TD-FP-1', 40000)]);
        $result = $this->checkoutOrder($cookie);
        Http::assertSentCount(1);

        // المبلغ تغيّر بعد إنشاء الرابط (لا مسار يغيّره في المتجر — هذا دفاعٌ ضدّ خطأٍ أو عبث).
        DB::table('orders')->where('id', $result['order']->id)->update(['total_minor' => 10000]);

        $this->postJson("/api/v1/checkout/orders/{$result['token']}/payment")
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        Http::assertSentCount(1);
    }

    public function test_the_same_logical_intent_request_reuses_one_charge(): void
    {
        $this->tapConfig();
        config(['services.payments.provider' => 'tap']);
        Http::fake(['api.tap.company/v2/charges' => Http::response(['id' => 'chg_TS_same', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://same']])]);
        $cookie = $this->cartWith([$this->makeProduct('TD-FP-2', 40000)]);
        $result = $this->checkoutOrder($cookie);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/v1/checkout/orders/{$result['token']}/payment")->assertOk()->assertJsonPath('payment.payUrl', 'https://same');
        }

        // رسمٌ واحد أُنشئ (POST)؛ وكلّ إعادةٍ سألت Tap عن الرسم القائم (GET) قبل إعادته.
        $this->assertSame(1, $this->chargeCreations());
        $this->assertSame(1, Payment::query()->where('order_id', $result['order']->id)->where('provider', 'tap')->count());
    }

    // ── رُصد في الصندوق التجريبيّ (٢٠٢٦-٠٩-٢٥): رابطٌ قائمٌ عندنا ميتٌ عند Tap ──

    /**
     * طلبٌ بنيّة Tap قائمة وTap يُجيب عنها بالحالة المعطاة — بأشكالٍ رُصدت
     * فعلًا من Tap: `amount` عدد صحيح بوحدة الريال، و`merchant.id`، و`response.code`.
     *
     * @return array{Order, string, string}
     */
    private function orderWhoseLiveChargeIs(string $tapStatus, string $code): array
    {
        [$order, $charge] = $this->orderWithTapIntent();
        Payment::query()->where('intent_id', $charge)->update(['pay_url' => 'https://checkout.tap.company/old', 'intent_fingerprint' => null]);
        config(['services.payments.provider' => 'tap']);
        Http::fake([
            'api.tap.company/v2/charges/*' => Http::response([
                'id' => $charge, 'object' => 'charge', 'live_mode' => false, 'status' => $tapStatus, 'amount' => 400, 'currency' => 'SAR',
                'merchant' => ['country' => 'SA', 'currency' => 'SAR', 'id' => self::MERCHANT],
                'reference' => ['order' => $order->number], 'response' => ['code' => $code, 'message' => $tapStatus],
            ]),
            'api.tap.company/v2/charges' => Http::response([
                'id' => 'chg_TS_fresh', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.tap.company/fresh'],
            ]),
        ]);

        return [$order, $charge, OrderToken::make($order->number, $order->email)];
    }

    public function test_pay_again_over_a_charge_tap_cancelled_opens_a_fresh_charge_and_keeps_the_trail(): void
    {
        [$order, $charge, $token] = $this->orderWhoseLiveChargeIs('CANCELLED', '302');

        $this->postJson("/api/v1/checkout/orders/{$token}/payment")->assertOk()
            ->assertJsonPath('payment.payUrl', 'https://checkout.tap.company/fresh');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['reference']['idempotent'] === "tahdheer:tap:intent:{$order->id}:2");
        $row = Payment::query()->where('order_id', $order->id)->where('provider', 'tap')->firstOrFail();
        $this->assertSame(['chg_TS_fresh', 'CREATED', 2], [$row->intent_id, $row->state, (int) $row->intent_attempt]);
        $trail = PaymentEvent::query()->where('payment_id', $row->id)->where('event_type', 'intent.replaced')->firstOrFail();
        $this->assertSame($charge, $trail->payload['previous_charge'], 'الرسم الملغى يبقى في الأثر');
        $this->assertSame('FAILED', $trail->payload['previous_state']);
    }

    public function test_pay_again_over_a_charge_tap_already_captured_funds_the_order_and_charges_nothing(): void
    {
        [$order, , $token] = $this->orderWhoseLiveChargeIs('CAPTURED', '000');

        $this->postJson("/api/v1/checkout/orders/{$token}/payment")
            ->assertStatus(409)->assertJsonPath('code', 'ORDER_NOT_PAYABLE');

        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertSame('COMPLETED', $order->fresh()->status, 'الخطّاف ضاع، والزبون عاد — فموَّل السؤالُ الطلب');
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
    }

    public function test_pay_again_over_a_charge_still_open_at_tap_reuses_it(): void
    {
        [$order, , $token] = $this->orderWhoseLiveChargeIs('INITIATED', '100');

        $this->postJson("/api/v1/checkout/orders/{$token}/payment")->assertOk()
            ->assertJsonPath('payment.payUrl', 'https://checkout.tap.company/old');

        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertNotFunded($order, 'still open');
    }
}
