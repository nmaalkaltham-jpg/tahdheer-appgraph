<?php

namespace Tests\Feature\Operations;

use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Operations\OperationLedger;
use App\Domain\Operations\ReconcilerRegistry;
use App\Domain\Operations\Reconcilers\WebhookReplayReconciler;
use App\Domain\Operations\StaleOperationRecovery;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\WebhookDelivery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إعادة تشغيل خطّافٍ مات عاملُها: يُسأل الدفتر لا المزوّد.
 */
class WebhookReplayReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(string $chargeId): WebhookDelivery
    {
        return WebhookDelivery::create([
            'provider' => 'fake', 'dedupe_key' => $chargeId.':CAPTURED', 'charge_id' => $chargeId,
            'order_number' => 'TD-'.$chargeId, 'signature_verified' => true, 'succeeded' => true,
            'status' => 'FAILED', 'last_error' => 'تعذّر', 'payload' => ['charge_id' => $chargeId, 'status' => 'CAPTURED'],
            'attempts' => 1, 'received_at' => now(),
        ]);
    }

    private function deadReplay(WebhookDelivery $delivery): OperationRecord
    {
        $ledger = app(OperationLedger::class);
        [$record] = $ledger->reserve("webhook_replay:{$delivery->id}:0", 'webhook_replay', 'webhook_delivery', $delivery->id);
        $ledger->acquire($record, 'worker-dead', 1);
        $ledger->markProcessing($record);
        OperationRecord::query()->whereKey($record->id)->update(['lease_expires_at' => now()->subMinute()]);

        return $record;
    }

    private function recovery(): StaleOperationRecovery
    {
        return new StaleOperationRecovery(app(OperationLedger::class), new ReconcilerRegistry([new WebhookReplayReconciler]));
    }

    public function test_a_replay_that_captured_the_payment_is_confirmed_not_repeated(): void
    {
        $delivery = $this->delivery('chg_r1');
        $order = Order::create(['number' => 'TD-chg_r1', 'email' => 'c@t.local', 'status' => 'PAID', 'total_minor' => 100]);
        Payment::create(['order_id' => $order->id, 'provider' => 'fake', 'intent_id' => 'chg_r1', 'state' => 'CAPTURED', 'idempotency_key' => 'fake:chg_r1']);
        $record = $this->deadReplay($delivery);

        $tally = $this->recovery()->run();

        $this->assertSame(1, $tally['confirmed']);
        $this->assertSame('SUCCEEDED', $record->fresh()->status);
    }

    public function test_a_replay_that_left_no_trace_is_marked_safe_to_retry(): void
    {
        $record = $this->deadReplay($this->delivery('chg_r2'));

        $tally = $this->recovery()->run();

        $this->assertSame(1, $tally['absent']);
        $this->assertSame('FAILED', $record->fresh()->status);
        $this->assertSame('TEMPORARY', $record->fresh()->failure_class);
    }
}
