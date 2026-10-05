<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Navigation\MenuTree;
use App\Domain\Navigation\Models\NavigationMenu;
use App\Domain\Navigation\Models\NavigationPublishOperation as Operation;
use App\Domain\Navigation\Models\NavigationRevision;
use App\Domain\Navigation\NavigationCache;
use App\Domain\Navigation\NavigationPublishContract as Contract;
use App\Domain\Navigation\NavigationPublishFailed;
use App\Domain\Navigation\NavigationPublishService;
use App\Domain\Outbox\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * دورة نشر التنقّل: قفل، فحص مسبق، لقطة، التزام، إبطال، تحقّق، تراجع.
 *
 * الخطر الذي تحرسه: نشرٌ ينتصف فيُترك النظام في حالة لا يعرفها أحد، أو تراجعٌ
 * يدّعي الاستعادة ولا يُقارن ما استعاده بشيء.
 */
class NavigationPublishTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function service(): NavigationPublishService
    {
        return app(NavigationPublishService::class);
    }

    /** @param list<array{0: string, 1: string}> $pairs [id, url] */
    private function menuWithDraft(array $pairs, string $location = 'header'): NavigationMenu
    {
        $nodes = MenuTree::normalize(array_map(
            static fn (array $p): array => ['id' => $p[0], 'parentId' => null, 'label' => $p[0], 'url' => $p[1]],
            $pairs,
        ));
        // الخادم يمنح الهويات الدائمة — المسوّدة لا تُنشر بمعرّفات مؤقّتة.
        $nodes = MenuTree::assignStableIds($nodes)['nodes'];

        /*
         * `updateOrCreate` لا `create`: `location` فريد، وهجرة نقل التذييل
         * تزرع صفَّ `footer` في كلّ قاعدة — فاختبارٌ ينشئه يخرق القيد.
         */
        $menu = NavigationMenu::query()->updateOrCreate(
            ['location' => $location],
            [
                'name' => MenuTree::LOCATIONS[$location],
                'draft_nodes' => $nodes,
                'version' => 1,
                'published_nodes' => null,
                'published_version' => null,
                'published_at' => null,
            ],
        );

        // كذلك الإصدار: `(menu_id, revision)` فريد، والتذييل المزروع يحمل ١ بالفعل.
        NavigationRevision::query()->updateOrCreate(
            ['menu_id' => $menu->id, 'revision' => 1],
            [
                'nodes' => $nodes,
                'fingerprint' => MenuTree::fingerprint($nodes),
                'node_count' => count($nodes),
                'created_at' => now(),
            ],
        );

        return $menu;
    }

    /* ───── المسار السعيد ───── */

    public function test_publish_runs_every_step_and_verifies_what_it_served(): void
    {
        $menu = $this->menuWithDraft([['home', '/'], ['shop', '/shop']]);

        $op = $this->service()->publish($menu, 'key-1', 1, null);

        $this->assertSame(Operation::COMPLETED, $op->status);
        $this->assertNotNull($op->verified_at);
        // البصمة المتحقَّقة تساوي بصمة ما نُشر فعلًا لا ما قُصد نشره.
        $this->assertSame(MenuTree::fingerprint((array) $menu->refresh()->published_nodes), $op->verified_fingerprint);
        $this->assertSame(1, (int) $menu->published_version);

        // والحدث كُتب داخل معاملة الالتزام.
        $this->assertTrue(
            OutboxEvent::query()->where('event_type', 'navigation.published')->exists(),
            'لم يُكتب حدث النشر',
        );

        // والمسار العام يخدم المنشور فورًا — الكاش أُبطل لا انتظار انتهاء المدّة.
        $served = $this->getJson('/api/v1/navigation/header')->assertOk()->json('data');
        $this->assertCount(2, $served);
    }

    /* ───── الفحص المسبق يمنع قبل الكتابة ───── */

    public function test_publishing_an_empty_draft_is_refused_before_anything_is_written(): void
    {
        $menu = NavigationMenu::query()->updateOrCreate(['location' => 'header'], [
            'published_nodes' => null, 'published_version' => null, 'published_at' => null,
            'name' => 'الترويسة', 'draft_nodes' => [], 'version' => 1,
        ]);

        $op = $this->service()->publish($menu, 'key-empty', 1, null);

        $this->assertSame(Operation::FAILED, $op->status);
        $this->assertSame('PREFLIGHT_FAILED', $op->failure_code);
        // لا شيء كُتب: القائمة ما زالت غير منشورة.
        $this->assertNull($menu->refresh()->published_at);
    }

    public function test_a_stale_base_revision_is_refused(): void
    {
        $menu = $this->menuWithDraft([['home', '/']]);

        $op = $this->service()->publish($menu, 'key-stale', 99, null);

        $this->assertSame(Operation::FAILED, $op->status);
        $this->assertStringContainsString('النسخة تغيّرت', (string) $op->failure_detail);
    }

    /** معرّفات مؤقّتة من المتصفّح لا تصل القائمة المنشورة. */
    public function test_temporary_ids_block_publishing(): void
    {
        $menu = NavigationMenu::query()->updateOrCreate(['location' => 'header'], [
            'published_nodes' => null, 'published_version' => null, 'published_at' => null,
            'name' => 'الترويسة', 'version' => 1,
            'draft_nodes' => MenuTree::normalize([
                ['id' => 'tmp_1', 'parentId' => null, 'label' => 'جديد', 'url' => '/new'],
            ]),
        ]);

        $op = $this->service()->publish($menu, 'key-tmp', 1, null);

        $this->assertSame('PREFLIGHT_FAILED', $op->failure_code);
        $this->assertStringContainsString('مؤقّتة', (string) $op->failure_detail);
    }

    /* ───── التكرار ───── */

    public function test_the_same_idempotency_key_returns_the_first_operation_not_a_second_publish(): void
    {
        $menu = $this->menuWithDraft([['home', '/']]);

        $first = $this->service()->publish($menu, 'key-same', 1, null);
        $second = $this->service()->publish($menu->refresh(), 'key-same', 1, null);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Operation::query()->count(), 'أُنشئت عملية ثانية لنفس المفتاح');
    }

    /* ───── التراجع ───── */

    public function test_rollback_restores_the_previous_revision_and_proves_it_by_fingerprint(): void
    {
        $service = $this->service();

        // الإصدار الأوّل يُنشر.
        $menu = $this->menuWithDraft([['home', '/'], ['shop', '/shop']]);
        $service->publish($menu, 'v1', 1, null);
        $v1Fingerprint = MenuTree::fingerprint((array) $menu->refresh()->published_nodes);

        // ثم مسوّدة ثانية تُحفظ وتُنشر.
        $v2 = MenuTree::assignStableIds(MenuTree::normalize([
            ['id' => 'a', 'parentId' => null, 'label' => 'مختلف', 'url' => '/different'],
        ]))['nodes'];
        $menu->forceFill(['draft_nodes' => $v2, 'version' => 2])->save();
        NavigationRevision::query()->create([
            'menu_id' => $menu->id, 'revision' => 2, 'nodes' => $v2,
            'fingerprint' => MenuTree::fingerprint($v2), 'node_count' => count($v2), 'created_at' => now(),
        ]);

        $op = $service->publish($menu->refresh(), 'v2', 2, null);
        $this->assertSame(Operation::COMPLETED, $op->status);
        $this->assertCount(1, $this->getJson('/api/v1/navigation/header')->json('data'));

        // التراجع يعود إلى اللقطة، ويُثبتها بالبصمة لا بالثقة.
        $rolled = $service->rollback($op, $menu->refresh(), null);

        $this->assertSame(Operation::ROLLED_BACK, $rolled->status);
        $this->assertSame($v1Fingerprint, MenuTree::fingerprint((array) $menu->refresh()->published_nodes));
        // والمتجر يخدم النسخة السابقة فورًا.
        $this->assertCount(2, $this->getJson('/api/v1/navigation/header')->json('data'));
    }

    /* ───── الكاش ───── */

    public function test_the_public_read_is_cached_and_the_publish_invalidates_it(): void
    {
        $menu = $this->menuWithDraft([['home', '/']]);
        $this->service()->publish($menu, 'cache-1', 1, null);

        $this->assertCount(1, NavigationCache::published('header'));

        // كتابة مباشرة في القاعدة لا تُبطل الكاش — وهذا هو السلوك المقصود.
        NavigationMenu::query()->whereKey($menu->id)->update(['published_nodes' => json_encode([])]);
        $this->assertCount(1, NavigationCache::published('header'), 'الكاش لم يعمل أصلًا');

        NavigationCache::invalidate('header');
        $this->assertSame([], NavigationCache::published('header'));
    }

    /** الجوّال يرث الترويسة حتى تُنشر له قائمة — لا شاشة بلا قائمة. */
    public function test_the_mobile_location_inherits_the_header_until_it_has_its_own(): void
    {
        $menu = $this->menuWithDraft([['home', '/'], ['shop', '/shop']]);
        $this->service()->publish($menu, 'mob-1', 1, null);

        $this->assertCount(2, $this->getJson('/api/v1/navigation/mobile')->assertOk()->json('data'));

        $mobile = $this->menuWithDraft([['help', '/help']], 'mobile');
        $this->service()->publish($mobile, 'mob-2', 1, null);

        $served = $this->getJson('/api/v1/navigation/mobile')->assertOk()->json('data');
        $this->assertCount(1, $served);
        $this->assertSame('/help', $served[0]['url']);
    }

    /* ───── الهويات والبصمة ───── */

    public function test_stable_ids_replace_temporary_ones_and_reparent_children(): void
    {
        $nodes = MenuTree::normalize([
            ['id' => 'tmp_1', 'parentId' => null, 'label' => 'أب', 'url' => '/parent'],
            ['id' => 'tmp_2', 'parentId' => 'tmp_1', 'label' => 'ابن', 'url' => '/child'],
        ]);

        $out = MenuTree::assignStableIds($nodes);

        $this->assertCount(2, $out['idMap']);
        foreach ($out['nodes'] as $node) {
            $this->assertStringStartsWith(MenuTree::ID_PREFIX, $node['id']);
        }
        // الابن يشير إلى الهوية الجديدة لأبيه لا إلى tmp_1 الميت.
        $this->assertSame($out['nodes'][0]['id'], $out['nodes'][1]['parentId']);
    }

    /** البصمة لا تتغيّر بترتيب مفاتيح JSON — وإلّا أفشلت تراجعًا سليمًا. */
    public function test_the_fingerprint_is_stable_across_key_order(): void
    {
        $a = MenuTree::normalize([['id' => 'x', 'parentId' => null, 'label' => 'أ', 'url' => '/a']]);
        $b = MenuTree::normalize([['url' => '/a', 'label' => 'أ', 'parentId' => null, 'id' => 'x']]);

        $this->assertSame(MenuTree::fingerprint($a), MenuTree::fingerprint($b));
    }

    public function test_the_diff_names_what_changed(): void
    {
        $before = MenuTree::normalize([
            ['id' => 'a', 'parentId' => null, 'label' => 'أ', 'url' => '/a'],
            ['id' => 'b', 'parentId' => null, 'label' => 'ب', 'url' => '/b'],
        ]);
        $after = MenuTree::normalize([
            ['id' => 'b', 'parentId' => null, 'label' => 'ب', 'url' => '/b'],
            ['id' => 'a', 'parentId' => null, 'label' => 'أ معدَّل', 'url' => '/a'],
            ['id' => 'c', 'parentId' => null, 'label' => 'ج', 'url' => '/c'],
        ]);

        $diff = MenuTree::diff($before, $after);

        $this->assertSame(1, $diff['created']);
        $this->assertSame(1, $diff['updated']);
        $this->assertSame(0, $diff['deleted']);
    }

    /* ───── ما كان يمرّ صامتًا ───── */

    /**
     * السباق بين الحفظ والنشر.
     *
     * كانت المسوّدة تُقرأ قبل المعاملة وتُكتب داخلها مع رقم الإصدار الذي
     * وجدته المعاملة: حفظٌ يقع بينهما يُنشر برقمه وبشجرةٍ ليست شجرته، فيرى
     * المشغّل «نُشر الإصدار ٢» وعلى المتجر شجرة الإصدار ١.
     */
    public function test_a_save_that_lands_between_preflight_and_commit_is_refused_not_published(): void
    {
        $menu = $this->menuWithDraft([['home', '/']]);

        // يُحاكي حفظًا متزامنًا: يرفع الإصدار لحظة أن يُقفل الصفّ للالتزام.
        $intervened = false;
        DB::beforeExecuting(function (string $query) use (&$intervened, $menu): void {
            if (! $intervened && str_contains(strtolower($query), 'for update')) {
                $intervened = true;
                DB::table('navigation_menus')->where('id', $menu->id)->update(['version' => 7]);
            }
        });

        $op = $this->service()->publish($menu, 'race-key', 1, null);

        $this->assertTrue($intervened, 'لم يقع التدخّل المُحاكي — الاختبار لا يفحص شيئًا');
        $this->assertSame(Operation::FAILED, $op->status);
        $this->assertSame('DRAFT_CHANGED', $op->failure_code);
        $this->assertNull($menu->refresh()->published_nodes, 'رُفض السباق ولم يُكتب منشور');
    }

    /** التراجع عن عمليّة لم تكتب شيئًا كان يستعيد إصدارًا لم يُغادَر أصلًا. */
    public function test_rollback_refuses_an_operation_that_never_completed(): void
    {
        $menu = $this->menuWithDraft([]);
        $failed = $this->service()->publish($menu, 'empty-key', 1, null);
        $this->assertSame(Operation::FAILED, $failed->status);

        $rolled = $this->service()->rollback($failed, $menu, null);

        $this->assertSame(Operation::ROLLBACK_FAILED, $rolled->status);
        $this->assertSame('ROLLBACK_INELIGIBLE', $rolled->failure_code);
    }

    /** معرّف عمليّة ترويسة مع مسار الذيل كان يستعيد لقطة الترويسة في الذيل. */
    public function test_rollback_will_not_apply_an_operation_that_belongs_to_another_menu(): void
    {
        $header = $this->menuWithDraft([['home', '/']], 'header');
        $footer = $this->menuWithDraft([['terms', '/terms']], 'footer');
        $op = $this->service()->publish($header, 'header-key', 1, null);
        $this->assertSame(Operation::COMPLETED, $op->status);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $response = $this->postJson('/api/v1/admin/navigation/footer/rollback', ['operationId' => $op->id]);

        $response->assertNotFound();
        $this->assertSame(Operation::COMPLETED, $op->refresh()->status, 'لم تُمسّ عمليّة القائمة الأخرى');
        $this->assertNull($footer->refresh()->published_nodes);
    }

    /** مفتاح التكرار كان يُقرأ عالميًّا: المفتاح نفسه لقائمة أخرى يقول «نُشر». */
    public function test_an_idempotency_key_from_another_menu_is_refused_not_replayed(): void
    {
        $header = $this->menuWithDraft([['home', '/']], 'header');
        $footer = $this->menuWithDraft([['terms', '/terms']], 'footer');
        $this->service()->publish($header, 'shared-key', 1, null);

        $this->expectException(NavigationPublishFailed::class);
        $this->service()->publish($footer, 'shared-key', 1, null);
    }

    /**
     * العقد المعروض هو المنفَّذ.
     *
     * الحوار يعرض خطوات الخادم ويقابلها بأثر العمليّة؛ خطوةٌ موصوفة ولا
     * يسجّلها المنسّق تجعله يكتب «لم تُنفَّذ» عن نشرٍ تمّ.
     */
    public function test_every_contract_step_is_recorded_by_the_publish_it_describes(): void
    {
        $menu = $this->menuWithDraft([['home', '/']]);

        $op = $this->service()->publish($menu, 'contract-key', 1, null);

        $this->assertSame(Operation::COMPLETED, $op->status);
        $recorded = array_column((array) $op->refresh()->steps, 'status', 'id');
        foreach (Contract::publish() as $step) {
            $this->assertArrayHasKey($step['id'], $recorded, "خطوة «{$step['label']}» موصوفة ولا تُسجَّل");
            $this->assertSame('SUCCESS', $recorded[$step['id']]);
        }
    }

    /** التراجع كذلك: كلّ خطوة موصوفة تُسجَّل حين يُنفَّذ. */
    public function test_every_rollback_contract_step_is_recorded(): void
    {
        $menu = $this->menuWithDraft([['home', '/']]);
        $op = $this->service()->publish($menu, 'rb-key', 1, null);

        $rolled = $this->service()->rollback($op, $menu, null);

        $this->assertSame(Operation::ROLLED_BACK, $rolled->status);
        $recorded = array_column((array) $rolled->refresh()->steps, 'status', 'id');
        foreach (Contract::rollback() as $step) {
            $this->assertArrayHasKey($step['id'], $recorded, "خطوة تراجعٍ «{$step['label']}» موصوفة ولا تُسجَّل");
        }
    }

    /** «عناصر بمعرّفات مؤقّتة» بلا اسم تترك المشغّل يفتّش شجرةً بيده. */
    public function test_a_blocked_preflight_names_the_node_that_blocked_it(): void
    {
        $nodes = MenuTree::normalize([['id' => 'tmp_9', 'parentId' => null, 'label' => 'صفحة الشروط', 'url' => '/terms']]);
        $menu = NavigationMenu::query()->updateOrCreate(['location' => 'header'], [
            'published_nodes' => null, 'published_version' => null, 'published_at' => null,
            'name' => 'الترويسة', 'draft_nodes' => $nodes, 'version' => 1,
        ]);

        $op = $this->service()->publish($menu, 'named-key', 1, null);

        $this->assertSame('PREFLIGHT_FAILED', $op->failure_code);
        $this->assertStringContainsString('صفحة الشروط', (string) $op->failure_detail);
    }
}
