<?php

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «تفاصيل أحداث القياس تُحذف بعد ‎٩٠ يومًا» — والوعد بلا منفّذٍ ليس وعدًا.
 *
 * السياسة كانت منشورة، وجدول `retention_policies` كان **فارغًا**: لا سطر
 * يأمر بالحذف، ولا أمرَ يقرأ السطر. فكان الحدث يبقى إلى الأبد بينما الصفحة
 * تقول إنّه يُمحى.
 */
class RetentionPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_only_what_is_past_the_window_and_logs_it(): void
    {
        DB::table('analytics_events')->insert([
            $this->event('/stale', now()->subDays(120)),
            $this->event('/edge', now()->subDays(89)),
            $this->event('/fresh', now()),
        ]);

        $this->artisan('analytics:prune')->assertSuccessful();

        $this->assertSame(0, DB::table('analytics_events')->where('path', '/stale')->count());
        $this->assertSame(
            2,
            DB::table('analytics_events')->whereIn('path', ['/edge', '/fresh'])->count(),
            'الحدّ ٩٠ يومًا لا ٨٩: حذفُ ما لم يبلغ المدّة إتلافُ قياسٍ مأذونٍ به.',
        );

        // المحو نفسه يُقيَّد: دفتر إتلافٍ بلا سطرٍ للإتلاف لا يُثبت الامتثال.
        $this->assertGreaterThan(0, DB::table('anonymization_log')->count());
    }

    /** @return array<string, mixed> */
    private function event(string $path, \DateTimeInterface $at): array
    {
        return [
            'occurred_at' => $at,
            'created_at' => $at,
            'event_type' => 'page_view',
            'path' => $path,
            'visitor_hash' => null,
            'session_hash' => null,
            'referrer_host' => null,
            'product_id' => null,
            'value_minor' => null,
            'device' => 'desktop',
            'is_logged_in' => false,
            'dedup_key' => null,
            'meta' => null,
        ];
    }
}
