<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Domain\Finance\SettlementReconciler;
use App\Domain\Payments\Models\Payment;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الحلقة الأخيرة: تحصيلٌ ← تسوية ← رسوم ← صافٍ (§P0).
 *
 * كانت السلسلة تنتهي عند التحصيل، فمتجرٌ يحصّل مئة ألفٍ شهريًّا لا يعرف كم
 * منها صار مالًا في يده.
 */
class SettlementReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private SettlementReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconciler = app(SettlementReconciler::class);
    }

    private function capture(int $amount = 10000, ?string $capturedAt = null): Payment
    {
        $order = Order::create([
            'number' => 'TD-T-'.uniqid(), 'email' => 'a@b.test', 'status' => 'PAID',
            'totals_gross_minor' => $amount, 'total_minor' => $amount,
            'order_key_hash' => hash('sha256', uniqid()),
        ]);

        return Payment::create([
            'order_id' => $order->id, 'provider' => 'tap',
            'intent_id' => 'chg_'.uniqid(), 'state' => 'CAPTURED',
            'idempotency_key' => 'tap:'.uniqid(),
            'amount_minor' => $amount, 'currency' => 'SAR',
            'captured_at' => $capturedAt ?? now(),
        ]);
    }

    private function settle(?Payment $payment, int $gross, int $fee, ?string $chargeRef = null): int
    {
        return (int) DB::table('settlements')->insertGetId([
            'provider' => 'tap',
            'charge_ref' => $chargeRef ?? ($payment?->intent_id ?? 'chg_orphan_'.uniqid()),
            'settlement_ref' => 'po_'.uniqid(),
            'payment_id' => $payment?->id,
            'gross_minor' => $gross, 'fee_minor' => $fee, 'net_minor' => $gross - $fee,
            'currency' => 'SAR', 'settled_at' => now(), 'source' => 'PROVIDER',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── التغطية قبل الحكم ────────────────────────────────────────────────

    public function test_with_no_settlements_at_all_nothing_is_flagged(): void
    {
        $this->capture(10000, now()->subMonth()->toDateTimeString());

        $result = $this->reconciler->run();

        /*
         * لا مصدر مربوط. والحكم بلا بيانات كان سيُنتج تنبيهًا عن كلّ تحصيل
         * في المتجر — فيُدرَّب المشغّل على إغلاق التنبيهات بلا قراءة.
         */
        $this->assertSame('NONE', $result['coverage']);
        $this->assertSame([], $result['findings']);
        $this->assertSame(1, $result['unsettledPayments']);
    }

    public function test_full_coverage_is_reported_when_every_capture_is_settled(): void
    {
        $payment = $this->capture(10000);
        $this->settle($payment, 10000, 250);

        $result = $this->reconciler->run();

        $this->assertSame('FULL', $result['coverage']);
        $this->assertSame([], $result['findings']);
    }

    // ── الفروق ───────────────────────────────────────────────────────────

    public function test_a_settlement_that_disagrees_with_its_capture_is_flagged(): void
    {
        $payment = $this->capture(10000);
        $this->settle($payment, 9000, 200);

        $finding = $this->reconciler->run()['findings'][0];

        $this->assertSame('AMOUNT_MISMATCH', $finding['code']);
        $this->assertSame(10000, $finding['expected']['grossMinor']);
        $this->assertSame(9000, $finding['actual']['grossMinor']);
        $this->assertSame('SETTLED', $finding['providerVerdict']);
        $this->assertSame('REVIEW_WITH_PROVIDER', $finding['repairAction']);
        $this->assertContains('payment#'.$payment->id, $finding['affectedEntities']);
    }

    public function test_an_unreasonable_fee_is_flagged(): void
    {
        $payment = $this->capture(10000);
        // ٢٠٪ رسومًا: خطأ إدخالٍ أو عملةٌ مختلفة أو رسمٌ غير متوقّع.
        $this->settle($payment, 10000, 2000);

        $finding = $this->reconciler->run()['findings'][0];

        $this->assertSame('FEE_MISMATCH', $finding['code']);
        $this->assertSame(0.2, $finding['actual']['feeRate']);
    }

    public function test_a_normal_fee_is_not_flagged(): void
    {
        $payment = $this->capture(10000);
        $this->settle($payment, 10000, 275);

        // ٢٫٧٥٪ رسوم بطاقاتٍ عاديّة — التنبيه عليها ضجيج.
        $this->assertSame([], $this->reconciler->run()['findings']);
    }

    public function test_a_capture_past_the_grace_period_without_settlement_is_flagged(): void
    {
        $settled = $this->capture(10000);
        $this->settle($settled, 10000, 250);

        $this->capture(5000, now()->subDays(30)->toDateTimeString());

        $codes = array_column($this->reconciler->run()['findings'], 'code');

        $this->assertContains('MISSING_SETTLEMENT', $codes);
    }

    public function test_a_recent_capture_is_not_yet_expected_to_settle(): void
    {
        $settled = $this->capture(10000);
        $this->settle($settled, 10000, 250);

        // التسوية تتأخّر أيّامًا بطبيعتها — المهلة تمنع إنذارًا سابقًا لأوانه.
        $this->capture(5000, now()->subDay()->toDateTimeString());

        $codes = array_column($this->reconciler->run()['findings'], 'code');
        $this->assertNotContains('MISSING_SETTLEMENT', $codes);
    }

    public function test_a_missing_settlement_does_not_claim_a_provider_verdict(): void
    {
        $settled = $this->capture(10000);
        $this->settle($settled, 10000, 250);
        $this->capture(5000, now()->subDays(30)->toDateTimeString());

        $finding = collect($this->reconciler->run()['findings'])
            ->firstWhere('code', 'MISSING_SETTLEMENT');

        /*
         * غيابُ الصفّ عندنا ليس نفيًا من المزوّد. وادّعاءُ حكمٍ لم يصدر عنه
         * يجعل المشغّل يتّهم البنك بلا دليل.
         */
        $this->assertSame('UNKNOWN', $finding['providerVerdict']);
        $this->assertSame('FETCH_FROM_PROVIDER', $finding['repairAction']);
    }

    public function test_two_settlements_for_one_charge_are_flagged(): void
    {
        $payment = $this->capture(10000);
        $this->settle($payment, 10000, 250, 'chg_same');
        $this->settle($payment, 10000, 250, 'chg_same');

        $codes = array_column($this->reconciler->run()['findings'], 'code');

        $this->assertContains('DUPLICATE_SETTLEMENT', $codes);
    }

    public function test_a_settlement_without_a_capture_is_an_orphan(): void
    {
        $payment = $this->capture(10000);
        $this->settle($payment, 10000, 250);
        $this->settle(null, 7000, 100);

        $finding = collect($this->reconciler->run()['findings'])
            ->firstWhere('code', 'ORPHAN_SETTLEMENT');

        // مالٌ وصل الحساب ولا دفعة تقابله — الخطّاف أوّل ما يُجرَّب.
        $this->assertSame('REPLAY_WEBHOOK', $finding['repairAction']);
        $this->assertSame(6900, $finding['evidence']['netMinor']);
    }

    // ── القيود في المحرّك ────────────────────────────────────────────────

    public function test_the_engine_refuses_a_settlement_that_does_not_add_up(): void
    {
        $payment = $this->capture(10000);

        // صافٍ ≠ إجماليّ − رسوم: صفٌّ لا يتّسق داخليًّا يُرفض عند الاستيراد.
        $this->expectException(QueryException::class);
        DB::table('settlements')->insert([
            'provider' => 'tap', 'charge_ref' => $payment->intent_id, 'settlement_ref' => 'po_bad',
            'payment_id' => $payment->id,
            'gross_minor' => 10000, 'fee_minor' => 250, 'net_minor' => 9999,
            'currency' => 'SAR', 'settled_at' => now(), 'source' => 'PROVIDER',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_engine_refuses_a_duplicate_settlement_row(): void
    {
        $payment = $this->capture(10000);
        $ref = 'po_fixed';

        DB::table('settlements')->insert([
            'provider' => 'tap', 'charge_ref' => $payment->intent_id, 'settlement_ref' => $ref,
            'payment_id' => $payment->id, 'gross_minor' => 10000, 'fee_minor' => 250, 'net_minor' => 9750,
            'currency' => 'SAR', 'settled_at' => now(), 'source' => 'PROVIDER',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // الاستيراد المكرّر لا يُنشئ تسويةً ثانية عن المال نفسه.
        $this->expectException(QueryException::class);
        DB::table('settlements')->insert([
            'provider' => 'tap', 'charge_ref' => $payment->intent_id, 'settlement_ref' => $ref,
            'payment_id' => $payment->id, 'gross_minor' => 10000, 'fee_minor' => 250, 'net_minor' => 9750,
            'currency' => 'SAR', 'settled_at' => now(), 'source' => 'PROVIDER',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
