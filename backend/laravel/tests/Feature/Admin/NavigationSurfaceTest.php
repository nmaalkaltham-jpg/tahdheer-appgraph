<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Navigation\LinkChecker;
use App\Domain\Navigation\MenuTree;
use App\Domain\Navigation\Models\NavigationMenu;
use App\Domain\Navigation\NavigationCache;
use App\Domain\Writing\AnthropicWritingAssistant;
use App\Domain\Writing\NullWritingAssistant;
use App\Domain\Writing\WritingAssistantProvider;
use App\Domain\Writing\WritingAssistantUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * الورشتان اللتان كانتا بلا خادم: التنقّل والأبطال — ومزوّد الكتابة.
 *
 * الخطر الذي تحرسه هذه الاختبارات: شجرة قائمة تصل من متصفّح وتُصيَّر في
 * ترويسة كل زائر، ونقطة نهاية تجلب أي عنوان يُرسَل إليها.
 */
class NavigationSurfaceTest extends TestCase
{
    use RefreshDatabase;

    /* ───── شجرة القائمة ───── */

    /** الدورة تُقطع ولا تُصيَّر: التصيير الدائري ينفد به المكدّس. */
    public function test_a_parent_cycle_is_broken_instead_of_rendered(): void
    {
        $nodes = MenuTree::normalize([
            ['id' => 'a', 'parentId' => 'b', 'label' => 'أ', 'url' => '/a'],
            ['id' => 'b', 'parentId' => 'a', 'label' => 'ب', 'url' => '/b'],
        ]);

        $roots = array_values(array_filter($nodes, static fn (array $n): bool => $n['parentId'] === null));

        $this->assertCount(2, $nodes);
        /*
         * «جذرٌ ما» لا يكفي: القطع يجب أن يقع على العقدة الأولى بعينها، وإلّا
         * مرّ الاختبار على شجرةٍ صحيحة الشكل خاطئة الترتيب.
         */
        $this->assertCount(1, $roots, 'القطع يُنتج جذرًا واحدًا لا اثنين');
        $this->assertSame('a', $roots[0]['id'], 'الأولى في الإدخال هي التي تُرفع جذرًا');
        $this->assertSame('a', $nodes[1]['parentId'] ?? null, 'والثانية تبقى معلّقةً بها');
    }

    /** العمق محدود: ما تجاوز الحدّ يُرفع إلى الجذر ولا يُسقَط. */
    public function test_depth_beyond_the_limit_is_lifted_to_the_root(): void
    {
        $input = [];
        for ($i = 0; $i < 8; $i++) {
            $input[] = [
                'id' => "n{$i}",
                'parentId' => $i === 0 ? null : 'n'.($i - 1),
                'label' => "عنصر {$i}",
                'url' => "/n{$i}",
            ];
        }

        $nodes = MenuTree::normalize($input);
        $byId = array_column($nodes, null, 'id');

        $this->assertCount(8, $nodes, 'لا عنصر يُفقد');

        foreach ($nodes as $node) {
            $depth = 0;
            $cursor = $node['parentId'];
            while ($cursor !== null && $depth < 10) {
                $cursor = $byId[$cursor]['parentId'] ?? null;
                $depth++;
            }
            $this->assertLessThan(MenuTree::MAX_DEPTH, $depth, "العنصر {$node['id']} أعمق من الحدّ");
        }
    }

    /** مخطَّطات التنفيذ تُرفَض: رابط القائمة يصل سمة href مباشرة. */
    public function test_script_and_protocol_relative_urls_are_rejected(): void
    {
        $nodes = MenuTree::normalize([
            ['id' => 'x', 'parentId' => null, 'label' => 'خبيث', 'url' => 'javascript:alert(1)'],
            ['id' => 'y', 'parentId' => null, 'label' => 'بيانات', 'url' => 'data:text/html,<script>'],
            ['id' => 'z', 'parentId' => null, 'label' => 'نسبي البروتوكول', 'url' => '//evil.example'],
            ['id' => 'ok', 'parentId' => null, 'label' => 'سليم', 'url' => '/shop'],
        ]);

        $this->assertCount(1, $nodes);
        $this->assertSame('/shop', $nodes[0]['url']);
    }

    /** فتح تبويب جديد يفرض noopener مهما أرسلت الواجهة. */
    public function test_blank_target_always_gets_noopener(): void
    {
        $nodes = MenuTree::normalize([
            ['id' => 'a', 'parentId' => null, 'label' => 'خارجي', 'url' => 'https://example.com', 'target' => '_blank', 'rel' => ''],
        ]);

        $this->assertStringContainsString('noopener', $nodes[0]['rel']);
        $this->assertStringContainsString('noreferrer', $nodes[0]['rel']);
    }

    /** المكرّر يُسقَط: مفتاحان متطابقان يُنتجان تصييرًا غير محدَّد. */
    public function test_duplicate_ids_are_dropped(): void
    {
        $nodes = MenuTree::normalize([
            ['id' => 'a', 'parentId' => null, 'label' => 'الأولى', 'url' => '/one'],
            ['id' => 'a', 'parentId' => null, 'label' => 'الثانية', 'url' => '/two'],
        ]);

        $this->assertCount(1, $nodes);
        $this->assertSame('/one', $nodes[0]['url']);
    }

    /* ───── النشر والقراءة العامّة ───── */

