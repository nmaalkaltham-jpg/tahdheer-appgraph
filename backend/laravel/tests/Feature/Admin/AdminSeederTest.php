<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Models\AdminUser;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * The owner account may never be created with a password the codebase knows.
 */
class AdminSeederTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private array $touched = [];

    private function setEnv(string $key, string $value): void
    {
        $this->touched[] = $key;
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    protected function tearDown(): void
    {
        foreach ($this->touched as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->touched = [];
        parent::tearDown();
    }

    public function test_production_without_a_supplied_password_refuses_to_seed(): void
    {
        $this->app['env'] = 'production';

        try {
            (new AdminUserSeeder)->run();
            $this->fail('The seeder must refuse to create an owner account in production without ADMIN_SEED_PASSWORD.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ADMIN_SEED_PASSWORD', $e->getMessage());
        }

        $this->assertSame(0, AdminUser::query()->count());
    }

    public function test_the_supplied_password_is_hashed_and_actually_logs_in(): void
    {
        $this->setEnv('ADMIN_SEED_EMAIL', 'owner@localhost');
        $this->setEnv('ADMIN_SEED_PASSWORD', 'كلمة-سر-من-البيئة-42');

        (new AdminUserSeeder)->run();

        $owner = AdminUser::query()->where('email', 'owner@localhost')->firstOrFail();
        $this->assertSame('owner', $owner->role->value);
        $this->assertNotSame('كلمة-سر-من-البيئة-42', $owner->password_hash);
        $this->assertTrue(Hash::check('كلمة-سر-من-البيئة-42', $owner->password_hash));

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'owner@localhost', 'password' => 'كلمة-سر-من-البيئة-42',
        ])->assertOk()->assertJsonPath('user.role', 'owner');
    }

    public function test_without_a_password_outside_production_the_generated_one_is_not_a_known_default(): void
    {
        $this->setEnv('ADMIN_SEED_EMAIL', 'generated@localhost');

        (new AdminUserSeeder)->run();

        $owner = AdminUser::query()->where('email', 'generated@localhost')->firstOrFail();

        foreach (['password', 'secret', 'admin', 'owner', '12345678', 'changeme', 'tahdheer'] as $guess) {
            $this->assertFalse(Hash::check($guess, $owner->password_hash), "Seeded password must not be «{$guess}».");
        }
    }

    public function test_reseeding_never_rewrites_an_existing_account(): void
    {
        $this->setEnv('ADMIN_SEED_EMAIL', 'owner@localhost');
        $this->setEnv('ADMIN_SEED_PASSWORD', 'الأولى-1');
        (new AdminUserSeeder)->run();
        $first = AdminUser::query()->where('email', 'owner@localhost')->firstOrFail()->password_hash;

        $this->setEnv('ADMIN_SEED_PASSWORD', 'الثانية-2');
        (new AdminUserSeeder)->run();

        $this->assertSame(1, AdminUser::query()->count());
        $this->assertSame($first, AdminUser::query()->where('email', 'owner@localhost')->firstOrFail()->password_hash);
        $this->assertFalse(Hash::check('الثانية-2', $first));
    }
}
