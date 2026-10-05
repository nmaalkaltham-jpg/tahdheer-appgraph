<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Insights\InsightsRegistry;
use App\Domain\Insights\Metric;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * طبقة التجميع الموحّدة — العقد الواحد، وقاعدة الصدق التي يفرضها.
 *
 * ─── ما يُحرَس هنا ───
 *
 * أخطر ما في لوحةٍ كهذه ليس رقمًا غائبًا بل رقمًا موجودًا بلا معنى. وقد وقع
 * الاثنان أثناء البناء: «معدّل هجر ١٠٠٪» من سلالٍ كلّها للضيوف لا يمكن الحكم
 * عليها، و«تحويل من سلّةٍ إلى طلب ١٨٦٪» من بسطٍ ومقامٍ لا رابط بينهما.
 * كلاهما يبدو معقولًا ويقود إلى قرار.
 *
 * فالاختبار الأوّل أدناه يمسح النطاقات كلّها ويفشل عند أيّ نسبةٍ فوق المئة،
 * مهما كان النطاق الذي أضافها. حارسٌ على القاعدة لا على حالةٍ بعينها.
 */
class InsightsTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function domain(string $domain, string $from = '2026-03-01', string $to = '2026-03-31'): TestResponse
    {
        return $this->getJson("/api/v1/admin/insights/{$domain}?from={$from}&to={$to}");
    }

    private function order(string $status, CarbonImmutable $at, int $total = 5000, ?int $customerId = null): Order
    {
        $order = Order::create([
            'number' => 'TD-I-'.uniqid(),
            'email' => 'a@b.test',
            'status' => $status,
            'customer_id' => $customerId,
            'totals_gross_minor' => $total,
            'total_minor' => $total,
            'vat_minor' => 0,
            'order_key_hash' => hash('sha256', uniqid()),
        ]);

        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $order;
    }

    private function customer(string $email): int
    {
        return DB::table('customers')->insertGetId([
            'email' => $email,
            'name' => 'عميل',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── القاعدة العامّة ──────────────────────────────────────────────────

    public function test_no_domain_can_return_an_impossible_percentage(): void
    {
        $customer = $this->customer('p@b.test');
        $this->order('COMPLETED', CarbonImmutable::parse('2026-03-10'), customerId: $customer);
        $this->order('AWAITING_PAYMENT', CarbonImmutable::parse('2026-03-11'));

        $cart = DB::table('carts')->insertGetId(['created_at' => CarbonImmutable::parse('2026-03-09'), 'updated_at' => now()]);
        $product = $this->makeProduct('INS-1');
        DB::table('cart_items')->insert([
            'cart_id' => $cart,
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price_snapshot_minor' => 4000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        foreach (app(InsightsRegistry::class)->names() as $domain) {
            foreach ($this->domain($domain)->assertOk()->json('metrics') as $metric) {
                if (($metric['state'] ?? null) !== 'MEASURED' || ($metric['format'] ?? null) !== 'percent') {
                    continue;
                }

                $this->assertLessThanOrEqual(
                    100,
                    $metric['value'],
                    "نسبةٌ فوق المئة في {$domain}.{$metric['key']} — بسطٌ ومقامٌ من مجموعتين مختلفتين.",
                );
            }
        }
    }

    public function test_every_registered_domain_answers(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $names = app(InsightsRegistry::class)->names();
        $this->assertNotEmpty($names);

        foreach ($names as $domain) {
            $body = $this->domain($domain)->assertOk()->json();

            $this->assertSame($domain, $body['domain']['key']);
            $this->assertNotEmpty($body['metrics'], "النطاق {$domain} لا يردّ مؤشّرًا واحدًا.");
        }
    }

    public function test_a_metric_is_never_both_measured_and_reasoned(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        foreach (app(InsightsRegistry::class)->names() as $domain) {
            foreach ($this->domain($domain)->assertOk()->json('metrics') as $m) {
                if ($m['state'] === 'MEASURED') {
                    /* قيمةٌ بلا صيغة رقمٌ لا يُقارَن، وسببٌ مع قيمةٍ حالتان في واحدة. */
                    $this->assertArrayHasKey('value', $m);
                    $this->assertArrayHasKey('formula', $m);
                    $this->assertArrayNotHasKey('reason', $m);
                } else {
                    // صفرٌ وهميّ هو الكذبة بعينها — الغائب لا يحمل قيمةً أصلًا.
                    $this->assertArrayNotHasKey('value', $m);
                    $this->assertNotEmpty($m['reason']);
                }
            }
        }
    }

    public function test_not_configured_list_mirrors_the_metrics(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $body = $this->domain('acquisition')->assertOk()->json();

        $declared = array_column($body['notConfigured'], 'key');
        $expected = array_column(
            array_values(array_filter($body['metrics'], fn ($m) => $m['state'] !== 'MEASURED')),
            'key',
        );

        // تُشتقّ في الخادم لا تُكتب مرّتين، فلا مؤشّر غائبٍ يفوت لوحة «ما ليس مُقاسًا».
        $this->assertSame($expected, $declared);
    }

    // ── نطاقاتٌ بعينها ───────────────────────────────────────────────────

    public function test_guest_carts_are_excluded_from_the_abandonment_denominator(): void
    {
        $product = $this->makeProduct('INS-2');

        foreach ([null, null, $this->customer('c@b.test')] as $owner) {
            $cart = DB::table('carts')->insertGetId([
                'customer_id' => $owner,
                'created_at' => CarbonImmutable::parse('2026-03-05'),
                'updated_at' => now(),
            ]);
            DB::table('cart_items')->insert([
                'cart_id' => $cart,
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price_snapshot_minor' => 4000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $metrics = collect($this->domain('carts')->assertOk()->json('metrics'))->keyBy('key');

        $this->assertSame(3, $metrics['cartsWithItems']['value']);
        $this->assertSame(2, $metrics['guestCarts']['value']);
        /*
         * المقام واحدٌ لا ثلاثة: سلّة الضيف لا تُربط بطلبٍ مهما تحوّلت، فعدّها
         * مهجورةً حكمٌ بالتعريف لا قياس — وهو ما أنتج «١٠٠٪» قبل الفصل.
         */
        $this->assertSame(1, $metrics['identifiedCarts']['value']);
        // `round()` تُعيد عددًا صحيحًا حين لا كسر — تُقارَن بالقيمة لا بالنوع.
        $this->assertEquals(100, $metrics['abandonmentRate']['value']);
    }

    public function test_the_abandonment_rate_has_no_denominator_when_every_cart_is_a_guest(): void
    {
        $product = $this->makeProduct('INS-3');
        $cart = DB::table('carts')->insertGetId(['created_at' => CarbonImmutable::parse('2026-03-05'), 'updated_at' => now()]);
        DB::table('cart_items')->insert([
            'cart_id' => $cart,
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price_snapshot_minor' => 4000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $metrics = collect($this->domain('carts')->assertOk()->json('metrics'))->keyBy('key');
        $this->assertSame('NO_DATA', $metrics['abandonmentRate']['state']);
    }

    public function test_customer_segments_sum_to_the_paying_population(): void
    {
        foreach (['a', 'b', 'c'] as $i => $letter) {
            $id = $this->customer("{$letter}@seg.test");
            $this->order('COMPLETED', CarbonImmutable::parse('2026-03-10'), 5000 * ($i + 1), $id);
            if ($letter !== 'a') {
                $this->order('COMPLETED', CarbonImmutable::parse('2026-03-12'), 5000, $id);
            }
        }

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $m = collect($this->domain('customers')->assertOk()->json('metrics'))->keyBy('key');

        $segments = ['segmentNew', 'segmentRepeat', 'segmentVip', 'segmentAtRisk', 'segmentDormant'];
        $sum = array_sum(array_map(fn (string $k) => $m[$k]['value'], $segments));

        /*
         * الشريحة حصريّة: عميلٌ يقع في اثنتين يجعل المجموع أكبر من العدد،
         * فتُقرأ اللوحة على أنّ العملاء أكثر ممّا هم.
         */
        $this->assertSame($m['payingCustomers']['value'], $sum);
    }

    public function test_the_funnel_refuses_to_divide_carts_by_orders(): void
    {
        // مرحلتان حقيقيّتان ليكون للنسبة السفلى مقام — وإلّا قِيس الغياب لا الرفض.
        $this->order('COMPLETED', CarbonImmutable::parse('2026-03-10'));
        $this->order('AWAITING_PAYMENT', CarbonImmutable::parse('2026-03-11'));

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $m = collect($this->domain('funnel')->assertOk()->json('metrics'))->keyBy('key');

        // لا عمود يربط السلّة بالطلب، فالنسبة بلا معنى مهما بدت معقولة.
        $this->assertSame('NOT_CONFIGURED', $m['cartToOrder']['state']);
        $this->assertSame('MEASURED', $m['orderToPaid']['state']);
    }

    public function test_ocr_is_measured_from_a_migrated_table_not_from_residue(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $m = collect($this->domain('ocr')->assertOk()->json('metrics'))->keyBy('key');

        /*
         * ─── ما كان يُختبَر، ولماذا تغيّر ───
         *
         * كان هذا الاختبار يشترط `NOT_CONFIGURED` للتغطية، وكان محقًّا حينها:
         * `asset_extractions` كان راسبًا في قاعدة التطوير بلا هجرةٍ تُنشئه،
         * فأيّ نسبةٍ تُحسب منه رقمٌ يختفي في أوّل نشرٍ نظيف.
         *
         * والشرط لم يكن «لا تقِس» بل «لا تقِس من راسب». والجدول اليوم مُهاجَرٌ
         * (`2026_09_23_000001_create_asset_extractions`) ويُنشئه `RefreshDatabase`
         * في كلّ تشغيل، فالقياس منه صار مشروعًا — والشرط الأصليّ يُحفظ بصيغته
         * الحقيقيّة: يُتحقَّق أنّ الجدول تصنعه الهجرات، ثمّ يُقبل القياس.
         */
        $this->assertTrue(
            Schema::hasTable('asset_extractions'),
            'التغطية تُقاس من جدولٍ يجب أن تُنشئه الهجرات — لا من راسبِ قاعدةِ تطوير.',
        );
        $this->assertNotSame(
            'NOT_CONFIGURED',
            $m['coverage']['state'],
            'خطُّ الاستخراج قائم، فإعلانُ المقياس «غير مهيّأ» يكذب على المشغّل.',
        );
        $this->assertSame('MEASURED', $m['assets']['state']);
    }

    // ── العقد والحراسة ──────────────────────────────────────────────────

    public function test_an_unknown_domain_is_refused_with_the_available_names(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/insights/nonsense')
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');
    }

    public function test_insights_require_analytics_read(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));
        $this->getJson('/api/v1/admin/insights/customers')->assertStatus(403);
    }

    public function test_the_index_lists_every_registered_domain(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $keys = array_column($this->getJson('/api/v1/admin/insights')->assertOk()->json('domains'), 'key');

        $this->assertSame(app(InsightsRegistry::class)->names(), $keys);
    }

    public function test_the_window_covers_the_whole_last_day(): void
    {
        $this->order('COMPLETED', CarbonImmutable::parse('2026-03-10 22:45:00'));

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $m = collect($this->domain('funnel', '2026-03-01', '2026-03-10')->assertOk()->json('metrics'))->keyBy('key');
        $this->assertSame(1, $m['ordersCreated']['value']);
    }

    public function test_a_ratio_with_a_zero_denominator_is_no_data_not_zero(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $m = collect($this->domain('refunds')->assertOk()->json('metrics'))->keyBy('key');

        $this->assertSame('NO_DATA', $m['refundRateByOrders']['state']);
        $this->assertArrayNotHasKey('value', $m['refundRateByOrders']);
    }

    public function test_the_metric_type_refuses_a_value_on_an_absent_state(): void
    {
        /*
         * حارسٌ على النوع نفسه لا على مُجمِّع: مصنعا الغياب لا يقبلان قيمةً
         * أصلًا، فلا سبيل إلى بناء «غير مهيّأ بقيمة صفر» ولو أراد كاتبٌ ذلك.
         */
        $absent = Metric::notConfigured('x', 'س', 'لا مصدر')->toArray();

        $this->assertArrayNotHasKey('value', $absent);
        $this->assertArrayNotHasKey('format', $absent);
        $this->assertSame('لا مصدر', $absent['reason']);
    }
}
