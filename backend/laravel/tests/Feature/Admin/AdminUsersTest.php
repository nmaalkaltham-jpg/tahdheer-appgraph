<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * إدارة مستخدمي اللوحة — والأقفال التي تمنع إغلاق الباب على الجميع.
 *
 * نظام الأدوار كان مفروضًا على كل مسار منذ اليوم الأوّل، والناقص بابُه: كانت
 * شاشة الإعدادات تقرّ بأنّ الحساب «يُنشأ من الخادم عبر AdminUserSeeder».
 *
 * وأهمّ ما يُختبَر هنا ليس الإنشاء بل **استحالة القفل**: كل طريق يُفضي إلى
 * متجرٍ بلا من يديره له اختبارٌ يثبت أنّ الخادم يرفضه.
 */
class AdminUsersTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private const NEW_PASSWORD = 'كلمة-مرور-جديدة-طويلة-7';

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'مشغّل جديد',
            'email' => 'new-operator@localhost',
            'password' => self::NEW_PASSWORD,
            'role' => AdminRole::OrderManager->value,
        ], $overrides);
    }

    /**
     * الفرق الذي انتظر نقطة نهاية.
     *
     * كان «المالك» و«مدير النظام» متطابقَين حرفيًّا لأنّ الصلاحية الوحيدة التي
     * تفصلهما لم تكن مفروضة في أي مسار. هذا الاختبار هو ما يجعل الفصل حقيقيًّا:
     * لو مُنحت `users.manage` لمدير النظام يومًا، سقط هذا الاختبار فورًا.
     */
    public function test_an_administrator_cannot_manage_users_only_the_owner_can(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Administrator))
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403)
            ->assertJsonPath('requiredPermission', 'users.manage');
    }

    public function test_the_owner_sees_the_roster_and_the_role_catalogue_from_the_server(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);
        $this->makeAdmin(AdminRole::Analytics);

        $response = $this->actingAsAdmin($owner)->getJson('/api/v1/admin/users')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertCount(count(AdminRole::cases()), $response->json('roles'));
        $this->assertSame(1, $response->json('meta.ownerCount'));
        $this->assertSame($owner->id, $response->json('meta.currentUserId'));

        // الأدوار تصل بصلاحياتها، فلا تكتب الواجهة نسخةً ثانية منها.
        $ownerRole = collect($response->json('roles'))->firstWhere('value', 'owner');
        $this->assertContains('users.manage', array_column($ownerRole['permissions'], 'key'));
    }

    /* ───── الإنشاء ───── */

    public function test_a_created_account_can_actually_sign_in_with_its_password(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);

        $created = $this->actingAsAdmin($owner)
            ->postJson('/api/v1/admin/users', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.role', 'order_manager');

        // لا تجزئة ولا كلمة مرور في أي ردّ.
        $this->assertStringNotContainsString('password', json_encode($created->json(), JSON_UNESCAPED_UNICODE));

        // والدليل القاطع أنّ التجزئة صحيحة: دورة دخول حقيقية بالحساب الجديد.
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'new-operator@localhost',
            'password' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'admin_user.created',
            'entity_type' => 'admin_user',
            'actor_email' => $owner->email,
        ]);
    }

    public function test_a_short_password_and_a_duplicate_email_are_refused(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);

        $this->actingAsAdmin($owner)
            ->postJson('/api/v1/admin/users', $this->payload(['password' => 'قصيرة']))
            ->assertStatus(422);

        $this->actingAsAdmin($owner)
            ->postJson('/api/v1/admin/users', $this->payload(['email' => $owner->email]))
            ->assertStatus(422);
    }

    /* ───── الأقفال الثلاثة ───── */

    public function test_you_cannot_delete_your_own_account(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);
        // مالك ثانٍ موجود، فالرفض سببه «نفسك» لا «آخر مالك».
        $this->makeAdmin(AdminRole::Owner, 'second-owner@localhost');

        $this->actingAsAdmin($owner)
            ->deleteJson("/api/v1/admin/users/{$owner->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'SELF_DELETE');

        $this->assertDatabaseHas('admin_users', ['id' => $owner->id]);
    }

    public function test_you_cannot_change_your_own_role(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);
        $this->makeAdmin(AdminRole::Owner, 'second-owner@localhost');

        $this->actingAsAdmin($owner)
            ->patchJson("/api/v1/admin/users/{$owner->id}", ['role' => AdminRole::ReadOnly->value])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SELF_ROLE_CHANGE');

        $this->assertSame(AdminRole::Owner, $owner->fresh()->role);
    }

    public function test_the_last_owner_can_be_neither_deleted_nor_demoted(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);
        $victim = $this->makeAdmin(AdminRole::Owner, 'doomed-owner@localhost');

        // بمالكَين: الحذف مسموح.
        $this->actingAsAdmin($owner)
            ->deleteJson("/api/v1/admin/users/{$victim->id}")
            ->assertOk();

        // وقد بقي واحد: كل طريق إلى صفر مالك مغلق.
        $this->actingAsAdmin($owner)
            ->deleteJson("/api/v1/admin/users/{$owner->id}")
            ->assertStatus(409);

        $second = $this->makeAdmin(AdminRole::Administrator, 'admin-2@localhost');
        $this->actingAsAdmin($second);
        // مدير النظام لا يملك الصلاحية أصلًا — فالخفض يُختبَر بمالك آخر.
        $this->actingAsAdmin($owner)
            ->patchJson("/api/v1/admin/users/{$owner->id}", ['role' => AdminRole::Analytics->value])
            ->assertStatus(409);

        $this->assertSame(1, AdminUser::query()->where('role', 'owner')->count());
    }

    /* ───── الدور وكلمة المرور ───── */

    public function test_a_role_change_is_audited_and_takes_effect_immediately(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);
        $target = $this->makeAdmin(AdminRole::ReadOnly);

        $this->actingAsAdmin($owner)
            ->patchJson("/api/v1/admin/users/{$target->id}", ['role' => AdminRole::ProductManager->value])
            ->assertOk()
            ->assertJsonPath('data.roleLabel', 'مدير المنتجات');

        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'admin_user.role_changed',
            'entity_id' => (string) $target->id,
        ]);

        // الصلاحية الجديدة مفروضة فورًا: الدور القديم لا ينشر منتجًا.
        $this->assertTrue($target->fresh()->allows('products.publish'));
    }

    public function test_a_password_reset_works_and_never_reaches_the_audit_log(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);
        $target = $this->makeAdmin(AdminRole::Analytics);

        $this->actingAsAdmin($owner)
            ->postJson("/api/v1/admin/users/{$target->id}/password", ['password' => self::NEW_PASSWORD])
            ->assertOk();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $target->email,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();

        // كلمة المرور القديمة لم تعد تعمل.
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $target->email,
            'password' => self::PASSWORD,
        ])->assertStatus(401);

        $entry = DB::table('admin_audit_log')->where('action', 'admin_user.password_reset')->first();
        $this->assertNotNull($entry);
        $this->assertNull($entry->before);
        $this->assertNull($entry->after);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, (string) json_encode($entry));
    }

    public function test_deleting_an_account_records_what_was_removed_before_it_is_gone(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);
        $target = $this->makeAdmin(AdminRole::SeoManager);

        $this->actingAsAdmin($owner)->deleteJson("/api/v1/admin/users/{$target->id}")->assertOk();

        $this->assertDatabaseMissing('admin_users', ['id' => $target->id]);

        $entry = DB::table('admin_audit_log')->where('action', 'admin_user.deleted')->first();
        $this->assertNotNull($entry);
        $this->assertStringContainsString($target->email, (string) $entry->before);
    }
}
