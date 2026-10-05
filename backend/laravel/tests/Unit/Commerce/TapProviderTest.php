<?php

namespace Tests\Unit\Commerce;

use App\Domain\Payments\InvalidWebhookSignature;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\ProviderRefundRejected;
use App\Domain\Payments\ProviderUnavailable;
use App\Domain\Payments\RefundOutcomeUnknown;
use App\Domain\Payments\RefundResult;
use App\Domain\Payments\TapProvider;
use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TapProviderTest extends TestCase
{
    private const SECRET = 'sk_test_secret';

    private const MERCHANT = '68052232';

    protected function setUp(): void
    {
        parent::setUp();
        // العقد الكامل: وضعٌ، ومفتاحُ بيئته، ومعرّفُ التاجر (بلا واحدٍ منها يرفض المزوّد العمل).
        config()->set('services.tap.mode', 'test');
        config()->set('services.tap.merchant_id', self::MERCHANT);
        config()->set('services.tap.test.secret_key', self::SECRET);
        config()->set('services.tap.webhook_secret', self::SECRET);
    }

    private function order(): Order
    {
        $order = new Order([
            'number' => 'TD-1042',
            'email' => 'noura@example.com',
            'phone' => '512345678',
            'total_minor' => 55250,
        ]);
        $order->id = 42;

        return $order;
    }

    public function test_create_intent_posts_charge_and_returns_pay_url(): void
    {
        Http::fake([
            'api.tap.company/v2/charges' => Http::response([
                'id' => 'chg_TS_123',
                'status' => 'INITIATED',
                'transaction' => ['url' => 'https://checkout.tap.company/pay/chg_TS_123'],
            ], 200),
        ]);

        $intent = (new TapProvider)->createIntent($this->order());

        $this->assertSame('tap', $intent->provider);
        $this->assertSame('chg_TS_123', $intent->intentId);
        $this->assertSame('requires_payment', $intent->status);
        $this->assertSame('https://checkout.tap.company/pay/chg_TS_123', $intent->payUrl);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.tap.company/v2/charges'
                && $request->hasHeader('Authorization', 'Bearer '.self::SECRET)
                && $request['amount'] === '552.50'
                && $request['currency'] === 'SAR'
                && $request['reference']['order'] === 'TD-1042'
                && $request['post']['url'] === url('/api/v1/payments/webhook/tap');
        });
    }

    public function test_create_intent_source_follows_the_requested_method(): void
    {
        Http::fake(['api.tap.company/v2/charges' => Http::response(['id' => 'chg_1', 'transaction' => ['url' => 'https://x']], 200)]);

        (new TapProvider)->createIntent($this->order());
        (new TapProvider)->createIntent($this->order(), 'apple_pay');

        $sources = Http::recorded()->map(fn ($pair) => $pair[0]['source']['id'])->all();
        $this->assertSame(['src_all', 'src_apple_pay'], $sources);
        // المبلغ والعملة لا يتغيّران بتغيّر الطريقة.
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['source']['id'] === 'src_apple_pay' && $r['amount'] === '552.50' && $r['currency'] === 'SAR');
    }

    public function test_create_intent_failure_throws(): void
    {
        Http::fake(['api.tap.company/*' => Http::response(['errors' => []], 401)]);

        $this->expectException(\RuntimeException::class);
        (new TapProvider)->createIntent($this->order());
    }

    /** @return array{0: array<string, mixed>, 1: string} payload + raw body */
    private function fixturePayload(): array
    {
        $payload = [
            'id' => 'chg_TS_123',
            'amount' => 552.50,
            'currency' => 'SAR',
            'status' => 'CAPTURED',
            'reference' => ['order' => 'TD-1042', 'gateway' => 'gw_1', 'payment' => 'pay_1'],
            'transaction' => ['created' => '1725000000000'],
        ];

        return [$payload, json_encode($payload, JSON_THROW_ON_ERROR)];
    }

    private function webhookRequest(string $body, string $signature): Request
    {
        return Request::create(
            '/api/v1/payments/webhook/tap',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_HASHSTRING' => $signature],
            content: $body,
        );
    }

    public function test_verify_webhook_accepts_signed_fixture(): void
    {
        [$payload, $body] = $this->fixturePayload();
        $signature = TapProvider::hashstring($payload, self::SECRET);

        $event = (new TapProvider)->verifyWebhook($this->webhookRequest($body, $signature));

        $this->assertSame('tap', $event->provider);
        $this->assertSame('chg_TS_123', $event->chargeId);
        $this->assertSame('TD-1042', $event->orderNumber);
        $this->assertTrue($event->succeeded);
    }

    public function test_verify_webhook_rejects_bad_signature(): void
    {
        [, $body] = $this->fixturePayload();

        $this->expectException(InvalidWebhookSignature::class);
        (new TapProvider)->verifyWebhook($this->webhookRequest($body, 'deadbeef'));
    }

    public function test_verify_webhook_rejects_tampered_payload(): void
    {
        [$payload] = $this->fixturePayload();
        $signature = TapProvider::hashstring($payload, self::SECRET);

        $payload['amount'] = 1.00; // attacker changes the amount
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->expectException(InvalidWebhookSignature::class);
        (new TapProvider)->verifyWebhook($this->webhookRequest($body, $signature));
    }

    private function capturedPayment(): Payment
    {
        $payment = new Payment(['provider' => 'tap', 'intent_id' => 'chg_TS_123', 'state' => 'CAPTURED']);
        $payment->id = 7;

        return $payment;
    }

    public function test_refund_posts_to_tap_with_the_idempotent_reference(): void
    {
        Http::fake([
            'api.tap.company/v2/refunds' => Http::response(['id' => 're_TS_1', 'status' => 'REFUNDED'], 200),
        ]);

        $result = (new TapProvider)->refund($this->capturedPayment(), 55250, 'SAR', 'refund:7', 'ملف تالف');

        $this->assertSame(RefundResult::SUCCEEDED, $result->status);
        $this->assertSame('re_TS_1', $result->providerRefundId);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.tap.company/v2/refunds'
            && $request->hasHeader('Authorization', 'Bearer '.self::SECRET)
            && $request['charge_id'] === 'chg_TS_123'
            && $request['amount'] === '552.50'
            && $request['currency'] === 'SAR'
            && $request['reference']['idempotent'] === 'refund:7'
            && $request['post']['url'] === url('/api/v1/payments/webhook/tap'));
    }

    public function test_refund_accepted_but_not_settled_is_pending(): void
    {
        Http::fake(['api.tap.company/v2/refunds' => Http::response(['id' => 're_TS_2', 'status' => 'PENDING'], 200)]);

        $result = (new TapProvider)->refund($this->capturedPayment(), 100, 'SAR', 'refund:8', null);

        $this->assertSame(RefundResult::PENDING, $result->status);
        $this->assertSame('re_TS_2', $result->providerRefundId);
    }

    public function test_refund_client_error_is_a_definitive_rejection(): void
    {
        Http::fake(['api.tap.company/v2/refunds' => Http::response(['errors' => [['description' => 'Amount exceeds charge']]], 400)]);

        $this->expectException(ProviderRefundRejected::class);
        (new TapProvider)->refund($this->capturedPayment(), 100, 'SAR', 'refund:9', null);
    }

    public function test_refund_server_error_is_an_unknown_outcome_not_a_failure(): void
    {
        Http::fake(['api.tap.company/v2/refunds' => Http::response('', 503)]);

        $this->expectException(RefundOutcomeUnknown::class);
        (new TapProvider)->refund($this->capturedPayment(), 100, 'SAR', 'refund:10', null);
    }

    public function test_refund_connection_failure_is_an_unknown_outcome(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->expectException(RefundOutcomeUnknown::class);
        (new TapProvider)->refund($this->capturedPayment(), 100, 'SAR', 'refund:11', null);
    }

    public function test_a_signed_refund_webhook_is_parsed_as_a_refund_event(): void
    {
        $payload = [
            'id' => 're_TS_1', 'object' => 'refund', 'charge_id' => 'chg_TS_123',
            'amount' => 552.5, 'currency' => 'SAR', 'status' => 'REFUNDED',
            'reference' => ['gateway' => 'g', 'payment' => 'p'], 'transaction' => ['created' => '1'],
        ];
        $request = Request::create('/api/v1/payments/webhook/tap', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HASHSTRING' => TapProvider::hashstring($payload, self::SECRET),
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));

        $event = (new TapProvider)->verifyWebhook($request);

        $this->assertTrue($event->isRefund());
        $this->assertSame('re_TS_1', $event->refundId);
        $this->assertSame('chg_TS_123', $event->chargeId);
        $this->assertTrue($event->succeeded);
    }

    private function signed(array $payload): Request
    {
        return Request::create('/api/v1/payments/webhook/tap', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HASHSTRING' => TapProvider::hashstring($payload, self::SECRET),
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_a_signed_capture_without_an_amount_fails_closed(): void
    {
        $event = (new TapProvider)->verifyWebhook($this->signed([
            'id' => 'chg_1', 'status' => 'CAPTURED', 'currency' => 'SAR', 'reference' => ['order' => 'TD-1'],
        ]));

        $this->assertNull($event->amountMinor);
        $this->assertSame('AMOUNT_MISSING', $event->amountProblem);
    }

    public function test_a_signed_capture_with_three_decimals_fails_closed(): void
    {
        $event = (new TapProvider)->verifyWebhook($this->signed([
            'id' => 'chg_1', 'status' => 'CAPTURED', 'amount' => '552.555', 'currency' => 'SAR', 'reference' => ['order' => 'TD-1'],
        ]));

        $this->assertSame('AMOUNT_PRECISION', $event->amountProblem);
        // ولا يُقرَّب إلى رقمٍ يبدو صالحًا: ٥٥٢٫٥٥٥ ليس ٥٥٢٥٦ هللة.
        $this->assertNull($event->amountMinor);
    }

    public function test_a_signed_capture_without_a_currency_fails_closed(): void
    {
        $event = (new TapProvider)->verifyWebhook($this->signed([
            'id' => 'chg_1', 'status' => 'CAPTURED', 'amount' => 552.5, 'reference' => ['order' => 'TD-1'],
        ]));

        $this->assertSame('CURRENCY_MISSING', $event->amountProblem);
    }

    public function test_retrieve_charge_classifies_every_documented_status(): void
    {
        $expect = [
            'CAPTURED' => 'CAPTURED', 'INITIATED' => 'OPEN',
            'ABANDONED' => 'FAILED', 'CANCELLED' => 'FAILED', 'FAILED' => 'FAILED', 'DECLINED' => 'FAILED',
            'RESTRICTED' => 'FAILED', 'VOID' => 'FAILED', 'TIMEDOUT' => 'FAILED',
            'UNKNOWN' => 'UNKNOWN', 'SOMETHING_NEW' => 'UNKNOWN',
        ];

        // Http::fake يُكدِّس البدائل ويطابق أوّلها؛ فبديلٌ واحد يقرأ الحالة من الهويّة.
        Http::fake(fn ($request) => Http::response([
            'id' => basename($request->url()), 'status' => substr(basename($request->url()), 4),
            'amount' => 552.5, 'currency' => 'SAR',
        ], 200));

        foreach ($expect as $tap => $ours) {
            $this->assertSame($ours, (new TapProvider)->retrieveCharge('chg_'.$tap)->status, $tap);
        }
    }

    public function test_retrieve_charge_reads_the_amount_exactly(): void
    {
        Http::fake(['api.tap.company/v2/charges/chg_1' => Http::response(['id' => 'chg_1', 'status' => 'CAPTURED', 'amount' => 10.05, 'currency' => 'sar'], 200)]);

        $charge = (new TapProvider)->retrieveCharge('chg_1');

        $this->assertSame(1005, $charge->amountMinor);
        $this->assertSame('SAR', $charge->currency);
        $this->assertNull($charge->amountProblem);
    }

    public function test_retrieve_charge_errors_are_unavailable_never_a_verdict(): void
    {
        Http::fake(fn ($request) => str_ends_with($request->url(), 'chg_badjson')
            ? Http::response('not json', 200)
            : Http::response(['errors' => []], (int) substr(basename($request->url()), 4)));

        foreach ([401, 404, 429, 500, 503] as $status) {
            try {
                (new TapProvider)->retrieveCharge('chg_'.$status);
                $this->fail("HTTP {$status} produced a verdict");
            } catch (ProviderUnavailable) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(ProviderUnavailable::class);
        (new TapProvider)->retrieveCharge('chg_badjson');
    }

    public function test_charge_amounts_are_sent_without_float_rounding(): void
    {
        Http::fake(['api.tap.company/v2/charges' => Http::response(['id' => 'chg_X', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://x']], 200)]);
        $order = $this->order();
        $order->total_minor = 1005;

        (new TapProvider)->createIntent($order);

        Http::assertSent(fn ($request) => $request['amount'] === '10.05');
    }

    // ── أشكالٌ رُصدت في الصندوق التجريبيّ (٢٠٢٦-٠٩-٢٥) — معرّفاتٌ منقّاة ──

    /** كائن استردادٍ بشكل Tap الحقيقيّ: `created` في الأعلى، و`transaction` بلا `created`. */
    private function observedRefundObject(): array
    {
        return [
            'id' => 're_TS_FIXTURE_REFUND_1', 'object' => 'refund', 'api_version' => 'V2', 'live_mode' => false,
            'amount' => 5, 'charge_id' => 'chg_TS_FIXTURE_CHARGE_1', 'created' => '1790286968190', 'date' => ['created' => 1790286968190],
            'currency' => 'SAR', 'status' => 'REFUNDED',
            'reference' => ['id' => '000000000000000001', 'gateway' => '123456789', 'payment' => 'activity_TS_FIXTURE_1', 'acquirer' => '000000000001', 'merchant' => 'refund:2', 'idempotent' => 'refund:2'],
            'response' => ['code' => '000', 'message' => 'Refunded'],
            'transaction' => ['authorization_id' => '000001', 'timezone' => 'UTC+03:00', 'asynchronous' => false, 'amount' => 5, 'currency' => 'SAR'],
            'merchant' => ['country' => 'SA', 'currency' => 'SAR', 'id' => '68052232'],
        ];
    }

    public function test_the_refund_signature_uses_the_top_level_created_as_observed(): void
    {
        $refund = $this->observedRefundObject();

        // التسلسل الموثّق، مبنيًّا يدويًّا من حقول الاسترداد الحقيقيّة.
        $expected = hash_hmac('sha256',
            'x_idre_TS_FIXTURE_REFUND_1'.'x_amount5.00'.'x_currencySAR'.'x_gateway_reference123456789'
            .'x_payment_referenceactivity_TS_FIXTURE_1'.'x_statusREFUNDED'.'x_created1790286968190',
            self::SECRET);

        $this->assertSame($expected, TapProvider::hashstring($refund, self::SECRET));

        $event = (new TapProvider)->verifyWebhook($this->signed($refund));
        $this->assertTrue($event->isRefund());
        $this->assertSame(['re_TS_FIXTURE_REFUND_1', 'chg_TS_FIXTURE_CHARGE_1', 500, 'SAR'], [$event->refundId, $event->chargeId, $event->amountMinor, $event->currency]);
        $this->assertTrue($event->succeeded);
    }

    public function test_charge_signatures_still_use_transaction_created(): void
    {
        $charge = ['id' => 'chg_x', 'amount' => 15, 'currency' => 'SAR', 'status' => 'CAPTURED',
            'reference' => ['gateway' => 'g', 'payment' => 'p'], 'transaction' => ['created' => '111'], 'created' => '999'];
        $expected = hash_hmac('sha256', 'x_idchg_xx_amount15.00x_currencySARx_gateway_referencegx_payment_referencepx_statusCAPTUREDx_created111', self::SECRET);

        $this->assertSame($expected, TapProvider::hashstring($charge, self::SECRET));
    }

    public function test_refunds_nested_in_a_charge_are_read_in_the_observed_shape(): void
    {
        $charge = ['id' => 'chg_x', 'status' => 'CAPTURED', 'amount' => 15, 'currency' => 'SAR',
            'refunds' => ['refund' => [
                ['id' => 're_A', 'amount' => 5, 'currency' => 'SAR', 'created' => '1', 'date' => [], 'status' => 'REFUNDED'],
                ['id' => 're_B', 'amount' => 3, 'currency' => 'sar', 'created' => '2', 'date' => [], 'status' => 'REFUNDED'],
            ]]];

        $this->assertSame([
            ['id' => 're_A', 'amountMinor' => 500, 'currency' => 'SAR', 'status' => 'REFUNDED'],
            ['id' => 're_B', 'amountMinor' => 300, 'currency' => 'SAR', 'status' => 'REFUNDED'],
        ], TapProvider::chargeRefunds($charge));

        // عنصرٌ مفرد (غير قائمة)، وغيابُ الحقل، وعناصرُ بلا هويّة.
        $this->assertCount(1, TapProvider::chargeRefunds(['refunds' => ['refund' => ['id' => 're_C', 'amount' => 1, 'currency' => 'SAR', 'status' => 'REFUNDED']]]));
        $this->assertSame([], TapProvider::chargeRefunds(['status' => 'CAPTURED']));
        $this->assertSame([], TapProvider::chargeRefunds(['refunds' => ['refund' => [['amount' => 1]]]]));
    }
}
