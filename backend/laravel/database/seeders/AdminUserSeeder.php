<?php

namespace Database\Seeders;

use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the SINGLE owner account for the operations console.
 *
 * Run explicitly (it is intentionally not wired into DatabaseSeeder, so no
 * deploy ever creates an operator account as a side effect):
 *
 *   ADMIN_SEED_EMAIL=… ADMIN_SEED_PASSWORD=… php artisan db:seed --class=AdminUserSeeder
 *
 * There is NO hardcoded default password anywhere:
 *   · production without ADMIN_SEED_PASSWORD → refuses and aborts;
 *   · elsewhere without it → generates a random one and prints it once.
 * Re-running never rewrites an existing account's password.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = mb_strtolower(trim((string) env('ADMIN_SEED_EMAIL', 'owner@localhost')));
        $name = (string) env('ADMIN_SEED_NAME', 'مالك المتجر');
        $password = (string) env('ADMIN_SEED_PASSWORD', '');
        $generated = false;

        if ($password === '') {
            if (app()->environment('production')) {
                throw new RuntimeException(
                    'ADMIN_SEED_PASSWORD is required in production. Refusing to create an owner account '
                    .'without an explicitly supplied password — there is no default.',
                );
            }

            $password = Str::password(24);
            $generated = true;
        }

        if (AdminUser::query()->where('email', $email)->exists()) {
            $this->command?->warn("حساب المالك «{$email}» موجود مسبقًا — لم تُغيَّر كلمة المرور.");

            return;
        }

        AdminUser::query()->create([
            'email' => $email,
            'name' => $name,
            'password_hash' => Hash::make($password),
            'role' => AdminRole::Owner->value,
        ]);

        $this->command?->info("أُنشئ حساب المالك: {$email}");

        if ($generated) {
            $this->command?->warn('كلمة مرور عشوائية (تُعرض مرة واحدة فقط، غيّرها فورًا): '.$password);
        }
    }
}
