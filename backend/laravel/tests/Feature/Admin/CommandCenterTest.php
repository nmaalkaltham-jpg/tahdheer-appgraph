<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مركز التشغيل — العدّادات التي تقود إلى صفوفها، والحال الذي يُعلَن ولا يُخترع.
 *
 * ─── لماذا يُختبر هذا بالذات ───
 *
 * العدّاد الذي لا يفتح على صفوفه رقمٌ لا يُحقَّق فيه، والرقم الذي لا تطابقه
 * وجهتُه أسوأ من غياب الوجهة: المشغّل يعدّ الصفوف فلا تطابق العنوان فيفقد
 * الثقة في اللوحة كلّها. فكلّ اختبارٍ هنا يقيس **تطابق العدّاد بوجهته**، لا
 * وجود الحقل فحسب.
 *
 * ─── والإنذار الميّت ───
 *
 * كان عدّاد «محاولات دفعٍ فاشلة» يقرأ `payments.state = 'FAILED'` ولا كاتبَ
 * لهذه القيمة في المشروع كلّه، فكان صفرًا بنيويًّا مهما فشل من مدفوعات. هذا
 * أخطر من غياب العدّاد: شارةٌ خضراء دائمًا تطمئن كذبًا. الاختبار أدناه يفشل
 * إن عاد أحدٌ إلى قراءة العمود الميّت.
 */
class CommandCenterTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function order(string $status, CarbonImmutable $at, int $totalMinor = 5000): Order
    {
        $order = Order::create([
            'number' => 'TD-C-'.uniqid(),
            'email' => 'a@b.test',
            'status' => $status,
            'totals_gross_minor' => $totalMinor,
            'total_minor' => $totalMinor,
            'vat_minor' => 0,
            'order_key_hash' => hash('sha256', uniqid()),
        ]);

        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $order;
    }

    // ── الإنذار الميّت ───────────────────────────────────────────────────

    public function test_failed_payments_reads_the_gateway_log_not_the_dead_column(): void
    {
        /*
         * صفٌّ في `payments` بحالةٍ لا كاتبَ لها — لو كان العدّاد يقرؤها
         * لَعادت واحدًا. والحقيقة صفر: لا حدثَ فشلٍ في سجلّ البوّابة.
         */
        $order = $this->order('PAID', CarbonImmutable::now());
        DB::table('payments')->insert([
            'order_id' => $order->id,
            'provider' => 'fake',
            'state' => 'FAILED',
            'idempotency_key' => uniqid('k'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/ops/summary')
            ->assertOk()
            ->assertJsonPath('counters.failedPayments.value', 0);
    }

    public function test_failed_payments_counts_a_real_gateway_failure(): void
    {
        DB::table('webhook_deliveries')->insert([
            'provider' => 'tap',
            'dedupe_key' => uniqid('d'),
            'signature_verified' => true,
            'succeeded' => false,
            'status' => 'FAILED',
            'payload' => json_encode([]),
            'last_error' => 'التوقيع صحيح والمعالجة رمت',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/ops/summary')
            ->assertOk()
            ->assertJsonPath('counters.failedPayments.value', 1)
            ->assertJsonPath('counters.failedPayments.href', '/payments/webhooks')
            ->assertJsonPath('counters.failedPayments.alert', true);
    }

    // ── التسليم الرقميّ ──────────────────────────────────────────────────

    public function test_undelivered_counter_and_its_destination_agree(): void
    {
        $at = CarbonImmutable::now();
        $this->order('COMPLETED', $at);
        $this->order('PAID', $at);
        // غير مدفوع: لا يُعدّ، فالتسليم لا يُنتظر قبل وصول المال.
        $this->order('AWAITING_PAYMENT', $at);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $counter = $this->getJson('/api/v1/admin/ops/summary')->assertOk()
            ->json('counters.undeliveredOrders');

        $this->assertSame('MEASURED', $counter['state']);
        $this->assertSame(2, $counter['value']);

        /*
         * الوجهة تُنفَّذ فعلًا، ومجموعها يُقارن بالعدّاد. اختبارٌ يتحقّق من
         * نصّ الرابط وحده يمرّ بينما الرابط يعرض مجموعةً أخرى.
         */
        $this->getJson('/api/v1/admin/orders?view=undelivered')
            ->assertOk()
            ->assertJsonPath('meta.total', $counter['value']);
    }

    public function test_an_order_with_an_entitlement_is_not_counted_undelivered(): void
    {
        $at = CarbonImmutable::now();
        $order = $this->order('COMPLETED', $at);

        $customer = DB::table('customers')->insertGetId([
            'email' => 'c@b.test',
            'name' => 'عميل',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = $this->makeProduct('CC-1');
        $item = DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'price_snapshot_minor' => 5000,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('entitlements')->insert([
            'customer_id' => $customer,
            'order_id' => $order->id,
            'order_item_id' => $item,
            'product_id' => $product->id,
            'granted_at' => now(),
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/ops/summary')
            ->assertOk()
            ->assertJsonPath('counters.undeliveredOrders.value', 0);
    }

    // ── العدسات ─────────────────────────────────────────────────────────

    public function test_an_unknown_lens_is_refused_not_ignored(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        /*
         * الرفض لا التجاهل: عدسةٌ مجهولة تُتجاهَل تعني رابطًا يعرض كلّ
         * الطلبات وهو يدّعي قصرها، وهو بالضبط الكذب الذي تمنعه العدسات.
         */
        $this->getJson('/api/v1/admin/orders?view=nonsense')->assertStatus(422);
    }

    public function test_the_lens_and_the_range_narrow_the_status_tabs_too(): void
    {
        $this->order('COMPLETED', CarbonImmutable::parse('2026-03-10'));
        $this->order('COMPLETED', CarbonImmutable::parse('2026-06-10'));

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $body = $this->getJson('/api/v1/admin/orders?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json();

        $this->assertSame(1, $body['meta']['total']);
        // التبويب يعدّ داخل المدى، وإلّا قال «٢» فوق صفٍّ واحد.
        $this->assertSame(1, $body['statusCounts']['COMPLETED']);
    }

    public function test_the_range_includes_the_whole_last_day(): void
    {
        /*
         * تاريخٌ بلا وقتٍ يعني منتصف ليله. بلا `endOfDay` كان «إلى العاشر»
         * يُسقط طلبات العاشر كلّها — خطأٌ يبدو صحيحًا في كلّ يومٍ خالٍ.
         */
        $this->order('COMPLETED', CarbonImmutable::parse('2026-03-10 22:45:00'));

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/orders?from=2026-03-01&to=2026-03-10')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    // ── المؤشّرات ووجهاتها ───────────────────────────────────────────────

    public function test_every_kpi_destination_reproduces_its_own_number(): void
    {
        $at = CarbonImmutable::parse('2026-03-10');
        $this->order('COMPLETED', $at);
        $this->order('PAID', $at);
        $this->order('AWAITING_PAYMENT', $at);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $kpis = $this->getJson('/api/v1/admin/dashboard/summary?from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->json('kpis');

        $checked = 0;

        foreach ($kpis as $kpi) {
            // المؤشّرات الماليّة تقيس ريالاتٍ لا صفوفًا؛ العدّيّة وحدها تُقارن بمجموع الوجهة.
            if ($kpi['href'] === null || $kpi['format'] !== 'count') {
                continue;
            }

            $this->getJson('/api/v1/admin'.$kpi['href'])
                ->assertOk()
                ->assertJsonPath('meta.total', $kpi['value']);

            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'لا مؤشّر عدّيّ بوجهة — الاختبار لم يقس شيئًا.');
    }

    public function test_a_kpi_without_a_matching_list_carries_no_link(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $kpis = collect($this->getJson('/api/v1/admin/dashboard/summary')->assertOk()->json('kpis'))
            ->keyBy('key');

        /*
         * «عملاء جدد» بلا وجهة عمدًا: قائمة العملاء لا تقرأ مدًى من الرابط،
         * فوجهةٌ إليها كانت ستعرض كلّ العملاء حيث تقول البطاقة عملاء المدى.
         */
        $this->assertNull($kpis['newCustomers']['href']);
        $this->assertNotNull($kpis['orders']['href']);
    }

    // ── النسخ الاحتياطيّ ─────────────────────────────────────────────────

    public function test_backups_declare_themselves_unconfigured_rather_than_healthy(): void
    {
        config(['backups.path' => null]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $backups = $this->getJson('/api/v1/admin/system')->assertOk()->json('backups');

        $this->assertSame('NOT_CONFIGURED', $backups['state']);
        $this->assertNotEmpty($backups['reason']);
        // لا عمر ولا عدّ: حقلٌ رقميّ هنا يُقرأ «صفر ساعة» أي «نسخةٌ للتوّ».
        $this->assertArrayNotHasKey('ageSeconds', $backups);
        $this->assertArrayNotHasKey('count', $backups);
    }

    public function test_an_empty_backup_directory_is_no_data_not_a_missing_setting(): void
    {
        $dir = sys_get_temp_dir().'/tahdheer-backups-'.uniqid();

        if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Unable to create backup test directory: {$dir}");
        }
        config(['backups.path' => $dir]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        try {
            $this->getJson('/api/v1/admin/system')
                ->assertOk()
                ->assertJsonPath('backups.state', 'NO_DATA');
        } finally {
            // التنظيف في finally: إخفاقٌ كان يترك مجلّدًا في /tmp عند كلّ تشغيل.
            @rmdir($dir);
        }
    }

    public function test_a_stale_dump_is_measured_and_flagged(): void
    {
        $dir = sys_get_temp_dir().'/tahdheer-backups-'.uniqid();

        if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Unable to create backup test directory: {$dir}");
        }
        $file = $dir.'/nightly.sql';
        file_put_contents($file, '-- dump');
        touch($file, now()->subDays(3)->getTimestamp());
        config(['backups.path' => $dir]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $backups = $this->getJson('/api/v1/admin/system')->assertOk()->json('backups');

        $this->assertSame('MEASURED', $backups['state']);
        $this->assertSame(1, $backups['count']);
        $this->assertSame('nightly.sql', $backups['newest']['name']);
        $this->assertTrue($backups['stale']);

        unlink($file);
        rmdir($dir);
    }

    // ── وضع الأمان ───────────────────────────────────────────────────────

    public function test_failed_logins_are_now_measured_because_the_writer_was_built(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $security = $this->getJson('/api/v1/admin/system')->assertOk()->json('security');

        /*
         * كان هذا الاختبار يحرس إعلان «غير مهيّأ» — وكان صادقًا يومها: لا
         * جدول يحفظ المحاولات. ثمّ بُني الكاتب في `AuthController::login`،
         * فصار الإعلان نفسه كذبًا.
         *
         * والاختبار يتبع الحقيقة لا العكس: حراسةُ حالةِ غيابٍ بعد بنائها
         * تُجمّد النقص وتمنع إصلاحه.
         */
        $this->assertSame('MEASURED', $security['failedLogins']['state']);
        $this->assertArrayHasKey('last24h', $security['failedLogins']);
        $this->assertArrayHasKey('topTargets', $security['failedLogins']);
    }

    public function test_privileged_actions_count_only_the_last_day(): void
    {
        $admin = $this->makeAdmin(AdminRole::Owner);

        foreach ([['order.refunded', now()->subHours(2)], ['entitlement.revoked', now()->subHours(5)],
            ['order.refunded', now()->subDays(4)], ['product.updated', now()->subHour()]] as [$action, $at]) {
            DB::table('admin_audit_log')->insert([
                'admin_user_id' => $admin->id,
                'actor_email' => $admin->email,
                'action' => $action,
                'entity_type' => 'order',
                'entity_id' => '1',
                'created_at' => $at,
            ]);
        }

        $this->actingAsAdmin($admin);

        $recent = $this->getJson('/api/v1/admin/system')->assertOk()->json('security.privilegedActions24h');

        /*
         * اثنان: الاسترداد وسحب الصلاحية داخل اليوم. والاسترداد القديم خارج
         * النافذة، و«تعديل منتج» ليس فعلًا ذا امتياز — إدراجه كان سيغرق
         * الأفعال الخطرة في ضجيج العمل اليوميّ.
         */
        $this->assertSame(2, $recent['total']);
        $this->assertNotContains('product.updated', array_column($recent['byAction'], 'action'));
    }

    public function test_a_fake_payment_driver_is_reported_as_not_live(): void
    {
        config(['services.payments.provider' => 'fake']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/system')
            ->assertOk()
            ->assertJsonPath('security.posture.paymentProvider.resolved', 'fake')
            ->assertJsonPath('security.posture.paymentProvider.live', false);
    }
}
