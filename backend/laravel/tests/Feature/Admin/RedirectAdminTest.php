<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Redirects\RedirectRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * إدارة التحويلات — والضمانتان اللتان يفرضهما النطاق.
 *
 * القاعدة الخاطئة لا تُصدر عطلًا: تُسقط ترتيب صفحةٍ في نتائج البحث وتُضيع
 * كلّ زائرٍ يصل من رابطٍ قديم، ولا يُكتشف ذلك إلّا بعد أسابيع.
 */
class RedirectAdminTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function rule(array $overrides = []): RedirectRule
    {
        return RedirectRule::create($overrides + [
            'from_path' => '/old-'.uniqid(),
            'to_path' => '/new',
            'status_code' => 301,
            'enabled' => true,
            'hits' => 0,
            // النسخة صريحةٌ في التثبيتة: الافتراض في المحرّك لا يصل إلى الكائن.
            'version' => 1,
        ]);
    }

    // ── الصلاحيات ────────────────────────────────────────────────────────

    public function test_the_list_requires_the_redirects_permission(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));
        $this->getJson('/api/v1/admin/redirects')->assertStatus(403);
    }

    public function test_the_seo_manager_owns_redirects(): void
    {
        // التحويلات أداة نقل المحتوى الأولى عند مدير SEO.
        $this->actingAsAdmin($this->makeAdmin(AdminRole::SeoManager));
        $this->getJson('/api/v1/admin/redirects')->assertOk();
    }

    // ── القراءة ──────────────────────────────────────────────────────────

    public function test_the_list_counts_enabled_and_fired_rules(): void
    {
        $this->rule(['enabled' => true, 'hits' => 5]);
        $this->rule(['enabled' => false, 'hits' => 0]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/redirects')
            ->assertOk()
            ->assertJsonPath('counts.all', 2)
            ->assertJsonPath('counts.enabled', 1)
            // «أطلقت» لا «موجودة»: قاعدةٌ بلا إصابةٍ واحدة قد تكون ميتة.
            ->assertJsonPath('counts.fired', 1);
    }

    public function test_a_wildcard_rule_is_flagged(): void
    {
        $this->rule(['from_path' => '/docs/*', 'to_path' => '/help']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/redirects')
            ->assertOk()
            ->assertJsonPath('data.0.isWildcard', true);
    }

    // ── الكتابة ──────────────────────────────────────────────────────────

    public function test_a_rule_is_created_and_audited(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/redirects', [
            'fromPath' => '/old', 'toPath' => '/new', 'idempotencyKey' => (string) Str::uuid(), 'statusCode' => 301,
        ])->assertStatus(201)->assertJsonPath('data.fromPath', '/old');

        $this->assertSame(1, DB::table('admin_audit_log')
            ->where('action', 'redirects.rule.created')->count());
    }

    public function test_a_self_redirect_is_refused_under_its_field(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        /*
         * الخطأ يصل تحت اسم الحقل لا شريطًا أحمر أعلى الصفحة: النموذج يُبرز
         * المدخل الذي يجب تغييره، فيعرف المشغّل ماذا يفعل.
         */
        $this->postJson('/api/v1/admin/redirects', [
            'fromPath' => '/loop', 'toPath' => '/loop', 'idempotencyKey' => (string) Str::uuid(), 'statusCode' => 301,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'SELF_REDIRECT')
            ->assertJsonStructure(['errors' => ['toPath']]);
    }

    public function test_a_closed_loop_is_refused(): void
    {
        $this->rule(['from_path' => '/b', 'to_path' => '/a']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // ‎/a → /b‎ و‎/b → /a‎ تُدوّر الزائر إلى الأبد.
        $this->postJson('/api/v1/admin/redirects', [
            'fromPath' => '/a', 'toPath' => '/b', 'idempotencyKey' => (string) Str::uuid(), 'statusCode' => 301,
        ])->assertStatus(422)->assertJsonPath('code', 'LOOP');
    }

    public function test_a_duplicate_source_is_refused(): void
    {
        $this->rule(['from_path' => '/taken', 'to_path' => '/x']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // مصدران لهدفين: أيُّهما يفوز؟ السؤال بلا جواب، فيُمنع.
        $this->postJson('/api/v1/admin/redirects', [
            'fromPath' => '/taken', 'toPath' => '/y', 'idempotencyKey' => (string) Str::uuid(), 'statusCode' => 301,
        ])->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_SOURCE')
            ->assertJsonStructure(['errors' => ['fromPath']]);
    }

    public function test_a_status_other_than_301_or_302_is_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/redirects', [
            'fromPath' => '/x', 'toPath' => '/y', 'idempotencyKey' => (string) Str::uuid(), 'statusCode' => 307,
        ])->assertStatus(422);
    }

    public function test_a_chain_is_collapsed_at_save_time(): void
    {
        $this->rule(['from_path' => '/middle', 'to_path' => '/final']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/redirects', [
            'fromPath' => '/start', 'toPath' => '/middle', 'idempotencyKey' => (string) Str::uuid(), 'statusCode' => 301,
        ])->assertStatus(201)
            /*
             * الهدف يُحلّ **وقت الحفظ** فيُخزَّن نهائيًّا: الزائر يقفز مرّةً
             * لا مرّتين. وقفزتان تُضاعفان زمن الوصول وتُضعفان إشارة SEO.
             */
            ->assertJsonPath('data.toPath', '/final');
    }

    public function test_updating_a_missing_rule_is_404(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->patchJson('/api/v1/admin/redirects/999999', [
            'fromPath' => '/a', 'toPath' => '/b', 'statusCode' => 301,
            'expectedVersion' => 1, 'idempotencyKey' => 'missing-rule',
        ])->assertStatus(404);
    }

    public function test_a_rule_can_be_disabled_without_deleting_it(): void
    {
        $rule = $this->rule(['from_path' => '/toggle', 'to_path' => '/dest']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->patchJson("/api/v1/admin/redirects/{$rule->id}", [
            'fromPath' => '/toggle', 'toPath' => '/dest', 'statusCode' => 301, 'enabled' => false,
            'expectedVersion' => (int) $rule->version, 'idempotencyKey' => 'toggle-1',
        ])->assertOk()->assertJsonPath('data.enabled', false)
            // النسخة تتقدّم بكلّ تحرير — وهي ما يُقارَن في التحرير التالي.
            ->assertJsonPath('data.version', (int) $rule->version + 1);

        $this->assertFalse((bool) $rule->fresh()->enabled);
    }

    public function test_deleting_a_rule_is_audited(): void
    {
        $rule = $this->rule();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->deleteJson("/api/v1/admin/redirects/{$rule->id}", [
            'expectedVersion' => (int) $rule->version,
        ])->assertOk();

        $this->assertNull(RedirectRule::query()->find($rule->id));
        $this->assertSame(1, DB::table('admin_audit_log')
            ->where('action', 'redirects.rule.deleted')->count());
    }

    public function test_deleting_a_missing_rule_is_404(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->deleteJson('/api/v1/admin/redirects/999999')->assertStatus(404);
    }

    // ── التزامن: لا سحق صامت ─────────────────────────────────────────────

    /**
     * محرِّران، نسخةٌ واحدة — والثاني يُرفض لا يُسحق.
     *
     * هذا هو العطل الذي انتهى: كان الثاني يكتب فوق الأوّل بلا رسالة، فيعود
     * المشغّل الأوّل بعد يومٍ ليجد تصحيحه غائبًا ولا أثر لسبب غيابه.
     */
    public function test_a_second_editor_is_refused_not_silently_overwritten(): void
    {
        $rule = $this->rule(['from_path' => '/race', 'to_path' => '/first']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->patchJson("/api/v1/admin/redirects/{$rule->id}", [
            'fromPath' => '/race', 'toPath' => '/winner', 'statusCode' => 301,
            'expectedVersion' => 1, 'idempotencyKey' => 'race-a',
        ])->assertOk();

        // النسخة نفسها: المحرِّر الثاني لم يرَ ما كتبه الأوّل.
        $this->patchJson("/api/v1/admin/redirects/{$rule->id}", [
            'fromPath' => '/race', 'toPath' => '/loser', 'statusCode' => 302,
            'expectedVersion' => 1, 'idempotencyKey' => 'race-b',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'STALE_VERSION')
            // الحاليّ يُرَدّ ليُقارَن — لا «حاول ثانيةً» بلا معلومة.
            ->assertJsonPath('conflict.current.toPath', '/winner')
            ->assertJsonPath('conflict.current.version', 2)
            ->assertJsonPath('conflict.expectedVersion', 1)
            ->assertJsonPath('conflict.actualVersion', 2);

        $this->assertSame('/winner', (string) $rule->fresh()->to_path);
        $this->assertSame(301, (int) $rule->fresh()->status_code);
    }

    public function test_an_update_without_a_version_is_refused(): void
    {
        $rule = $this->rule(['from_path' => '/noversion', 'to_path' => '/x']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // «بلا نسخة» يعني «اكتب فوق ما لا أعرفه» — وهو السلوك الذي انتهى.
        $this->patchJson("/api/v1/admin/redirects/{$rule->id}", [
            'fromPath' => '/noversion', 'toPath' => '/y', 'statusCode' => 301,
            'idempotencyKey' => 'no-version',
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['expectedVersion']]);

        $this->assertSame('/x', (string) $rule->fresh()->to_path);
    }

    /** إعادةُ إرسالٍ بالمفتاح نفسه لا تُنشئ صفًّا ثانيًا. */
    public function test_the_same_idempotency_key_creates_one_rule_only(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $body = [
            'fromPath' => '/dup', 'toPath' => '/dest', 'statusCode' => 301,
            'idempotencyKey' => 'create-once',
        ];

        $this->postJson('/api/v1/admin/redirects', $body)->assertStatus(201);

        // مهلةٌ عند الوكيل ثمّ نقرةٌ ثانية: الطلب نفسه، لا أثر جديد.
        $this->postJson('/api/v1/admin/redirects', $body)
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('data.fromPath', '/dup');

        $this->assertSame(1, RedirectRule::query()->where('from_path', '/dup')->count());
        $this->assertSame(1, DB::table('admin_audit_log')
            ->where('action', 'redirects.rule.created')->count());
    }

    /** المفتاح نفسه بجسمٍ مختلف: تعارضٌ لا إعادةُ جوابٍ كاذبة. */
    public function test_the_same_key_with_a_different_body_is_a_conflict(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/redirects', [
            'fromPath' => '/k1', 'toPath' => '/a', 'statusCode' => 301,
            'idempotencyKey' => 'shared-key',
        ])->assertStatus(201);

        $this->postJson('/api/v1/admin/redirects', [
            'fromPath' => '/k2', 'toPath' => '/b', 'statusCode' => 301,
            'idempotencyKey' => 'shared-key',
        ])->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame(0, RedirectRule::query()->where('from_path', '/k2')->count());
    }

    /** الرفض المحفوظ يُعاد كما هو — لا «عمليّةٌ قيد التنفيذ». */
    public function test_a_stored_refusal_is_replayed_verbatim(): void
    {
        $this->rule(['from_path' => '/occupied', 'to_path' => '/x']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $body = [
            'fromPath' => '/occupied', 'toPath' => '/y', 'statusCode' => 301,
            'idempotencyKey' => 'refused-once',
        ];

        $this->postJson('/api/v1/admin/redirects', $body)
            ->assertStatus(422)->assertJsonPath('code', 'DUPLICATE_SOURCE');

        $this->postJson('/api/v1/admin/redirects', $body)
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_SOURCE')
            ->assertJsonPath('duplicate', true);
    }

    public function test_deleting_with_a_stale_version_is_refused(): void
    {
        $rule = $this->rule(['from_path' => '/keep', 'to_path' => '/dest']);
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->patchJson("/api/v1/admin/redirects/{$rule->id}", [
            'fromPath' => '/keep', 'toPath' => '/moved', 'statusCode' => 301,
            'expectedVersion' => 1, 'idempotencyKey' => 'keep-edit',
        ])->assertOk();

        // حذفٌ بنسخةٍ قديمة يمحو تحريرًا وقع قبل ثوانٍ ولا يُبقي منه شيئًا.
        $this->deleteJson("/api/v1/admin/redirects/{$rule->id}", ['expectedVersion' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'STALE_VERSION');

        $this->assertNotNull(RedirectRule::query()->find($rule->id));
    }
}
