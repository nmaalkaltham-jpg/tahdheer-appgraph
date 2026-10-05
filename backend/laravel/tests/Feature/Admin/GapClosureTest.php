<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Queueing\QueueDepth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * فجواتٌ أُغلقت — كلّ اختبارٍ هنا كان يمرّ على حالةٍ كاذبة.
 *
 * ─── لماذا هذا الملفّ ───
 *
 * مسحٌ مستقلّ وجد إحدى وعشرين حالةً تقول للمشغّل «غير مهيّأ» أو «غير مقيس»
 * بينما الشيء مبنيٌّ وموصول. وهذه أخطر من النقص الصريح: بوّابةٌ بائتة تُفقد
 * الثقة في كلّ بوّابةٍ أخرى، فيتجاهل المشغّل الإعلان الصادق حين يأتي.
 */
class GapClosureTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    // ── محاولات الدخول الفاشلة ──────────────────────────────────────────

    public function test_a_failed_login_is_recorded_with_its_target_and_source(): void
    {
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'ghost@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        /*
         * لم يكن يُسجَّل شيء: حملة تخمينٍ كاملة تمرّ بلا أثرٍ يُقرأ بعدها،
         * والحماية الوحيدة مُحدِّد معدّلٍ في الذاكرة لا يُستعلَم.
         */
        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'admin_user.login_failed',
            'actor_email' => 'ghost@example.test',
            'reason' => 'بريد غير معروف',
        ]);
    }

    public function test_a_wrong_password_on_a_real_account_is_distinguished_in_the_log_only(): void
    {
        $admin = $this->makeAdmin(AdminRole::Owner, 'real@example.test');

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'definitely-not-it',
        ])->assertStatus(401);

        // الردّ لا يميّز — وإلّا كشف أيّ الحسابات موجود لمن يمسح.
        $this->assertSame('INVALID_CREDENTIALS', $response->json('code'));

        // والسجلّ يميّز، حيث يخدم التحقيق ولا يخدم المهاجم.
        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'admin_user.login_failed',
            'reason' => 'كلمة مرور خاطئة',
        ]);
    }

    public function test_the_security_panel_reports_failed_logins_as_measured(): void
    {
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'a@b.test', 'password' => 'x'])->assertStatus(401);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $failed = $this->getJson('/api/v1/admin/system')->assertOk()->json('security.failedLogins');

        // كان `NOT_CONFIGURED` — وبقاؤه بعد بناء الكاتب كان سيصير كذبًا.
        $this->assertSame('MEASURED', $failed['state']);
        $this->assertGreaterThanOrEqual(1, $failed['last24h']);
        $this->assertSame(10, $failed['alertAfter']);
    }

    public function test_a_successful_login_writes_no_failure_row(): void
    {
        $admin = $this->makeAdmin(AdminRole::Owner, 'good@example.test');
        $this->actingAsAdmin($admin);

        $this->assertDatabaseMissing('admin_audit_log', ['action' => 'admin_user.login_failed']);
    }

    // ── نقرات البحث ─────────────────────────────────────────────────────

    public function test_search_click_through_is_measured_not_declared_missing(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $body = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json();

        /*
         * الإعلان كان يقول «لا يوجد مسار يسجّل النقرات بعد»، والمسار مبنيّ
         * (`POST /search/click`) ويناديه المتجر. فبقاؤه يُخفي مقياسًا يعمل.
         */
        $this->assertNotContains('searchClickThrough', array_column($body['notConfigured'], 'key'));
        $this->assertArrayHasKey('clickedQueries', $body['measured']['search']);
    }

    public function test_click_through_has_no_ratio_without_a_denominator(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $search = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('measured.search');

        // لا بحث جرى: صفرٌ هنا يُقرأ «بحثٌ لا يُنقر عليه» وهي تهمةٌ للفهرس.
        $this->assertSame(0, $search['queriesLogged']);
        $this->assertNull($search['clickThroughRate']);
        $this->assertNull($search['meanClickPosition']);
    }

    public function test_a_real_click_produces_a_real_ratio(): void
    {
        $product = $this->makeProduct('CTR-1');

        /*
         * صفّان بأعمدةٍ متطابقة: الإدراج الجماعيّ في PostgreSQL يبني قائمة
         * VALUES واحدة، فصفٌّ ينقصه مفتاح يُنتج «VALUES lists must all be the
         * same length». النقر الغائب يُكتب `null` صراحةً.
         */
        DB::table('search_queries')->insert([
            ['raw_query' => 'أ', 'normalized_query' => 'أ', 'results_count' => 3, 'zero_result' => false, 'clicked_product_id' => $product->id, 'position' => 2, 'created_at' => now()],
            ['raw_query' => 'ب', 'normalized_query' => 'ب', 'results_count' => 1, 'zero_result' => false, 'clicked_product_id' => null, 'position' => null, 'created_at' => now()],
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $search = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('measured.search');

        $this->assertSame(2, $search['queriesLogged']);
        $this->assertSame(1, $search['clickedQueries']);
        $this->assertSame(0.5, $search['clickThroughRate']);
        // `round()` تردّ عددًا صحيحًا حين لا كسر — تُقارَن بالقيمة لا بالنوع.
        $this->assertEquals(2, $search['meanClickPosition']);
    }

    // ── عمق الطابور ─────────────────────────────────────────────────────

    /*
     * تطابقُ `queue.monitored` مع `queue:work --queue=` في ملفّات compose لا
     * يُقاس من هنا: الحاوية لا تُركّب إلّا `backend/laravel`، فكان الاختبار
     * يُتخطّى في كلّ تشغيل ويبدو تغطيةً. يفرضه الآن فحص `queues` في
     * scripts/guards/guard-infra-refs.mjs، ومعه أنّ كلّ طابورٍ تُرسل إليه
     * مهمّةٌ يستهلكه العامل.
     */

    public function test_an_unknown_driver_is_declared_not_measurable_never_zero(): void
    {
        config(['queue.default' => 'sqs']);

        $summary = QueueDepth::summary();

        // صفرٌ هنا يُقرأ «لا شيء عالق» وهو أسوأ جوابٍ حين يكون الطابور ممتلئًا.
        $this->assertSame('NOT_MEASURABLE', $summary['state']);
        $this->assertNull($summary['pending']);
        $this->assertNotEmpty($summary['reason']);
    }

    public function test_the_system_screen_and_the_ai_panel_agree_on_the_driver(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $system = $this->getJson('/api/v1/admin/system')->assertOk()->json('queue');
        $ai = $this->getJson('/api/v1/admin/ai')->assertOk()->json('ai.queue');

        /*
         * كانت شاشة النظام تقول «غير مقيس» تحت Redis بينما لوحة الذكاء تعرض
         * رقمًا من المخزن نفسه. شاشتان تتناقضان في السؤال نفسه تُفقدان الثقة
         * في كليهما.
         */
        $this->assertSame($system['driver'], $ai['driver']);
        $this->assertSame($system['status'], $ai['state']);
    }
}
