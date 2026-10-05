<?php

declare(strict_types=1);

namespace Tests\Feature\Risk;

use App\Domain\Admin\AdminRole;
use App\Domain\Risk\FraudGraph;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * تحليل العلاقات (§١٨): يكشف النمط ولا يحكم على أحد.
 *
 * ─── القانون المُثبَت هنا ───
 *
 *   قرارُ المخاطرة ≠ الحقيقة الماليّة.
 *
 * التحليل قراءةٌ محضة. شبهةٌ تُغيّر حالة دفعٍ تُفسد الدفتر بإشارةٍ ظنّيّة،
 * ثمّ لا يُعرف أيّ الأرقام حقيقة وأيّها تخمين.
 */
class FraudGraphTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private FraudGraph $graph;

    protected function setUp(): void
    {
        parent::setUp();
        $this->graph = app(FraudGraph::class);
    }

    private function customer(): int
    {
        $n = uniqid();

        return (int) DB::table('customers')->insertGetId([
            'email' => "c-{$n}@b.test", 'name' => 'عميل',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assessment(array $overrides = []): void
    {
        DB::table('risk_assessments')->insert($overrides + [
            'subject_type' => 'entitlement',
            'subject_id' => 1,
            'customer_id' => $this->customer(),
            'entitlement_id' => null,
            'decision' => 'ALLOW',
            'score' => 10,
            'signals' => json_encode([]),
            'ip_hash' => str_repeat('a', 64),
            'user_agent_hash' => str_repeat('b', 64),
            'created_at' => now(),
        ]);
    }

    private function order(int $customer, int $total, int $refunded, string $status = 'PAID'): Order
    {
        return Order::create([
            'number' => 'TD-F-'.uniqid(),
            'email' => 'a@b.test',
            'customer_id' => $customer,
            'status' => $status,
            'totals_gross_minor' => $total,
            'total_minor' => $total,
            'refunded_total_minor' => $refunded,
            'order_key_hash' => hash('sha256', uniqid()),
        ]);
    }

    // ── العناقيد ─────────────────────────────────────────────────────────

    public function test_one_ip_serving_many_customers_is_clustered(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assessment(['ip_hash' => str_repeat('f', 64)]);
        }

        $clusters = $this->graph->clusters();

        /*
         * تقييمٌ واحد يُقرأ وحده لا يكشف شيئًا. والربط هو ما كان ناقصًا —
         * البيانات كانت محفوظة منذ البداية.
         */
        $this->assertSame(3, $clusters['sharedIp'][0]['customers']);
        $this->assertSame(['SHARED_IP'], $clusters['sharedIp'][0]['reasonCodes']);
    }

    public function test_two_customers_on_one_ip_are_below_the_threshold(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->assessment(['ip_hash' => str_repeat('e', 64)]);
        }

        // عائلةٌ خلف عنوانٍ واحد سلوكٌ مشروع — العتبة تحمي من إدانتها.
        $this->assertSame([], $this->graph->clusters()['sharedIp']);
    }

    public function test_the_raw_address_hash_is_never_returned_in_full(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assessment(['ip_hash' => str_repeat('d', 64)]);
        }

        $hash = $this->graph->clusters()['sharedIp'][0]['ipHash'];

        // الكشف لا يتطلّب معرفة العنوان نفسه ولا تجزئته كاملة.
        $this->assertSame(12, mb_strlen($hash));
    }

    public function test_a_shared_device_is_clustered_separately_from_the_address(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assessment([
                'user_agent_hash' => str_repeat('c', 64),
                // عناوين مختلفة: الإشارة من الجهاز لا من الشبكة.
                'ip_hash' => hash('sha256', (string) $i),
            ]);
        }

        $clusters = $this->graph->clusters();

        $this->assertSame([], $clusters['sharedIp']);
        $this->assertSame(3, $clusters['sharedDevice'][0]['customers']);
    }

    public function test_refund_abuse_is_measured_as_a_rate_not_a_count(): void
    {
        $heavy = $this->customer();
        $this->order($heavy, 10000, 10000, 'REFUNDED');
        $this->order($heavy, 10000, 10000, 'REFUNDED');

        $loyal = $this->customer();
        for ($i = 0; $i < 10; $i++) {
            $this->order($loyal, 10000, $i < 2 ? 10000 : 0, $i < 2 ? 'REFUNDED' : 'PAID');
        }

        $abuse = $this->graph->clusters()['refundAbuse'];

        /*
         * من اشترى عشرةً وردّ اثنين ليس كمن اشترى اثنين وردّ اثنين. والعدد
         * المطلق وحده يُدين أكبر الزبائن.
         */
        $this->assertCount(1, $abuse);
        $this->assertSame($heavy, $abuse[0]['customerId']);
        $this->assertSame(1.0, $abuse[0]['refundRate']);
    }

    public function test_a_single_order_customer_is_not_flagged(): void
    {
        $customer = $this->customer();
        $this->order($customer, 10000, 10000, 'REFUNDED');

        // استردادٌ واحد على طلبٍ واحد حادثة لا نمط.
        $this->assertSame([], $this->graph->clusters()['refundAbuse']);
    }

    /** استحقاقٌ حقيقيّ: المفتاح الأجنبيّ يرفض معرّفًا مخترعًا — وهو محقّ. */
    private function entitlement(int $customer): int
    {
        $n = uniqid();
        $product = DB::table('products')->insertGetId([
            'sku' => "P-{$n}", 'slug' => "p-{$n}", 'title' => 'منتج',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = $this->order($customer, 10000, 0);
        $item = DB::table('order_items')->insertGetId([
            'order_id' => $order->id, 'product_id' => $product,
            'title_snapshot' => 'منتج', 'price_snapshot_minor' => 10000, 'quantity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('entitlements')->insertGetId([
            'customer_id' => $customer, 'order_id' => $order->id, 'order_item_id' => $item,
            'product_id' => $product, 'status' => 'ACTIVE', 'granted_at' => now(),
            'download_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_one_entitlement_downloaded_from_many_addresses_is_flagged(): void
    {
        $customer = $this->customer();
        $entitlement = $this->entitlement($customer);

        for ($i = 0; $i < 4; $i++) {
            DB::table('risk_assessments')->insert([
                'subject_type' => 'entitlement', 'subject_id' => $entitlement,
                'customer_id' => $customer, 'entitlement_id' => $entitlement,
                'decision' => 'ALLOW', 'score' => 20 + $i, 'signals' => json_encode([]),
                'ip_hash' => hash('sha256', 'ip'.$i), 'user_agent_hash' => str_repeat('b', 64),
                'created_at' => now(),
            ]);
        }

        $spread = $this->graph->clusters()['downloadAbuse'];

        // الملفّ يُشترى مرّةً ويُوزَّع — أقوى إشارات إساءة المحتوى الرقميّ.
        $this->assertSame(4, $spread[0]['distinctIps']);
        $this->assertSame(['DOWNLOAD_SPREAD'], $spread[0]['reasonCodes']);
    }

    // ── القانون: التحليل لا يمسّ المال ───────────────────────────────────

    public function test_the_analysis_changes_no_financial_state(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 10000, 10000, 'REFUNDED');
        $this->order($customer, 10000, 10000, 'REFUNDED');

        $before = DB::table('orders')->orderBy('id')->get()->toJson();
        $paymentsBefore = DB::table('payments')->count();

        $this->graph->clusters();

        /*
         * الشبهة لا تُغيّر دفترًا. وإلّا فلا يُعرف بعدها أيّ الأرقام حقيقة
         * وأيّها أثرُ تخمين — وهو أسوأ ما يقع لسجلٍّ ماليّ.
         */
        $this->assertSame($before, DB::table('orders')->orderBy('id')->get()->toJson());
        $this->assertSame($paymentsBefore, DB::table('payments')->count());
        $this->assertSame('REFUNDED', $order->refresh()->status);
    }

    public function test_the_result_declares_itself_advisory(): void
    {
        // من يقرأ الأرقام يجب أن يعرف أنّها اقتراحُ نظرٍ لا قرار.
        $this->assertTrue($this->graph->clusters()['advisory']);
    }

    // ── العقد ────────────────────────────────────────────────────────────

    public function test_the_endpoint_requires_order_read(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::SeoManager));
        $this->getJson('/api/v1/admin/operations/risk/clusters')->assertStatus(403);
    }

    public function test_the_endpoint_returns_every_cluster_kind(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/operations/risk/clusters')
            ->assertOk()
            ->assertJsonStructure(['windowDays', 'sharedIp', 'refundAbuse', 'downloadAbuse', 'sharedDevice', 'advisory']);
    }
}
