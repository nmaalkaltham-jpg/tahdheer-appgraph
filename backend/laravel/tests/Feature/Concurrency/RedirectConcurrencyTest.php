<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Redirects\RedirectRule;
use App\Domain\Redirects\RedirectRuleError;
use App\Domain\Redirects\RedirectService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * تزامن قواعد التحويل على PostgreSQL — لا على افتراض.
 *
 * ─── لماذا لا معاملةَ اختبار ───
 *
 * `RefreshDatabase` يلفّ الاختبار في معاملةٍ لا تُلتزم، فلا يرى اتّصالٌ
 * ثانٍ صفوفَها أصلًا — ويصير «لم يُرمَ شيء» برهانًا كاذبًا على أنّ الصفّ
 * غير مقفول بينما هو غير **موجود** عنده. فالصفوف تُلتزم هنا وتُنظَّف يدويًّا.
 */
class RedirectConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $ruleIds = [];

    protected function beginDatabaseTransaction(): void
    {
        // عمدًا فارغة — الصفوف تُلتزم فعلًا فيراها اتّصالٌ ثانٍ.
    }

    protected function tearDown(): void
    {
        if ($this->ruleIds !== []) {
            DB::table('redirect_rules')->whereIn('id', $this->ruleIds)->delete();
            $this->ruleIds = [];
        }

        parent::tearDown();
    }

    private function rule(string $from, string $to): RedirectRule
    {
        $rule = RedirectRule::create([
            'from_path' => $from,
            'to_path' => $to,
            'status_code' => 301,
            'enabled' => true,
            'hits' => 0,
            'version' => 1,
        ]);

        $this->ruleIds[] = (int) $rule->id;

        return $rule;
    }

    private function secondConnection(): Connection
    {
        config(['database.connections.concurrent' => config('database.connections.pgsql')]);
        DB::purge('concurrent');

        $second = DB::connection('concurrent');
        $second->statement("SET lock_timeout = '2s'");

        return $second;
    }

    public function test_the_rule_row_is_locked_while_a_save_runs(): void
    {
        $rule = $this->rule('/c-locked-'.uniqid(), '/dest');
        $second = $this->secondConnection();
        $observed = false;

        DB::transaction(function () use ($rule, $second, &$observed) {
            RedirectRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();

            try {
                // `NOWAIT` يرمي فورًا بدل الانتظار — فالرمي هو الدليل.
                $second->select('select id from redirect_rules where id = ? for update nowait', [$rule->id]);
            } catch (PDOException) {
                $observed = true;
            }
        });

        $this->assertTrue($observed, 'صفّ قاعدة التحويل لم يكن مقفولًا.');
    }

    /**
     * محرِّران بالنسخة نفسها: الثاني يُرفض، وكتابةُ الأوّل تبقى.
     *
     * هذا هو «آخر كاتبٍ يفوز» بعد أن انتهى — والبرهان أنّ الهدف بعد
     * المحاولتين هو هدف الأوّل لا الثاني.
     */
    public function test_a_stale_version_cannot_overwrite_a_committed_edit(): void
    {
        $service = app(RedirectService::class);
        $source = '/c-race-'.uniqid();
        $rule = $this->rule($source, '/before');

        $service->save([
            'from_path' => $source, 'to_path' => '/first', 'status_code' => 301,
        ], $rule->id, 1);

        $refused = null;

        try {
            $service->save([
                'from_path' => $source, 'to_path' => '/second', 'status_code' => 302,
            ], $rule->id, 1);
        } catch (RedirectRuleError $e) {
            $refused = $e;
        }

        $this->assertNotNull($refused, 'التحرير بنسخةٍ قديمة لم يُرفض.');
        $this->assertSame(RedirectRuleError::STALE_VERSION, $refused->errorCode);
        $this->assertSame(2, $refused->context['actualVersion']);

        $fresh = $rule->fresh();
        $this->assertSame('/first', (string) $fresh->to_path);
        $this->assertSame(301, (int) $fresh->status_code);
        $this->assertSame(2, (int) $fresh->version);
    }

    /**
     * الطيّ يرفع نسخة الصفوف الداخلة عليه.
     *
     * لولا ذلك لَحمل محرِّرٌ فتح إحداها نسخةً صارت كاذبة: يحفظ فيكتب فوق
     * الهدف المطويّ ويُعيد السلسلة إلى قفزتين بلا أن يعلم.
     */
    public function test_collapsing_a_chain_advances_the_inbound_version(): void
    {
        $service = app(RedirectService::class);
        $middle = '/c-mid-'.uniqid();
        $start = '/c-start-'.uniqid();

        $inbound = $this->rule($start, $middle);
        $this->ruleIds[] = (int) $service->save([
            'from_path' => $middle, 'to_path' => '/c-final', 'status_code' => 301,
        ])->id;

        $fresh = $inbound->fresh();

        $this->assertSame('/c-final', (string) $fresh->to_path);
        $this->assertSame(2, (int) $fresh->version);
    }
}
