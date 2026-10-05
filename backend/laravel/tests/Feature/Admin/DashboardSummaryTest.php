<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ملخّص لوحة التشغيل وعدّادات العمليّات.
 *
 * ─── لماذا يُختبر هذا بالذات ───
 *
 * هذه أوّل شاشةٍ يفتحها المشغّل في نوبته، وأرقامُها تُبنى عليها قرارات. ورقمٌ
 * خاطئ هنا لا يُصدر عطلًا: يبدو معقولًا فيُصدَّق. فالاختبارات تُثبّت ثلاثة
 * أشياء تحديدًا: أنّ المدى يُحترم، وأنّ المقارنة بمدًى مساوٍ، وأنّ ما لا
 * يُقاس يُعلَن ولا يُرَدّ صفرًا.
 */
class DashboardSummaryTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function order(string $status, int $totalMinor, CarbonImmutable $at, int $vat = 0): Order
    {
        $order = Order::create([
            'number' => 'TD-D-'.uniqid(),
            'email' => 'a@b.test',
            'status' => $status,
            'totals_gross_minor' => $totalMinor,
            'total_minor' => $totalMinor,
            'vat_minor' => $vat,
            'order_key_hash' => hash('sha256', uniqid()),
        ]);

        // `created_at` يُضبط بعد الإنشاء: الطابع التلقائيّ يسبق قصدنا.
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $order;
    }

    private function summary(string $from, string $to): TestResponse
    {
        return $this->getJson("/api/v1/admin/dashboard/summary?from={$from}&to={$to}");
    }

    // ── الصلاحيات ────────────────────────────────────────────────────────

    public function test_the_summary_requires_analytics_read(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));
        $this->summary('2026-01-01', '2026-01-31')->assertStatus(403);
    }

    public function test_the_counters_require_orders_read(): void
    {
        /*
         * `orders.read` لا `analytics.read`: هذه ملاحةُ نوبةٍ لا تقرير أرقام،
         * ومن يعالج الطلبات يجب أن يرى ما ينتظره.
         */
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));
        $this->getJson('/api/v1/admin/ops/summary')->assertOk();
    }

    // ── المدى ────────────────────────────────────────────────────────────

    public function test_only_orders_inside_the_range_are_counted(): void
    {
        $inside = CarbonImmutable::parse('2026-03-10 12:00:00');
        $outside = CarbonImmutable::parse('2026-02-10 12:00:00');

        $this->order('COMPLETED', 10000, $inside);
        $this->order('COMPLETED', 99900, $outside);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $response = $this->summary('2026-03-01', '2026-03-31')->assertOk();
        $kpis = collect($response->json('kpis'))->keyBy('key');

        $this->assertSame(1, $kpis['orders']['value']);
        $this->assertSame(10000, $kpis['grossSales']['value']);
    }

    /**
     * المقارنة بمدًى **مساوٍ** يسبقه مباشرةً.
     *
     * لو قُورن أسبوعٌ بشهرٍ لظهر هبوطٌ لم يقع — وهو أسوأ من غياب المقارنة،
     * لأنّه رقمٌ يبدو ذا معنى.
     */
    public function test_the_previous_period_is_the_same_length_immediately_before(): void
    {
        $this->order('COMPLETED', 20000, CarbonImmutable::parse('2026-03-05 10:00:00'));
        $this->order('COMPLETED', 10000, CarbonImmutable::parse('2026-02-26 10:00:00'));

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // ١–٧ مارس ⇦ السابق ٢٢–٢٨ فبراير بالضبط.
        $response = $this->summary('2026-03-01', '2026-03-07')->assertOk();

        $response->assertJsonPath('range.days', 7)
            ->assertJsonPath('range.previousFrom', fn ($v) => str_starts_with((string) $v, '2026-02-22'))
            ->assertJsonPath('range.previousTo', fn ($v) => str_starts_with((string) $v, '2026-02-28'));

        $kpis = collect($response->json('kpis'))->keyBy('key');
        $this->assertSame(20000, $kpis['grossSales']['value']);
        $this->assertSame(10000, $kpis['grossSales']['previous']);
        // ١٠٠٪ تُرسَل عددًا صحيحًا في JSON — المقارنة بالقيمة لا بالنوع.
        $this->assertEquals(100, $kpis['grossSales']['changePercent']);
    }

    /**
     * مقامٌ صفريّ ⇦ لا نسبة.
     *
     * «+١٠٠٪» على مدًى سابقٍ فارغ اختراع: النموّ من صفرٍ غير معرَّف.
     */
    public function test_a_zero_previous_period_yields_no_percentage(): void
    {
        $this->order('COMPLETED', 20000, CarbonImmutable::parse('2026-03-05 10:00:00'));
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $kpis = collect($this->summary('2026-03-01', '2026-03-07')->json('kpis'))->keyBy('key');

        $this->assertNull($kpis['grossSales']['changePercent']);
    }

    /** غير المدفوع لا يدخل المال، ويدخل عدّ «كلّ الطلبات». */
    public function test_unpaid_orders_are_excluded_from_money_but_counted_separately(): void
    {
        $at = CarbonImmutable::parse('2026-03-05 10:00:00');
        $this->order('COMPLETED', 10000, $at);
        $this->order('AWAITING_PAYMENT', 50000, $at);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $kpis = collect($this->summary('2026-03-01', '2026-03-31')->json('kpis'))->keyBy('key');

        $this->assertSame(10000, $kpis['grossSales']['value']);
        $this->assertSame(1, $kpis['orders']['value']);
        $this->assertSame(2, $kpis['allOrders']['value']);
        $this->assertSame(1, $kpis['needsAction']['value']);
    }

    /** الصافي = الإجماليّ ناقص الضريبة ناقص المستردّ. */
    public function test_net_sales_subtracts_vat_and_refunds(): void
    {
        $at = CarbonImmutable::parse('2026-03-05 10:00:00');
        $order = $this->order('COMPLETED', 11500, $at, vat: 1500);
        // ردٌّ جزئيّ حقيقيّ: المبلغ والحالة معًا، كما يكتبهما `RefundService`.
        DB::table('orders')->where('id', $order->id)->update(['refunded_total_minor' => 1000, 'status' => 'PARTIALLY_REFUNDED']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $kpis = collect($this->summary('2026-03-01', '2026-03-31')->json('kpis'))->keyBy('key');

        $this->assertSame(11500, $kpis['grossSales']['value']);
        $this->assertSame(9000, $kpis['netSales']['value']);
        $this->assertSame(1000, $kpis['refunded']['value']);
    }

    // ── السلسلة ──────────────────────────────────────────────────────────

    /**
     * صفٌّ لكلّ يوم، ومجموعُها يطابق المؤشّر.
     *
     * سلسلةٌ بفجوات ترسم خطًّا يقفز فوق أيّامٍ بلا مبيعات فيبدو المدى أقصر
     * ممّا هو. والتعبئة بالأصفار هنا **مقيسة** لا مخترعة: الجدول استُعلم
     * والمدى معلوم، فاليوم الخالي يومٌ بلا طلبات.
     */
    public function test_the_series_has_one_row_per_day_and_sums_to_the_kpi(): void
    {
        $this->order('COMPLETED', 10000, CarbonImmutable::parse('2026-03-02 10:00:00'));
        $this->order('COMPLETED', 30000, CarbonImmutable::parse('2026-03-02 18:00:00'));
        $this->order('CANCELLED', 30000, CarbonImmutable::parse('2026-03-06 18:00:00'));

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $body = $this->summary('2026-03-01', '2026-03-07')->json();

        $this->assertCount(7, $body['series']);
        $this->assertSame('2026-03-01', $body['series'][0]['date']);
        $this->assertSame(0, $body['series'][0]['orders']);
        $this->assertSame(2, $body['series'][1]['orders']);
        $this->assertSame(40000, $body['series'][1]['grossMinor']);

        $kpis = collect($body['kpis'])->keyBy('key');
        $this->assertSame(
            $kpis['allOrders']['value'],
            array_sum(array_column($body['series'], 'orders')),
        );
    }

    // ── الصدق ────────────────────────────────────────────────────────────

    /** ما لا مصدر له يُعلَن بسببه ولا يُرَدّ صفرًا. */
    public function test_unmeasurable_metrics_are_declared_not_zeroed(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $body = $this->summary('2026-03-01', '2026-03-07')->json();

        $keys = array_column($body['notConfigured'], 'key');
        $this->assertContains('conversionRate', $keys);
        $this->assertContains('visitors', $keys);

        foreach ($body['notConfigured'] as $item) {
            $this->assertArrayNotHasKey('value', $item, 'المؤشّر غير المُقاس لا يحمل قيمة.');
            $this->assertNotSame('', $item['reason']);
        }

        $this->assertNotContains('conversionRate', array_column($body['kpis'], 'key'));
    }

    /**
     * الكمّيّة الغائبة تُقال `null` لا صفرًا.
     *
     * `order_items.quantity` فارغٌ في صفوفٍ قائمة. وصفرٌ هنا يجعل منتجًا
     * مبيعًا يبدو غير مبيع — ويُحذف من قرار إعادة الطلب.
     */
    public function test_top_products_report_unknown_quantity_as_null(): void
    {
        $at = CarbonImmutable::parse('2026-03-05 10:00:00');
        $order = $this->order('COMPLETED', 10000, $at);

        $productId = DB::table('products')->insertGetId([
            'slug' => 'probe-'.uniqid(),
            'sku' => 'PROBE-'.uniqid(),
            'title' => 'منتجٌ للاختبار',
            'status' => 'published',
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => $productId,
            'title_snapshot' => 'منتجٌ للاختبار',
            'price_snapshot_minor' => 10000,
            'quantity' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $top = $this->summary('2026-03-01', '2026-03-31')->json('topProducts');

        $this->assertCount(1, $top);
        $this->assertSame(1, $top[0]['orders']);
        $this->assertNull($top[0]['units']);
        $this->assertNull($top[0]['revenueMinor']);
    }

    // ── عدّادات العمليّات ────────────────────────────────────────────────

    public function test_counters_are_measured_aggregates(): void
    {
        $at = CarbonImmutable::now();
        $this->order('AWAITING_PAYMENT', 5000, $at);
        $this->order('PROCESSING', 5000, $at);
        $this->order('COMPLETED', 5000, $at);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/ops/summary')
            ->assertOk()
            ->assertJsonPath('counters.ordersNeedingAction.state', 'MEASURED')
            ->assertJsonPath('counters.ordersNeedingAction.value', 2)
            ->assertJsonPath('counters.ordersNeedingAction.href', '/orders');
    }
}
