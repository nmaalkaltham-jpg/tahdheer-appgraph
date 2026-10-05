<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Domain\Admin\AdminRole;
use App\Domain\Finance\Reconciliation;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Payments\Models\Payment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * التسوية (§١٧): ما أبلغ به المزوّد مقابل ما يقوله دفترنا.
 *
 * كانت مستحيلة بنيويًّا: `payments` بلا عمود مبلغ، فالمقارنة الوحيدة الممكنة
 * هي رقم الطلب بنفسه — وهي تقول «متطابق» دائمًا مهما اختلف الواقع.
 */
class ReconciliationTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private Reconciliation $reconcile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconcile = app(Reconciliation::class);
    }

    private function order(int $total = 10000, string $status = 'PAID'): Order
    {
        return Order::create([
            'number' => 'TD-X-'.uniqid(),
            'email' => 'a@b.test',
            'status' => $status,
            'totals_gross_minor' => $total,
            'total_minor' => $total,
            'order_key_hash' => hash('sha256', uniqid()),
        ]);
    }

    private function capture(Order $order, ?int $amount, string $currency = 'SAR'): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'provider' => 'fake',
            'intent_id' => 'chg_'.uniqid(),
            'state' => 'CAPTURED',
            'idempotency_key' => 'fake:'.uniqid(),
            'captured_at' => now(),
        ] + ($amount === null ? [] : ['amount_minor' => $amount, 'currency' => $currency]));
    }

    public function test_matching_amounts_reconcile_clean(): void
    {
        $this->capture($this->order(10000), 10000);

        $result = $this->reconcile->run();

        $this->assertTrue($result['clean']);
        $this->assertSame(1, $result['knownAmount']);
        $this->assertSame(0, $result['unknownAmount']);
    }

    public function test_an_amount_mismatch_is_detected_with_its_difference(): void
    {
        $order = $this->order(10000);
        $this->capture($order, 9500);

        $result = $this->reconcile->run();

        /*
         * خمسُ ريالاتٍ ناقصة عن طلبٍ واحد لا يلاحظها أحد. وعلى ألف طلبٍ
         * تصير خسارةً شهريّة لا مصدر لها في أيّ تقرير.
         */
        $this->assertFalse($result['clean']);
        $this->assertSame(-500, $result['amountMismatch'][0]['differenceMinor']);
        $this->assertSame($order->number, $result['amountMismatch'][0]['orderNumber']);
    }

    public function test_an_unknown_amount_is_counted_not_assumed_matching(): void
    {
        $this->capture($this->order(10000), null);

        $result = $this->reconcile->run();

        /*
         * عدُّها متطابقةً كذب، وعدُّها مختلفةً كذبٌ آخر. تُعلَن مجهولةً فيعرف
         * المشغّل حدود ما تُثبته التسوية.
         */
        $this->assertSame(1, $result['unknownAmount']);
        $this->assertSame([], $result['amountMismatch']);
        $this->assertTrue($result['clean']);
    }

    public function test_a_foreign_currency_capture_is_flagged_separately(): void
    {
        $this->capture($this->order(10000), 10000, 'USD');

        $result = $this->reconcile->run();

        // رقمان متطابقان ومبلغان مختلفان — وعلاجُه مراجعةُ إعداد لا ردّ فرق.
        $this->assertFalse($result['clean']);
        $this->assertSame('USD', $result['currencyMismatch'][0]['currency']);
        $this->assertSame([], $result['amountMismatch']);
    }

    public function test_two_captures_on_one_order_are_detected(): void
    {
        $order = $this->order(10000);
        $this->capture($order, 10000);
        $this->capture($order, 10000);

        $result = $this->reconcile->run();

        // إمّا خطأُ مزوّد وإمّا مالٌ أُخذ مرّتين — كلاهما يحتاج إنسانًا.
        $this->assertSame(2, $result['duplicateCapture'][0]['captures']);
        $this->assertSame(20000, $result['duplicateCapture'][0]['totalMinor']);
    }

    public function test_a_paid_order_without_a_capture_is_detected(): void
    {
        $order = $this->order(10000, 'PAID');

        $result = $this->reconcile->run();

        // سلّمنا بضاعةً ولا سجلّ دفعٍ يقابلها.
        $this->assertFalse($result['clean']);
        $this->assertSame($order->number, $result['paidWithoutCapture'][0]['orderNumber']);
    }

    public function test_a_capture_on_an_unpaid_order_is_detected(): void
    {
        $order = $this->order(10000, 'AWAITING_PAYMENT');
        $this->capture($order, 10000);

        $result = $this->reconcile->run();

        // مالٌ وصل ولم يُسلَّم شيء مقابله.
        $this->assertSame('AWAITING_PAYMENT', $result['orphanCapture'][0]['orderStatus']);
    }

    public function test_a_refund_beyond_the_reported_capture_is_detected(): void
    {
        $order = $this->order(10000);
        // المزوّد أبلغ بتحصيل ٩٠٠٠ بينما الطلب ١٠٠٠٠ — والقيد يقيس ضدّ الطلب.
        $this->capture($order, 9000);
        $order->forceFill(['refunded_total_minor' => 9500, 'status' => 'PARTIALLY_REFUNDED'])->save();

        $result = $this->reconcile->run();

        $this->assertSame(9500, $result['refundExceedsCapture'][0]['refundedMinor']);
        $this->assertSame(9000, $result['refundExceedsCapture'][0]['capturedMinor']);
    }

    public function test_an_entitlement_on_an_unpaid_order_is_detected(): void
    {
        $order = $this->order(10000, 'CANCELLED');
        $n = uniqid();
        $customer = DB::table('customers')->insertGetId([
            'email' => "c-{$n}@b.test", 'name' => 'عميل', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = DB::table('products')->insertGetId([
            'sku' => "P-{$n}", 'slug' => "p-{$n}", 'title' => 'منتج',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = DB::table('order_items')->insertGetId([
            'order_id' => $order->id, 'product_id' => $product,
            'title_snapshot' => 'منتج', 'price_snapshot_minor' => 10000, 'quantity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // صفٌّ سبق الحارس: يُكشف لأنّ الملفّ الذي نُزّل لا يعود.
        DB::table('entitlements')->insert([
            'customer_id' => $customer, 'order_id' => $order->id, 'order_item_id' => $item,
            'product_id' => $product, 'status' => 'ACTIVE', 'granted_at' => now(),
            'download_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->reconcile->run();

        $this->assertFalse($result['clean']);
        $this->assertSame(1, $result['counts']['unauthorizedEntitlements']);
        $this->assertSame('CANCELLED', $result['unauthorizedEntitlements'][0]['orderStatus']);
    }

    public function test_the_endpoint_requires_order_read(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::SeoManager));
        $this->getJson('/api/v1/admin/payments/reconciliation')->assertStatus(403);
    }

    public function test_the_endpoint_reports_the_verdict(): void
    {
        $this->capture($this->order(10000), 9000);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/payments/reconciliation')
            ->assertOk()
            ->assertJsonPath('clean', false)
            ->assertJsonPath('amountMismatch.0.differenceMinor', -1000);
    }

    /*
     * دُمج من PaidWithoutFulfillmentTest: الخدمة والتثبيتة نفسهما.
     */
    public function test_a_collected_order_with_no_fulfillment_unit_is_a_finding(): void
    {
        $n = uniqid();
        $product = Product::create(['sku' => "S-{$n}", 'slug' => "p-{$n}", 'title' => 'م', 'status' => 'published', 'fulfillment_type' => 'digital']);
        $order = Order::create(['number' => "TD-{$n}", 'email' => 'c@t.local', 'status' => 'PAID', 'total_minor' => 100, 'paid_at' => now()->subHour()]);
        $item = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'title_snapshot' => 'م', 'price_snapshot_minor' => 100, 'quantity' => 1]);

        $this->assertSame(1, app(Reconciliation::class)->run()['counts']['paidWithoutFulfillment']);

        FulfillmentUnit::create(['order_id' => $order->id, 'order_item_id' => $item->id, 'strategy' => 'DIGITAL_DOWNLOAD', 'sequence' => 0, 'idempotency_key' => "item:{$item->id}:DIGITAL_DOWNLOAD", 'status' => 'PENDING', 'pending_reason' => 'x']);

        $this->assertSame(0, app(Reconciliation::class)->run()['counts']['paidWithoutFulfillment']);
    }
}
