<?php

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * `retry_after` أكبر من أطول `$timeout`: وإلّا أعاد الطابور المهمّة إلى عاملٍ
 * ثانٍ وهي ما زالت تعمل في الأوّل — تنفيذٌ مزدوج بالتصميم.
 */
class QueueRetryAfterTest extends TestCase
{
    public function test_retry_after_exceeds_every_job_timeout(): void
    {
        $longest = 0;

        foreach (glob(base_path('app/Domain/*/Jobs/*.php')) + glob(base_path('app/Domain/*/*/*Job.php')) + glob(base_path('app/Domain/*/*Job.php')) as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(base_path('app/'))));

            if (! class_exists($class) || ! property_exists($class, 'timeout')) {
                continue;
            }

            $longest = max($longest, (int) (new \ReflectionClass($class))->getDefaultProperties()['timeout']);
        }

        $this->assertGreaterThan(0, $longest);

        foreach (['database', 'redis'] as $connection) {
            $this->assertGreaterThan(
                $longest,
                (int) config("queue.connections.{$connection}.retry_after"),
                "queue.connections.{$connection}.retry_after must exceed the longest job timeout ({$longest}s)",
            );
        }
    }
}
