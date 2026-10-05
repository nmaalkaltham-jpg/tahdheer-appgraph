<?php

namespace Tests\Feature\Finance;

use App\Domain\Cases\CaseScanner;
use App\Domain\Cases\Models\WorkCase;
use App\Domain\Finance\Reconciliation;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Commerce\CommerceFixtures;
use Tests\TestCase;

/**
 * الكاشفات الجديدة تكشف فعلًا — وكلّ كشفٍ يصير حالةً لمشغّل.
 */
class ReconciliationCoverageTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    private function paidOrder(string $sku): Order
    {
        $cookie = $this->cartWith([$this->makeProduct($sku, 40000)]);
        $result = $this->checkoutOrder($cookie);
        $this->postJson("/api/v1/payments/fake/{$result['token']}/confirm")->assertOk();

        return $result['order']->fresh();
    }

    public function test_a_clean_paid_order_is_clean(): void
    {
        $this->paidOrder('TD-RC-0');

        $counts = app(Reconciliation::class)->run()['counts'];

        $this->assertSame(0, $counts['refundLedgerMismatch']);
        $this->assertSame(0, $counts['paidWithoutFulfillment']);
        $this->assertSame(0, $counts['orphanCapture']);
    }

    public function test_an_order_ledger_that_claims_a_refund_no_row_supports_is_a_critical_case(): void
    {
        $order = $this->paidOrder('TD-RC-1');
        // «مستردّ جزئيًّا» بلا استردادٍ محسوم: الطلب يقول للزبون ما لم يقع.
        DB::table('orders')->where('id', $order->id)->update(['refunded_total_minor' => 10000, 'status' => 'PARTIALLY_REFUNDED']);

        $recon = app(Reconciliation::class)->run();
        $this->assertSame(1, $recon['counts']['refundLedgerMismatch']);
        $this->assertSame(10000, $recon['refundLedgerMismatch'][0]['ledgerMinor']);
        $this->assertSame(0, $recon['refundLedgerMismatch'][0]['settledMinor']);

        app(CaseScanner::class)->scan();
        $case = WorkCase::query()->where('subject_type', 'order')->where('subject_id', $order->id)->firstOrFail();
        $this->assertSame(['REFUND_LEDGER_MISMATCH'], $case->reason_codes);
        $this->assertSame('CRITICAL', $case->severity);
    }

    public function test_money_in_with_nothing_delivered_is_a_critical_case(): void
    {
        $order = $this->paidOrder('TD-RC-2');
        DB::table('fulfillment_units')->where('order_id', $order->id)->delete();
        DB::table('orders')->where('id', $order->id)->update(['status' => 'PAID', 'paid_at' => now()->subMinutes(30)]);

        $recon = app(Reconciliation::class)->run();
        $this->assertSame(1, $recon['counts']['paidWithoutFulfillment']);

        app(CaseScanner::class)->scan();
        $this->assertSame(['PAID_WITHOUT_FULFILLMENT'], WorkCase::query()->where('subject_id', $order->id)->where('subject_type', 'order')->firstOrFail()->reason_codes);
    }

    public function test_the_verdict_is_not_clean_while_any_detector_fires(): void
    {
        $order = $this->paidOrder('TD-RC-3');
        DB::table('orders')->where('id', $order->id)->update(['refunded_total_minor' => 10000, 'status' => 'PARTIALLY_REFUNDED']);

        $this->assertFalse(app(Reconciliation::class)->run()['clean']);
    }
}
