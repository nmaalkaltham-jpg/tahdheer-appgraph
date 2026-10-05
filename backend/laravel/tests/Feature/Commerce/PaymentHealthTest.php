<?php

namespace Tests\Feature\Commerce;

use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\PaymentHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PaymentHealthTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    public function test_a_clean_ledger_raises_no_alert(): void
    {
        $this->assertSame([], app(PaymentHealth::class)->alerts(app(PaymentHealth::class)->snapshot()));
    }

    public function test_an_unfundable_capture_is_counted_and_alerts(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-PH-1', 40000)]);
        $order = $this->checkoutOrder($cookie)['order'];
        $body = json_encode(['charge_id' => 'chg_ph', 'order_number' => $order->number, 'status' => 'CAPTURED', 'amount_minor' => 1, 'currency' => 'SAR'], JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/v1/payments/webhook/fake', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body)], content: $body)->assertOk();

        $health = app(PaymentHealth::class);
        $metrics = $health->snapshot();

        $this->assertSame(1, $metrics['captures_unfundable']);
        $this->assertSame(1, $metrics['webhooks_mismatch']);
        $alerts = collect($health->alerts($metrics))->keyBy('code');
        $this->assertSame('CRITICAL', $alerts['captures_unfundable']['severity']);
        $this->assertSame('CRITICAL', $alerts['unfunded_money_open']['severity']);
    }

    public function test_the_command_logs_structured_metrics_without_secrets(): void
    {
        config(['services.tap.test.secret_key' => 'REDACTED_PUBLIC_TEST_CREDENTIAL']);
        Log::spy();

        $this->artisan('payments:health --json')->assertSuccessful();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) {
            return $message === 'payments.health'
                && array_key_exists('captures_funded', $context)
                && ! str_contains(json_encode($context), 'sk_test_');
        })->once();
    }

    public function test_the_money_jobs_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command.' @ '.$e->expression)->implode("\n");

        $this->assertStringContainsString('payments:requery', $commands);
        $this->assertStringContainsString('payments:health', $commands);
        $this->assertStringContainsString('cases:scan', $commands);
        $this->assertStringContainsString('outbox:process', $commands);
        $this->assertMatchesRegularExpression('/payments:requery[\'"]? @ \*\/5 \* \* \* \*/', $commands);
    }
}
