<?php

namespace Tests\Feature\Seo;

use App\Domain\Redirects\RedirectService;
use App\Domain\SEO\ProductPageSeo;
use App\Domain\Sitemaps\SitemapAuditor;
use App\Domain\Sitemaps\SitemapBuilder;
use App\Domain\Sitemaps\SitemapEntry;
use App\Domain\Sitemaps\SitemapRegistry;
use App\Domain\Sitemaps\SitemapUrlState;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** MIP §7.3 — purity (§7.3.6) and truthful lastmod (§7.3.7). */
class SitemapPurityTest extends TestCase
{
    use RefreshDatabase, SeedsSeoFixtures;

    private SitemapRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://example.test']);
        $this->registry = app(SitemapRegistry::class);
    }

    /** @return list<string> every loc a provider yields */
    private function locsOf(string $provider): array
    {
        $source = $this->registry->get($provider);
        $this->assertNotNull($source, 'مزوّد غير مسجَّل: '.$provider);

        return array_map(
            static fn (SitemapEntry $e) => $e->loc,
            iterator_to_array($source->entries(), false),
        );
    }

    public function test_an_unpublished_product_never_reaches_the_sitemap(): void
    {
        $this->makePrice($this->makeProduct('TD-1', 'منشور'), 1000);
        $this->makePrice($this->makeProduct('TD-2', 'مسودة', ['status' => 'draft']), 1000);
        $this->makePrice($this->makeProduct('TD-3', 'متقاعد', ['status' => 'retired']), 1000);
        $this->makePrice($this->makeProduct('TD-4', 'مجدول', ['published_at' => now()->addWeek()]), 1000);

        $locs = $this->locsOf('products');

        $this->assertSame(['/products/td-1'], $locs);
    }

    public function test_the_audit_reports_a_clean_map_with_real_coverage(): void
    {
        $this->makePrice($this->makeProduct('TD-1', 'منشور'), 1000);
        $this->makePrice($this->makeProduct('TD-2', 'منشور آخر'), 1500);

        $report = app(SitemapAuditor::class)->audit();

        $this->assertTrue($report['clean']);
        $this->assertSame(0, $report['expelled']);
        $this->assertSame(100.0, $report['coveragePct']);

        $products = collect($report['providers'])->firstWhere('provider', 'products');
        $this->assertSame(2, $products['kept']);
    }

    public function test_the_audit_expels_a_url_that_is_a_redirect_source(): void
    {
        $this->makePrice($this->makeProduct('TD-1', 'منشور'), 1000);
        // The product URL now redirects: the sitemap must list the DESTINATION.
        app(RedirectService::class)->save([
            'from_path' => '/products/td-1',
            'to_path' => '/products/td-2',
        ]);

        $report = app(SitemapAuditor::class)->audit();

        $this->assertFalse($report['clean']);
        $this->assertSame(1, $report['expelled']);

        $products = collect($report['providers'])->firstWhere('provider', 'products');
        $this->assertSame(0.0, $products['coveragePct'], 'المنتج الوحيد المعروض طُرد');
        $this->assertStringContainsString('مصدر تحويل', $products['samples'][0]['reason']);
        // The static-content map is untouched, so overall purity is partial —
        // reported as it really is, not rounded up to a clean bill of health.
        $this->assertLessThan(100.0, $report['coveragePct']);
    }

    public function test_coverage_is_unmeasured_rather_than_a_flattering_hundred_percent(): void
    {
        // No products at all: coverage is «غير مقيس» (null), never 100%.
        $report = app(SitemapAuditor::class)->audit();

        $products = collect($report['providers'])->firstWhere('provider', 'products');
        $this->assertNull($products['coveragePct']);
    }

    public function test_dormant_providers_state_a_reason_and_emit_nothing(): void
    {
        $this->makePrice($this->makeProduct('TD-1', 'منشور', ['subject_id' => null]), 1000);

        // صنف مسار مُطفأ من الإعدادات لا يُصدر عنوانًا واحدًا.
        $this->disableRoute('curriculum');
        $this->registry = app()->make(SitemapRegistry::class);
        $this->assertSame([], $this->locsOf('curriculum'));

        $report = app(SitemapAuditor::class)->audit();
        $curriculum = collect($report['dormant'])->firstWhere('provider', 'curriculum');

        $this->assertNotNull($curriculum, 'المزوّد المعطّل يُبلَّغ عنه صراحة');
        $this->assertStringContainsString('معطَّلة', $curriculum['reason']);
    }

    public function test_a_thin_archive_stays_out_until_it_reaches_three_products(): void
    {
        $this->enableRoute('subjects');
        $subject = Subject::query()->create(['slug' => 'math', 'name_ar' => 'رياضيات']);

        $this->makeProduct('TD-1', 'أ', ['subject_id' => $subject->id]);
        $this->makeProduct('TD-2', 'ب', ['subject_id' => $subject->id]);

        $this->assertSame([], $this->locsOf('subjects'), 'منتجان دون العتبة');

        $this->makeProduct('TD-3', 'ج', ['subject_id' => $subject->id]);
        $this->registry = app()->make(SitemapRegistry::class);

        $this->assertSame(['/subjects/math'], $this->locsOf('subjects'));
    }

    /**
     * سلسلة فيها مسافة تُنشر بشرطة لا بـ`%20`.
     *
     * اثنتا عشرة مادة من ثماني عشرة في هذا المتجر تحمل مسافة («اللغة
     * الإنجليزية»)، وهي عناوين الذيل الطويل نفسها. لو أعلنت الخريطة المسافة
     * خامًا لأعلنت عنوانًا لا يخدمه المتجر، ولو أعلنتها `%20` لحمل كل عنوان
     * قانوني تسلسل هروب. القاعدة واحدة على الجانبين — وهذا ما يثبّته الاختبار.
     */
    public function test_a_slug_with_a_space_is_published_as_a_hyphenated_path(): void
    {
        $this->enableRoute('subjects');
        $subject = Subject::query()->create([
            'slug' => 'اللغة الإنجليزية',
            'name_ar' => 'اللغة الإنجليزية',
        ]);

        foreach (['TD-1', 'TD-2', 'TD-3'] as $sku) {
            $this->makeProduct($sku, 'ملف', ['subject_id' => $subject->id]);
        }
        $this->registry = app()->make(SitemapRegistry::class);

        $this->assertSame(['/subjects/اللغة-الإنجليزية'], $this->locsOf('subjects'));

        // والفتات تشير إلى العنوان نفسه — لا إلى صيغة ثانية للأرشيف ذاته.
        $product = Product::query()->where('sku', 'TD-1')->with(['subject'])->firstOrFail();
        $crumbs = app(ProductPageSeo::class)->breadcrumbs($product);

        $this->assertContains(
            '/subjects/اللغة-الإنجليزية',
            array_column($crumbs, 'url'),
            'الفتات تشير إلى عنوان غير الذي يخدمه المتجر',
        );
    }

    /* ─── lastmod honesty (§7.3.7) ─── */

    public function test_rebuilding_without_a_content_change_does_not_bump_lastmod(): void
    {
        $product = $this->makeProduct('TD-1', 'عنوان ثابت');
        $this->makePrice($product, 2500);

        $directory = $this->tempDirectory();
        $builder = app(SitemapBuilder::class);

        $builder->build($directory);
        $first = SitemapUrlState::query()->where('loc', '/products/td-1')->value('lastmod_at');

        // A full day passes and the sitemap is rebuilt — nothing about the
        // product changed, so lastmod must NOT move.
        $this->travel(1)->days();
        $builder->build($directory);
        $second = SitemapUrlState::query()->where('loc', '/products/td-1')->value('lastmod_at');

        $this->assertTrue($first->equalTo($second), 'إعادة البناء وحدها لا تُحرّك lastmod');
    }

    public function test_a_real_title_change_moves_lastmod(): void
    {
        $product = $this->makeProduct('TD-1', 'العنوان الأول');
        $this->makePrice($product, 2500);

        $directory = $this->tempDirectory();
        app(SitemapBuilder::class)->build($directory);
        $before = SitemapUrlState::query()->where('loc', '/products/td-1')->value('lastmod_at');

        $this->travel(1)->days();
        $product->update(['title' => 'العنوان الثاني']);
        app(SitemapBuilder::class)->build($directory);
        $after = SitemapUrlState::query()->where('loc', '/products/td-1')->value('lastmod_at');

        $this->assertTrue($after->greaterThan($before), 'تغيّر المحتوى يُحرّك lastmod');
    }

    public function test_a_sale_expiring_moves_lastmod_because_the_visible_price_changed(): void
    {
        $product = $this->makeProduct('TD-1', 'منتج');
        $this->makePrice($product, 40000, [
            'sale_minor' => 30000,
            'sale_from' => now()->subDay(),
            'sale_to' => now()->addDay(),
        ]);

        $directory = $this->tempDirectory();
        app(SitemapBuilder::class)->build($directory);
        $before = SitemapUrlState::query()->where('loc', '/products/td-1')->value('lastmod_at');

        // The sale ends. The shopper now sees 400.00, so the page really changed.
        $this->travel(2)->days();
        app(SitemapBuilder::class)->build($directory);
        $after = SitemapUrlState::query()->where('loc', '/products/td-1')->value('lastmod_at');

        $this->assertTrue($after->greaterThan($before));
    }

    public function test_an_unrelated_column_touch_does_not_move_lastmod(): void
    {
        $product = $this->makeProduct('TD-1', 'عنوان');
        $this->makePrice($product, 2500);

        $directory = $this->tempDirectory();
        app(SitemapBuilder::class)->build($directory);
        $before = SitemapUrlState::query()->where('loc', '/products/td-1')->value('lastmod_at');

        $this->travel(1)->days();
        // `sku` is not a watched field — a crawler would not notice it.
        $product->update(['sku' => 'TD-1-RENAMED']);
        app(SitemapBuilder::class)->build($directory);
        $after = SitemapUrlState::query()->where('loc', '/products/td-1')->value('lastmod_at');

        $this->assertTrue($before->equalTo($after));
    }

    /* ─── the chunked, atomic builder ─── */

    public function test_the_builder_writes_parts_and_an_index_leaving_no_temp_files(): void
    {
        $this->makePrice($this->makeProduct('TD-1', 'منتج'), 2500);
        $directory = $this->tempDirectory();

        $result = app(SitemapBuilder::class)->build($directory);

        $this->assertFileExists($directory.'/sitemap.xml');
        $this->assertFileExists($directory.'/sitemap-products-1.xml');
        $this->assertSame([], glob($directory.'/*.tmp'), 'لا يبقى ملف مؤقت بعد إعادة التسمية');

        $index = file_get_contents($directory.'/sitemap.xml');
        foreach ($result['parts'] as $part) {
            $this->assertStringContainsString($part['file'], $index, 'الفهرس يشير إلى كل جزء');
        }

        $part = file_get_contents($directory.'/sitemap-products-1.xml');
        $this->assertStringContainsString('<loc>https://example.test/products/td-1</loc>', $part);
        $this->assertStringContainsString('<lastmod>', $part);
    }

    /** @var list<string> directories this test created, removed in tearDown */
    private array $tempDirectories = [];

    private function tempDirectory(): string
    {
        $directory = storage_path('framework/testing/sitemaps-'.$this->name());
        File::ensureDirectoryExists($directory);
        File::cleanDirectory($directory);
        $this->tempDirectories[] = $directory;

        return $directory;
    }

    /**
     * Remove ONLY the directories this test created.
     *
     * `storage/framework/testing` is shared: it holds a tracked .gitignore and
     * the fake disks other suites use. Deleting the whole tree from here would
     * destroy another suite's fixtures and a versioned file.
     */
    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        $this->tempDirectories = [];

        parent::tearDown();
    }

    /**
     * منتج منشور لا تحمله أي خريطة = يتيم اكتشاف، والتغطية لا تراه.
     *
     * السيناريو الحقيقي: يُعطَّل مولِّد المنتجات (تبديل مسار في اللوحة) فيخرج
     * الكتالوج كلّه من الخريطة صامتًا. `coveragePct` تبقى «غير مقيسة» لأن
     * المولِّد لم يعرض شيئًا — تقيس نقاء ما عُرض، لا اكتمال ما يجب أن يُعرض.
     * المصالحة مع القاعدة هي وحدها التي ترى الغياب.
     */
    public function test_the_audit_catches_published_products_that_no_provider_offered(): void
    {
        $product = $this->makeProduct('TD-ORPHAN', 'منتج منشور');
        $this->makePrice($product, 2000);

        // المولِّد مفعَّل: لا غياب.
        $this->enableRoute('products');
        $clean = app(SitemapAuditor::class)->audit();
        $this->assertSame([], $clean['missing'], 'أبلغ عن غياب لا وجود له');

        // ثم يُعطَّل — والمنتج ما زال منشورًا ومؤهَّلًا للفهرسة.
        Setting::query()->updateOrCreate(
            ['key' => 'seo.routes.products'],
            ['value' => ['enabled' => false]],
        );

        $after = app(SitemapAuditor::class)->audit();

        $this->assertContains(
            '/products/'.$product->slug,
            array_column($after['missing'], 'path'),
            'الكتالوج خرج من الخريطة ولم يُكتشف',
        );
        $this->assertFalse($after['clean'], 'خريطة ينقصها كتالوج منشور ليست نظيفة');
        /*
         * وهذا بيت القصيد: التغطية تقول 100٪ بينما الكتالوج كلّه خارج الخريطة.
         * المولِّدات الباقية عرضت روابطها ونجت كلّها، فالنقاء تامّ — والاكتمال
         * صفر. رقمٌ سليم يقود إلى استنتاج خاطئ ما لم تُقاس المصالحة معه.
         */
        $this->assertSame(100.0, $after['coveragePct']);
    }
}
