<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * حارسٌ فاشلٌ-مغلق: لا تُشغَّل الحزمة على قاعدةٍ ليست قاعدة اختبار.
     *
     * `RefreshDatabase` يُجري `migrate:fresh`، أي يُسقط كلّ الجداول. وضبط
     * الوجهة في `phpunit.xml` لا يكفي وحده لسببين تراكبا:
     *
     *   ١ — PHPUnit لا يَغلِب متغيّرًا بيئيًّا قائمًا إلّا بـ`force="true"`،
     *       وdocker-compose يضبط `DB_DATABASE=tahdheer` متغيّرًا حقيقيًّا.
     *   ٢ — و`bootstrap/cache/config.php` إن وُجد يُجمّد قيم `env()` وقت
     *       التخزين، فلا يُقرأ المتغيّر أصلًا مهما فُرض.
     *
     * فكان `php artisan test` داخل الحاوية يمسح قاعدة التطوير كاملةً بلا
     * إنذار. الحارس هنا يقف قبل أيّ سِمة اختبار، فيفشل بدل أن يُتلف — ولا
     * يعتمد على إعدادٍ قد يُخبَّأ أو يُنسى.
     */
    /**
     * هل الوجهة قاعدة اختبار؟ — قائمةُ سماحٍ لا بحثٌ عن جزءٍ من الاسم.
     *
     * `str_contains($name, 'test')` كان يقبل كلّ قاعدةٍ يقع فيها الحرفان
     * — `latest_snapshot` و`contest_data` و`tahdheer_testbed` تمرّ كلّها
     * ثمّ تُمسح. عامّةٌ لأنّ ما يحرس المسح يجب أن يكون مفحوصًا بنفسه.
     */
    public static function isTestDatabase(string $database): bool
    {
        $name = basename($database);

        return $database === ':memory:'
            || $name === ':memory:'
            || $name === 'tahdheer_test'
            || (bool) preg_match('/^tahdheer_test_[a-z0-9_]+$/', $name)
            || str_ends_with($name, '.sqlite');
    }

    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app['config'];
        $connection = $config->get('database.default');
        $database = (string) $config->get("database.connections.{$connection}.database");
        $name = basename($database);

        /*
         * قائمةُ سماحٍ لا بحثٌ عن جزءٍ من الاسم.
         *
         * `str_contains($name, 'test')` كان يقبل كلّ قاعدةٍ يقع فيها الحرفان
         * — `latest_snapshot` و`contest_data` و`tahdheer_testbed` تمرّ كلّها
         * ثمّ تُمسح. والسماح الآن صريح: `tahdheer_test` أو بادئتُها متبوعةً
         * بلاحقةٍ للتشغيل المتوازي، أو قاعدةٌ في الذاكرة.
         */
        if (! self::isTestDatabase($database)) {
            throw new RuntimeException(
                "الحزمة تستهدف قاعدةً ليست قاعدة اختبار: «{$database}» على اتّصال «{$connection}». "
                ."تشغيلها هنا يمسح البيانات. صحّح الوجهة ثمّ أعد المحاولة:\n"
                ."  php artisan config:clear   # إن كان الإعداد مخبّأً\n"
                .'  DB_DATABASE=tahdheer_test php artisan test'
            );
        }

        /*
         * الحاوية تضبط QUEUE_CONNECTION=redis وSESSION_DRIVER=redis، و`<env>`
         * في phpunit.xml بلا force لا يغلب متغيّرًا قائمًا — فكانت الاختبارات
         * تدفع مهامّها إلى طوابير Valkey المشتركة بالبادئة نفسها التي يستهلكها
         * عاملُ التطوير، فيُنفّذ مهمّةَ اختبارٍ بمعرّفاتٍ من قاعدة الاختبار على
         * قاعدة التطوير. الضبطُ هنا بعد التحميل فلا يُفلته إعدادٌ مخبّأ.
         */
        $config->set('queue.default', 'sync');
        $config->set('session.driver', 'array');

        return $app;
    }

    /**
     * نظيرُ حارس القاعدة للتخزين: لا يلمس اختبارٌ قرصًا حقيقيًّا.
     *
     * الحاوية تُركّب `storage/` من شجرة التطوير، فكلّ اختبارٍ يزرع
     * (`DatabaseSeeder` ⇒ `DevAssetSeeder`) أو يستورد أصلًا بلا
     * `Storage::fake` كان يكتب في مخزن التطوير نفسه — ووُجدت فيه كائناتُ
     * `drive/DRIVE_FILE_*` من الاختبارات، وفُقد كائنا الملفّين المدفوعين
     * اللذين يثبتان مسار التحميل. الاختبارُ والتطوير يتقاسمان الأسماء لأنّ
     * المفتاح مشتقٌّ من المحتوى، فما يحذفه أحدهما يفقده الآخر.
     *
     * يُزيَّف كلّ قرصٍ هنا لا في `setUp`: السِّمات (`RefreshDatabase` مع
     * `$seed`) تعمل قبل أيّ ردّ نداءٍ بعد الإنشاء، والزرعُ يكتب حينها.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        /*
         * جذرُ القرص المزيَّف مشتركٌ بين كلّ تشغيلٍ في الحاوية، و`fake()`
         * يُفرغه أوّلًا — فتشغيلان متزامنان يمسح أحدهما ملفّات الآخر. رمزُ
         * التشغيل (اسمُ قاعدته من `scripts/test-backend.sh`) يُفرِد الجذر.
         * لا يُفعّل هذا وضعَ التوازي: ذاك يشترط LARAVEL_PARALLEL_TESTING.
         */
        $run = (string) getenv('TEST_RUN_TOKEN');
        if ($run !== '' && ! ParallelTesting::token()) {
            ParallelTesting::resolveTokenUsing(fn () => $run);
        }

        foreach (array_keys((array) $this->app['config']->get('filesystems.disks', [])) as $disk) {
            Storage::fake($disk);
        }
    }
}
