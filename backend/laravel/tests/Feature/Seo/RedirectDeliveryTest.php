<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Redirects\RedirectRule;
use App\Domain\Redirects\RedirectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تسليم التحويلات فعلًا — الطبقة التي كانت مفقودة.
 *
 * الجدول والخدمة والمسارات كانت كلّها موجودة ومُختبَرة، ولا شيء في الواجهة
 * يستدعيها: قاعدة يكتبها المشرف لا تُطبَّق على زائر، و`not_found_hits` لا
 * يُغذّى فيبقى كشف الروابط المكسورة معطَّلًا.
 *
 * هذه الاختبارات تثبّت العقد الذي يعتمد عليه وسيط الواجهة.
 */
class RedirectDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function rule(string $from, string $to, int $status = 301, bool $enabled = true): RedirectRule
    {
        return RedirectRule::query()->create([
            'from_path' => $from,
            'to_path' => $to,
            'status_code' => $status,
            'enabled' => $enabled,
        ]);
    }

    /* ───── عقد قائمة القواعد ───── */

    public function test_the_rules_endpoint_serves_only_enabled_rules_longest_first(): void
    {
        $this->rule('/a', '/one');
        $this->rule('/a/deeper/path', '/two');
        $this->rule('/disabled', '/three', enabled: false);

        $body = $this->getJson('/api/v1/redirects/rules')->assertOk()->json();

        $froms = array_column($body['data'], 'from');
        $this->assertSame(['/a/deeper/path', '/a'], $froms, 'الأطول أوّلًا — الوسيط يعتمد الترتيب');
        $this->assertNotContains('/disabled', $froms, 'قاعدة معطّلة تسرّبت للعامّة');
        $this->assertNotEmpty($body['revision']);
    }

    /** البصمة تتغيّر بتغيّر القواعد — بها يعرف الوسيط متى يُحدّث. */
    public function test_the_revision_changes_when_the_rules_change(): void
    {
        $this->rule('/a', '/one');
        $first = $this->getJson('/api/v1/redirects/rules')->json('revision');

        $this->rule('/b', '/two');
        $second = $this->getJson('/api/v1/redirects/rules')->json('revision');

        $this->assertNotSame($first, $second);
    }

    public function test_an_empty_rule_table_serves_an_empty_list_not_an_error(): void
    {
        $this->getJson('/api/v1/redirects/rules')->assertOk()->assertJsonPath('data', []);
    }
    /* ───── الأمان ───── */

    /** التحويل لا يصير مسار خروج: وجهة خارجية تُرفض عند الحفظ. */
    public function test_an_external_destination_is_refused(): void
    {
        foreach (['https://evil.test/x', '//evil.test', 'javascript:alert(1)'] as $bad) {
            $refused = false;
            try {
                app(RedirectService::class)->save([
                    'from_path' => '/x', 'to_path' => $bad, 'status_code' => 301, 'enabled' => true,
                ]);
            } catch (\Throwable) {
                $refused = true;
            }
            $this->assertTrue($refused, "قُبلت وجهة خارجية: {$bad}");
        }
    }

    private function resolve(string $path): ?string
    {
        $match = app(RedirectService::class)->match($path);

        return $match?->target;
    }
}
