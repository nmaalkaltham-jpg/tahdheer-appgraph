<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Admin\AdminRole;
use App\Domain\Crawl\Models\CrawledPage;
use App\Domain\Crawl\Models\InternalLink;
use App\Domain\Crawl\SiteCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * زاحف الموقع: رسم الروابط، عمق النقر، اليتامى — وحدوده.
 *
 * الخطر الذي تحرسه هذه الاختبارات: زاحف يتبع عناوين يقرّرها محتوى صفحة هو
 * تزوير طلب من الخادم بثوب آخر، وزاحف بلا حدّ يأكل موارد الدفع والسلة.
 */
class SiteCrawlerTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private const ORIGIN = 'https://example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['seo.site_url' => self::ORIGIN]);
    }

    /** @param array<string, string> $pages المسار ⇒ HTML */
    private function fakeSite(array $pages, string $sitemap = ''): void
    {
        $responses = [
            self::ORIGIN.'/sitemap.xml' => Http::response($sitemap, $sitemap === '' ? 404 : 200),
        ];

        foreach ($pages as $path => $html) {
            $responses[self::ORIGIN.($path === '/' ? '/' : $path)] = Http::response($html, 200);
        }

        Http::fake($responses + ['*' => Http::response('', 404)]);
    }

    private function page(string $body): string
    {
        return "<html><head><title>صفحة</title></head><body>{$body}</body></html>";
    }

    /* ───── الرسم ───── */

    public function test_it_builds_edges_with_anchor_text_and_counts_degrees(): void
    {
        $this->fakeSite([
            '/' => $this->page('<a href="/shop">المتجر</a><a href="/about">من نحن</a>'),
            '/shop' => $this->page('<a href="/about">من نحن</a>'),
            '/about' => $this->page('<a href="/">الرئيسية</a>'),
        ]);

        $run = app(SiteCrawler::class)->crawl();

        $this->assertSame(3, $run->pages);

        // «من نحن» يُشار إليها من صفحتين — عدّ حواف لا تقدير.
        $about = CrawledPage::query()->where('run_id', $run->id)->where('path', '/about')->firstOrFail();
        $this->assertSame(2, $about->inbound);
        $this->assertSame(1, $about->outbound);

        // ونصّ المرساة محفوظ: بدونه لا يُقال إن المرساة عامّة أو مضلّلة.
        $this->assertSame(
            'المتجر',
            InternalLink::query()->where('run_id', $run->id)->where('to_path', '/shop')->value('anchor'),
        );
    }

    /** عمق النقر أقصر مسافة — والطابور بعرض أوّلًا هو ما يضمن ذلك. */
    public function test_click_depth_is_the_shortest_path_not_the_discovery_path(): void
    {
        $this->fakeSite([
            '/' => $this->page('<a href="/a">أ</a><a href="/deep">عميق</a>'),
            '/a' => $this->page('<a href="/b">ب</a>'),
            '/b' => $this->page('<a href="/deep">عميق</a>'),
            '/deep' => $this->page('نهاية'),
        ]);

        $run = app(SiteCrawler::class)->crawl();

        $depth = fn (string $p) => CrawledPage::query()
            ->where('run_id', $run->id)->where('path', $p)->value('depth');

        $this->assertSame(0, $depth('/'));
        $this->assertSame(1, $depth('/a'));
        $this->assertSame(2, $depth('/b'));
        // مسار عبر /a/b عمقه 3، والرابط المباشر من الرئيسية 1 — الأقصر يفوز.
        $this->assertSame(1, $depth('/deep'), 'حُسب العمق بمسار طويل فأبلغ عن دفن لم يقع');
    }

    /** اليتيم: صفحة تُجيب 200 ولا يشير إليها رابط — والخريطة تكشفه. */
    public function test_a_page_only_in_the_sitemap_is_reported_as_an_orphan(): void
    {
        $this->fakeSite(
            [
                '/' => $this->page('<a href="/shop">المتجر</a>'),
                '/shop' => $this->page('<a href="/">الرئيسية</a>'),
                '/buried' => $this->page('لا يشير إليّ أحد'),
            ],
            sitemap: '<urlset><loc>'.self::ORIGIN.'/buried</loc></urlset>',
        );

        $run = app(SiteCrawler::class)->crawl();

        $buried = CrawledPage::query()->where('run_id', $run->id)->where('path', '/buried')->firstOrFail();
        $this->assertSame(0, $buried->inbound);
        $this->assertSame('sitemap', $buried->discovered_from);
        $this->assertSame(1, $run->orphans);

        // واليتيم الوحيد هو المدفون: الرئيسية مستثناة بحكم كونها المدخل،
        // ولو لم يشر إليها رابط واحد لما صارت «غير قابلة للاكتشاف».
        $orphans = CrawledPage::query()->where('run_id', $run->id)
            ->where('status', 200)->where('inbound', 0)->where('path', '!=', '/')
            ->pluck('path')->all();
        $this->assertSame(['/buried'], $orphans);
    }

    /**
     * عمق النقر يُقاس بالروابط لا بالعضوية في الخريطة.
     *
     * كانت بذور الخريطة تأخذ عمق 0 — أي «على بُعد نقرة من الرئيسية» — وهي قد
     * لا يصلها رابط واحد. الإشارة تنقلب: أعمق صفحة في الموقع تبدو الأقرب.
     * `null` هي الجواب الصادق: لم يُوصَل إليها.
     */
    public function test_a_sitemap_only_page_has_no_depth_not_depth_zero(): void
    {
        $this->fakeSite(
            [
                '/' => $this->page('<a href="/shop">المتجر</a>'),
                '/shop' => $this->page('ok'),
                '/buried' => $this->page('لا يشير إليّ أحد'),
            ],
            sitemap: '<urlset><loc>'.self::ORIGIN.'/buried</loc></urlset>',
        );

        $run = app(SiteCrawler::class)->crawl();

        $depth = fn (string $p) => CrawledPage::query()
            ->where('run_id', $run->id)->where('path', $p)->value('depth');

        $this->assertSame(0, $depth('/'));
        $this->assertSame(1, $depth('/shop'));
        $this->assertNull($depth('/buried'), 'صفحةٌ من الخريطة وحدها ادّعت عمقًا لا تملكه');
        // وأقصى عمق لا يتأثّر بغير الموصول.
        $this->assertSame(1, $run->max_depth);
    }

    public function test_broken_pages_are_counted_and_kept(): void
    {
        Http::fake([
            self::ORIGIN.'/sitemap.xml' => Http::response('', 404),
            self::ORIGIN.'/' => Http::response($this->page('<a href="/gone">مفقود</a>'), 200),
            self::ORIGIN.'/gone' => Http::response('', 404),
        ]);

        $run = app(SiteCrawler::class)->crawl();

        $this->assertSame(1, $run->broken);
        $this->assertSame(404, CrawledPage::query()->where('run_id', $run->id)->where('path', '/gone')->value('status'));
    }

    /* ───── الحدود والأمان ───── */

    /** لا يخرج من الأصل — الرابط الخارجي حافة تُسجَّل ولا تُزار. */
    public function test_it_never_leaves_the_configured_origin(): void
    {
        $this->fakeSite([
            '/' => $this->page(
                '<a href="https://evil.test/x">خارجي</a>'
                .'<a href="//evil.test/y">بروتوكول-نسبي</a>'
                .'<a href="http://127.0.0.1:8080/admin">داخلي للشبكة</a>'
                .'<a href="/shop">المتجر</a>',
            ),
            '/shop' => $this->page('ok'),
        ]);

        $run = app(SiteCrawler::class)->crawl();

        $paths = CrawledPage::query()->where('run_id', $run->id)->pluck('path')->all();
        $this->assertSame(['/', '/shop'], $paths);

        // ولا حافة واحدة تشير خارج الأصل.
        foreach (InternalLink::query()->where('run_id', $run->id)->pluck('to_path') as $to) {
            $this->assertStringStartsWith('/', $to);
            $this->assertStringStartsNotWith('//', $to);
        }

        Http::assertNotSent(fn ($request) => ! str_starts_with($request->url(), self::ORIGIN));
    }

    /** الاستعلام ليس هوية: ?page=2 لا يفجّر الرسم بعناوين لا نهائية. */
    public function test_query_strings_do_not_create_new_nodes(): void
    {
        $this->fakeSite([
            '/' => $this->page(
                '<a href="/shop?page=2">٢</a><a href="/shop?page=3&utm=x">٣</a><a href="/shop">المتجر</a>',
            ),
            '/shop' => $this->page('ok'),
        ]);

        $run = app(SiteCrawler::class)->crawl();

        $this->assertSame(2, $run->pages, 'معاملات الاستعلام صنعت عقدًا جديدة');
    }

    /** الأصول والمسارات الخاصّة لا تُزار. */
    public function test_assets_and_private_paths_are_skipped(): void
    {
        $this->fakeSite([
            '/' => $this->page(
                '<a href="/style.css">نمط</a><a href="/cart">السلة</a>'
                .'<a href="/api/v1/x">واجهة</a><a href="/shop">المتجر</a>',
            ),
            '/shop' => $this->page('ok'),
        ]);

        $run = app(SiteCrawler::class)->crawl();

        $this->assertSame(['/', '/shop'], CrawledPage::query()->where('run_id', $run->id)->pluck('path')->all());
    }

    /** السقف يُحترم: موقع بحلقة لا نهائية لا يشغّل الزاحف إلى الأبد. */
    public function test_the_page_cap_is_enforced(): void
    {
        Http::fake([
            self::ORIGIN.'/sitemap.xml' => Http::response('', 404),
            '*' => Http::response($this->page('<a href="/a">أ</a><a href="/b">ب</a><a href="/c">ج</a>'), 200),
        ]);

        $run = app(SiteCrawler::class)->crawl(maxPages: 3);

        $this->assertSame(3, $run->pages);
    }

    /** بلا أصل مضبوط لا يُعرف الداخلي من الخارجي — فلا زحف أصلًا. */
    public function test_it_refuses_to_run_without_a_configured_origin(): void
    {
        config(['seo.site_url' => null]);

        $this->expectException(\RuntimeException::class);
        app(SiteCrawler::class)->crawl();
    }

    /* ───── الحماية ───── */

    public function test_crawl_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/crawl')->assertStatus(401);
        $this->postJson('/api/v1/admin/crawl/run')->assertStatus(401);
    }

    /** بلا جولة: حالة صريحة لا أصفار تُقرأ «كل شيء سليم». */
    public function test_the_report_says_no_run_instead_of_reporting_zeros(): void
    {
        $admin = $this->makeAdmin(AdminRole::Owner);

        $body = $this->actingAsAdmin($admin)->getJson('/api/v1/admin/crawl')->assertOk()->json();

        $this->assertNull($body['data']);
        $this->assertStringContainsString('لم تُشغَّل', $body['message']);
    }
}
