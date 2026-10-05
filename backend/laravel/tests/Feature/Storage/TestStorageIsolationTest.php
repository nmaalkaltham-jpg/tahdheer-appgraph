<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * حارسُ `Tests\TestCase::refreshApplication`: كلّ قرصٍ مُعلَن مزيَّف قبل أن
 * يكتب أيُّ اختبارٍ أو زرع. لولاه كتبت الحزمة في مخزن التطوير المركَّب في
 * الحاوية، وحذفت منه ما يشاركها مفتاحَه المشتقَّ من المحتوى.
 */
class TestStorageIsolationTest extends TestCase
{
    public function test_no_declared_disk_resolves_to_real_developer_storage(): void
    {
        $disks = array_keys((array) config('filesystems.disks'));
        $this->assertNotEmpty($disks);

        $fakeRoot = storage_path('framework/testing/disks');
        $run = (string) getenv('TEST_RUN_TOKEN');

        foreach ($disks as $disk) {
            $root = Storage::disk($disk)->path('');

            $this->assertStringStartsWith($fakeRoot, $root,
                "القرص «{$disk}» يشير إلى مخزنٍ حقيقيّ ({$root}) داخل الاختبار.");

            if ($run !== '') {
                $this->assertStringContainsString("_test_{$run}", $root,
                    "القرص «{$disk}» يتقاسم جذره مع تشغيلاتٍ متزامنة.");
            }
        }
    }

    public function test_jobs_and_sessions_never_reach_the_shared_valkey(): void
    {
        $this->assertSame('sync', config('queue.default'), 'مهامّ الاختبار تصل إلى طابور عامل التطوير.');
        $this->assertSame('array', config('session.driver'), 'جلسات الاختبار تُكتب في Valkey المشترك.');
    }
}
