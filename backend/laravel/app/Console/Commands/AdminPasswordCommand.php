<?php

namespace App\Console\Commands;

use App\Domain\Admin\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * ضبطُ كلمة مرور حسابٍ إداريّ قائم — أداةُ تطويرٍ محلّيّ لا غير.
 *
 * ─── العطل الذي وُلد منه ───
 *
 * `AdminUserSeeder` **يُنشئ ولا يُحدِّث**: إن وُجد الحساب انصرف وحذّر
 * «لم تُغيَّر كلمة المرور». وهذا ضمانٌ مقصود — بذرةٌ تُعاد لا يجوز أن
 * تدهس كلمةَ مرورٍ قائمة — لكنّه يترك حالةً لا مخرج منها: قاعدةٌ محلّيّة
 * فيها `owner@localhost` بتجزئةٍ لا يعرفها أحد، فلا `npm run local:seed`
 * يُصلحها ولا أمرٌ آخر. وقيس ذلك فعلًا: الدخولُ اليدويّ والدخولُ التلقائيّ
 * للتطوير كلاهما يردّ `INVALID_CREDENTIALS`.
 *
 * فهذا الأمر هو المخرج المفقود: يضبط التجزئة لحسابٍ **قائم** بالآليّة
 * نفسها التي يتحقّق بها الدخول (`Hash::make` مقابل `Hash::check` في
 * `AuthController`)، بلا لمسِ وسيطٍ ولا حارسٍ ولا تحقّق.
 *
 * ─── وحدوده ───
 *
 * · لا يعمل في الإنتاج بتاتًا: `production` تُرفَض قبل أيّ عمل. ليس بابًا
 *   خلفيًّا ولا يصير واحدًا بمتغيّر بيئة.
 * · لا يُنشئ حسابًا: الإنشاء وظيفةُ البذرة وحدها، وحسابٌ يُخترع من سطر
 *   أوامرٍ بصلاحيّة مالكٍ أخطرُ من كلمة مرورٍ منسيّة.
 * · لا يطبع كلمة المرور: يقولها المشغّل أو تأتي من `ADMIN_SEED_PASSWORD`،
 *   وطباعتُها تضعها في سجلّ الصدفة وفي لقطات الشاشة.
 * · حتميّ: البيئةُ نفسها تعطي الاعتمادَ نفسه في كلّ نسخةٍ من المستودع.
 */
class AdminPasswordCommand extends Command
{
    protected $signature = 'admin:password
        {email? : بريدُ الحساب — الافتراضيّ ADMIN_SEED_EMAIL ثمّ owner@localhost}
        {--password= : كلمةُ المرور — الافتراضيّ ADMIN_SEED_PASSWORD}';

    protected $description = 'يضبط كلمة مرور حسابٍ إداريّ قائم (تطويرٌ محلّيّ فقط — يرفض الإنتاج)';

    public function handle(): int
    {
        /*
         * الحارسُ أوّلًا وقبل قراءة أيّ مُدخَل: الرفض يجب ألّا يعتمد على
         * شكل ما مُرِّر، بل على البيئة وحدها.
         */
        if (app()->environment('production')) {
            $this->error('admin:password أداةُ تطويرٍ محلّيّ — لا تعمل في الإنتاج.');
            $this->line('لتدوير اعتماد الإنتاج استعمل مسارَ التدوير المعتمد لا سطرَ أوامر.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim(
            (string) ($this->argument('email') ?? env('ADMIN_SEED_EMAIL', 'owner@localhost')),
        ));

        $password = (string) ($this->option('password') ?? env('ADMIN_SEED_PASSWORD', ''));

        if ($password === '') {
            $this->error('لا كلمة مرور: مرِّر --password=… أو اضبط ADMIN_SEED_PASSWORD.');

            return self::FAILURE;
        }

        /*
         * حدٌّ أدنى لهذا الأمر وحده — `AuthController` لا يفرض طولًا على
         * الدخول (‏`required|string|max:255`‏). والغرض منع اعتمادٍ تافه
         * يُضبط بالخطأ ثمّ يُنسى على جهازٍ مكشوف؛ لا مطابقةَ قاعدةٍ قائمة.
         */
        if (mb_strlen($password) < 8) {
            $this->error('كلمة المرور أقصر من ثمانية محارف.');

            return self::FAILURE;
        }

        $user = AdminUser::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("لا حساب بهذا البريد: {$email}");
            $this->line('الإنشاء وظيفةُ البذرة: php artisan db:seed --class=Database\\\\Seeders\\\\AdminUserSeeder');

            return self::FAILURE;
        }

        $user->forceFill(['password_hash' => Hash::make($password)])->save();

        /* لا تُطبع كلمةُ المرور ولا تجزئتُها — البريدُ وحده يكفي للتأكيد. */
        $this->info("ضُبطت كلمة مرور: {$email}");

        return self::SUCCESS;
    }
}
