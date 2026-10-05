<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Http\Middleware\AdminAuthenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    public function test_admin_routes_reject_anonymous_callers_with_arabic_json_401(): void
    {
        $this->getJson('/api/v1/admin/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED')
            ->assertJsonPath('message', 'يلزم تسجيل الدخول للوصول إلى لوحة التشغيل.');

        $this->getJson('/api/v1/admin/products')->assertStatus(401);
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);
    }

    public function test_login_rejects_wrong_password_without_revealing_which_field_failed(): void
    {
        $admin = $this->makeAdmin(AdminRole::Owner);

        $wrongPassword = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email, 'password' => 'خاطئة',
        ]);
        $unknownEmail = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'nobody@localhost', 'password' => self::PASSWORD,
        ]);

        foreach ([$wrongPassword, $unknownEmail] as $response) {
            $response->assertStatus(401)
                ->assertJsonPath('code', 'INVALID_CREDENTIALS')
                ->assertJsonPath('message', 'بيانات الدخول غير صحيحة.');
        }

        $this->assertNull($admin->fresh()->last_login_at);
    }

    public function test_login_returns_identity_permissions_and_csrf_token_and_stamps_last_login(): void
    {
        $admin = $this->makeAdmin(AdminRole::ProductManager);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email, 'password' => self::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', $admin->email)
            ->assertJsonPath('user.role', 'product_manager')
            ->assertJsonPath('user.roleLabel', 'مدير المنتجات')
            ->assertJsonPath('permissions', AdminRole::ProductManager->permissions());

        $this->assertNotEmpty($response->json('csrfToken'));
        // The hash is never exposed, under any key.
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_session_carries_identity_to_me_and_logout_ends_it(): void
    {
        $admin = $this->makeAdmin(AdminRole::Owner);
        $this->actingAsAdmin($admin);

        $this->getJson('/api/v1/admin/me')
            ->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonPath('user.role', 'owner');

        $this->postJson('/api/v1/admin/auth/logout')->assertOk();

        $this->getJson('/api/v1/admin/me')->assertStatus(401);
    }

    public function test_mutating_request_without_the_csrf_header_is_refused(): void
    {
        $admin = $this->makeAdmin(AdminRole::Owner);
        $this->actingAsAdmin($admin);

        // Reading still works; only state changes require the token.
        $this->getJson('/api/v1/admin/products')->assertOk();

        $this->withHeader(AdminAuthenticate::CSRF_HEADER, 'رمز-مزيّف')
            ->postJson('/api/v1/admin/products', ['productNature' => 'DIGITAL', 'sku' => 'TD-X-1', 'slug' => 'td-x-1', 'title' => 'عنوان'])
            ->assertStatus(419)
            ->assertJsonPath('code', 'CSRF_MISMATCH');

        $this->assertDatabaseMissing('products', ['sku' => 'TD-X-1']);
    }

    public function test_validation_errors_are_returned_in_arabic(): void
    {
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'صيغة البريد الإلكتروني غير صحيحة.')
            ->assertJsonPath('errors.password.0', 'هذا الحقل مطلوب.');
    }
}