    /** المسوّدة لا تصل زائرًا. */
    public function test_the_public_menu_returns_only_published_nodes(): void
    {
        // الترويسة تصل مزروعةً منشورة؛ «غير منشورة» تُصنع صراحةً هنا.
        NavigationMenu::query()->updateOrCreate(['location' => 'header'], [
            'name' => 'الترويسة',
            'draft_nodes' => MenuTree::normalize([
                ['id' => 'd', 'parentId' => null, 'label' => 'مسوّدة', 'url' => '/draft'],
            ]),
            'version' => 2,
            'published_nodes' => null,
            'published_version' => null,
            'published_at' => null,
        ]);
        NavigationCache::invalidate('header');

        $this->assertSame([], $this->getJson('/api/v1/navigation/header')->assertOk()->json('data'));

        // بالموقع لا بـ«الأولى»: هجرة التذييل زرعت صفًّا يسبقه في الترتيب.
        $menu = NavigationMenu::query()->where('location', 'header')->firstOrFail();
        $menu->forceFill([
            'published_nodes' => $menu->draft_nodes,
            'published_version' => 2,
            'published_at' => now(),
        ])->save();
        /*
         * الكتابة المباشرة تتخطّى المنسّق، فعليها أن تفعل ما يفعله: تُبطل.
         *
         * كان الاختبار يمرّ بلا هذا السطر لأنّ `Cache::remember` لا يخزّن
         * `null` فعليًّا — فالقائمة غير المنشورة كانت تُستعلَم في كلّ طلب.
         * والظرف الآن قيمةٌ حقيقيّة تُخزَّن، فغياب الإبطال يبقي الفراغ عشر
         * دقائق. وهذا هو السلوك المقصود: النشر يُبطل، والكتابة اليدويّة
         * ليست نشرًا.
         */
        NavigationCache::invalidate('header');

        $published = $this->getJson('/api/v1/navigation/header')->assertOk()->json('data');
        $this->assertCount(1, $published);
        $this->assertSame('/draft', $published[0]['url']);
    }

    public function test_an_unknown_menu_location_is_a_404(): void
    {
        $this->getJson('/api/v1/navigation/sidebar')->assertStatus(404);
    }
    /* ───── فاحص الروابط ───── */

    /** الشبكة الداخلية محجوبة: هذه هي الثغرة التي يوجد الفاحص لمنعها. */
    public function test_the_link_checker_refuses_internal_addresses(): void
    {
        Http::fake();
        $checker = new LinkChecker;

        foreach ([
            'http://127.0.0.1/admin',
            'http://localhost:80/',
            'http://169.254.169.254/latest/meta-data/',
            'http://10.0.0.5/',
            'http://192.168.1.1/',
        ] as $url) {
            $result = $checker->check($url, 'https://tahdheer.com');
            $this->assertSame(LinkChecker::BLOCKED, $result['status'], "لم يُحجب: {$url}");
        }

        // ولا طلب واحد خرج: الحجب قبل الجلب لا بعده.
        Http::assertNothingSent();
    }

    public function test_the_link_checker_refuses_non_http_schemes_and_odd_ports(): void
    {
        Http::fake();
        $checker = new LinkChecker;

        $this->assertSame(LinkChecker::BLOCKED, $checker->check('file:///etc/passwd', 'https://tahdheer.com')['status']);
        $this->assertSame(LinkChecker::BLOCKED, $checker->check('gopher://example.com/', 'https://tahdheer.com')['status']);
        $this->assertSame(LinkChecker::BLOCKED, $checker->check('http://example.com:6379/', 'https://tahdheer.com')['status']);

        Http::assertNothingSent();
    }

    /** التحويل لا يُتبَع عمياءً: كل قفزة تُفحص من جديد. */
    public function test_a_redirect_into_a_private_address_is_blocked_mid_chain(): void
    {
        Http::fake([
            'example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/secret']),
        ]);

        $result = (new LinkChecker)->check('https://example.com/out', 'https://tahdheer.com');

        $this->assertSame(LinkChecker::BLOCKED, $result['status']);
        $this->assertNotNull($result['reason']);
    }

    /* ───── المساعد الكتابي ───── */

    /** بلا مفتاح: المزوّد الرافض — لا نصّ مُلفَّق. */
    public function test_an_unkeyed_provider_resolves_to_the_refusing_one(): void
    {
        config(['services.writing.provider' => 'anthropic', 'services.writing.key' => '']);

        $this->assertInstanceOf(NullWritingAssistant::class, app(WritingAssistantProvider::class));
    }

    public function test_a_keyed_provider_resolves_to_anthropic(): void
    {
        config([
            'services.writing.provider' => 'anthropic',
            'services.writing.key' => 'sk-ant-test',
            'services.writing.model' => 'claude-sonnet-5',
        ]);

        $provider = app(WritingAssistantProvider::class);

        $this->assertInstanceOf(AnthropicWritingAssistant::class, $provider);
        $this->assertTrue($provider->isConfigured());
    }

    /** الاقتراحات تُستخرج سطرًا سطرًا، ويُقصّ الترقيم رغم التعليمات. */
    public function test_suggestions_are_parsed_and_capped(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => "1. اقتراح أوّل\n- اقتراح ثانٍ\n\nاقتراح ثالث\nرابع"]],
            ]),
        ]);

        $suggestions = (new AnthropicWritingAssistant('sk-ant-test', 'claude-sonnet-5'))
            ->suggest('title', ['title' => 'تحضير رياضيات'], 3);

        $this->assertSame(['اقتراح أوّل', 'اقتراح ثانٍ', 'اقتراح ثالث'], $suggestions);
    }

    /** ردّ بلا نصّ عطل يُعلَن، لا «صفر اقتراحات». */
    public function test_an_empty_provider_response_is_reported_as_a_failure(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => []])]);

        $this->expectException(WritingAssistantUnavailable::class);
        (new AnthropicWritingAssistant('sk-ant-test', 'claude-sonnet-5'))->suggest('title', []);
    }
}
