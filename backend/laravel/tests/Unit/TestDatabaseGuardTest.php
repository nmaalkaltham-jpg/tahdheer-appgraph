<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Tests\TestCase;

/**
 * الحارس الذي يمنع مسح قاعدة التطوير — مفحوصًا بنفسه.
 *
 * `RefreshDatabase` يُجري `migrate:fresh`، فالخطأ هنا لا يُصلَح: البيانات
 * تذهب. وكان الفحص `str_contains($name, 'test')` فيقبل كلّ اسمٍ يقع فيه
 * الحرفان — `tahdheer_testbed` و`latest_snapshot` و`contest_data` تمرّ
 * كلُّها. هذه الحالات هي ما يمنع عودة ذلك الفحص.
 *
 * ولا يرث `Tests\TestCase`: الوارث يُشغّل الحارس نفسه في `setUp`.
 */
class TestDatabaseGuardTest extends PHPUnitTestCase
{
    public static function accepted(): array
    {
        return [
            'قاعدة الاختبار الافتراضيّة' => ['tahdheer_test'],
            'قاعدة تشغيلٍ متوازٍ' => ['tahdheer_test_reliab'],
            'قاعدةٌ بمسارٍ كامل' => ['/var/lib/postgresql/tahdheer_test'],
            'ذاكرة' => [':memory:'],
            'ملفّ sqlite' => ['/tmp/suite.sqlite'],
        ];
    }

    public static function refused(): array
    {
        return [
            'قاعدة التطوير' => ['tahdheer'],
            'اسمٌ يحوي test ولا يبدأ به' => ['tahdheer_testbed'],
            'latest_snapshot' => ['latest_snapshot'],
            'contest_data' => ['contest_data'],
            'الإنتاج' => ['tahdheer_production'],
            'فارغ' => [''],
        ];
    }

    #[DataProvider('accepted')]
    public function test_a_real_test_target_is_accepted(string $database): void
    {
        $this->assertTrue(TestCase::isTestDatabase($database));
    }

    #[DataProvider('refused')]
    public function test_anything_else_is_refused_so_the_suite_cannot_wipe_it(string $database): void
    {
        $this->assertFalse(TestCase::isTestDatabase($database));
    }
}
