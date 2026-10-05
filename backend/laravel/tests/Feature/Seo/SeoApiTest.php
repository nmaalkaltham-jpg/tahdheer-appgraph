<?php

namespace Tests\Feature\Seo;

use App\Domain\Redirects\RedirectRule;
use App\Domain\Redirects\RedirectService;
use App\Domain\SEO\MetadataService;
use App\Models\Setting;
use App\Providers\RateLimitServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The SEO API contract. Every response key is camelCase. */
class SeoApiTest extends TestCase
{
    use RefreshDatabase, SeedsSeoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://example.test']);
    }

    public function test_the_sitemap_index_lists_every_provider_with_its_state(): void
    {
        $response = $this->getJson('/api/v1/sitemap/index')
            ->assertOk()
            ->assertJsonStructure(['providers' => [['provider', 'label', 'enabled', 'reason', 'url']]]);

        $providers = collect($response->json('providers'))->keyBy('provider');

        foreach (['products', 'stages', 'grades', 'subjects', 'semesters', 'kinds', 'curriculum', 'content'] as $name) {
            $this->assertTrue($providers->has($name), 'المزوّد مفقود: '.$name);
        }

        $this->assertTrue($providers['products']['enabled']);
        $this->assertNull($providers['products']['reason']);

        // Every route class has shipped, so every provider is live.
        foreach (['stages', 'grades', 'subjects', 'semesters', 'kinds', 'curriculum'] as $name) {
            $this->assertTrue($providers[$name]['enabled'], 'المسار مبني: '.$name);
            $this->assertNull($providers[$name]['reason']);
        }
    }

    public function test_a_provider_returns_its_url_set_with_lastmod(): void
    {
        $this->makePrice($this->makeProduct('TD-1', 'منتج'), 2500);

        $this->getJson('/api/v1/sitemap/products')
            ->assertOk()
            ->assertJsonPath('provider', 'products')
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('urls.0.loc', '/products/td-1')
            ->assertJsonStructure(['urls' => [['loc', 'lastmod']]]);
    }

    public function test_a_dormant_provider_returns_an_honest_empty_set(): void
    {
        // A dormant provider always states WHY — never a silent empty map.
        $this->disableRoute('curriculum');

        $this->getJson('/api/v1/sitemap/curriculum')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('urls', [])
            ->assertJsonPath('count', 0)
            ->assertJsonPath('reason', 'مسارات /curriculum معطَّلة بإعداد');
    }

    public function test_an_unknown_provider_is_a_404_with_a_typed_error(): void
    {
        $this->getJson('/api/v1/sitemap/nonsense')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'PROVIDER_NOT_FOUND');
    }

    public function test_llms_data_lists_only_real_published_products_with_coverage(): void
    {
        $this->makePrice($this->makeProduct('TD-1', 'تحضير الرياضيات', [
            'short_desc' => 'تحضير كامل لوحدة الكسور.',
        ]), 2500);
        $this->makePrice($this->makeProduct('TD-2', 'مسودة', ['status' => 'draft']), 2500);
        $this->makePrice($this->makeProduct('TD-3', 'بلا وصف'), 2500);

        $response = $this->getJson('/api/v1/llms-data')->assertOk();

        $this->assertSame('التحاضير الحديثة', $response->json('brand'));
        $this->assertCount(2, $response->json('items'));
        $this->assertSame('https://example.test/products/td-1', $response->json('items.0.url'));
        $this->assertSame('تحضير كامل لوحدة الكسور.', $response->json('items.0.oneLiner'));
        // The gap is visible, not filled with generated prose.
        $this->assertNull($response->json('items.1.oneLiner'));

        $this->assertSame(2, $response->json('coverage.published'));
        $this->assertSame(2, $response->json('coverage.included'));
        $this->assertSame(1, $response->json('coverage.withOneLiner'));
    }

    public function test_llms_coverage_is_unmeasured_when_the_catalog_is_empty(): void
    {
        $this->getJson('/api/v1/llms-data')
            ->assertOk()
            ->assertJsonPath('items', [])
            ->assertJsonPath('coverage.pct', null);
    }

    public function test_the_robots_policy_comes_from_the_shared_indexability_policy(): void
    {
        $response = $this->getJson('/api/v1/robots')->assertOk();

        $this->assertContains('/cart', $response->json('disallow'));
        $this->assertContains('/checkout', $response->json('disallow'));
        $this->assertContains('/search', $response->json('disallow'));
        $this->assertContains('/downloads', $response->json('disallow'));
        $this->assertSame('https://example.test/sitemap.xml', $response->json('sitemap'));
    }

    /**
     * مضيف الـAPI لا يصير أصل المتجر أبدًا — العطل الذي كسر الإنتاج شهرًا.
     *
     * `app.url` في الإنتاج هو `https://api.tahdheer.com`. حين كان آخر حلقة في
     * سلسلة الأصل، أعلنت **كل** صفحة رابطًا معياريًّا إلى مضيف يخدم JSON،
     * وأعلن robots.txt خريطة موقع تُعيد 404. بلا خطأ ولا تحذير.
     *
     * الصواب عند غياب الأصل: رابط نسبي — صحيح بالحذف، لا ادّعاء خاطئ.
     */
    public function test_the_api_host_is_never_used_as_the_public_origin(): void
    {
        config(['seo.site_url' => null, 'app.url' => 'https://api.example.test']);

        $product = $this->makeProduct('TD-ORIGIN', 'تحضير الرياضيات');
        $meta = app(MetadataService::class)->forProduct($product);

        $this->assertStringNotContainsString('api.example.test', $meta['canonical']);
        $this->assertStringStartsWith('/products/', $meta['canonical']);
        $this->assertStringNotContainsString(
            'api.example.test',
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'مضيف الـAPI تسرّب إلى وسوم الصفحة',
        );
    }

    public function test_robots_reports_no_sitemap_when_the_site_url_is_unconfigured(): void
    {
        /*
         * الأصل غير مضبوط يعني: لا صفّ إعدادات ولا config/seo.php.
         * `app.url` لم يعد مصدرًا — هو مضيف الـAPI، وليس أصل المتجر أبدًا.
         */
        config(['seo.site_url' => null, 'app.url' => 'https://api.example.test']);

        // NOT_CONFIGURED, never a guessed hostname.
        $this->getJson('/api/v1/robots')
            ->assertOk()
            ->assertJsonPath('sitemap', null)
            ->assertJsonPath('host', null);
    }

    public function test_a_settings_row_overrides_the_public_site_origin(): void
    {
        // The public storefront origin is not necessarily the API's own host,
        // so it is admin-editable at runtime — and read from config, never from
        // env(), which returns null once config:cache has run in production.
        Setting::query()->create([
            'key' => 'seo.site_url',
            'value' => ['url' => 'https://storefront.test/'],
        ]);

        $this->getJson('/api/v1/robots')
            ->assertOk()
            ->assertJsonPath('sitemap', 'https://storefront.test/sitemap.xml')
            ->assertJsonPath('host', 'https://storefront.test');
    }

    public function test_the_indexability_inspector_explains_its_verdict(): void
    {
        $this->getJson('/api/v1/seo/indexability?path=/stages/primary%3Forderby%3Dprice')
            ->assertOk()
            ->assertJsonPath('verdict', 'excluded')
            ->assertJsonPath('rule', 'IDX-FACET')
            ->assertJsonPath('robots', 'noindex, follow');
    }

    public function test_the_product_seo_payload_carries_metadata_visible_and_schema(): void
    {
        $this->makePrice($this->makeProduct('TD-1', 'منتج', ['short_desc' => 'وصف قصير.']), 2500);

        $response = $this->getJson('/api/v1/seo/products/td-1')
            ->assertOk()
            ->assertJsonStructure([
                'metadata' => ['title', 'renderedTitle', 'description', 'canonical', 'robots', 'openGraph', 'twitter'],
                'visible' => ['breadcrumbs', 'faqs', 'facts', 'author', 'description'],
                'schema' => ['@context', '@graph'],
            ]);

        // The visible description and the schema description are the SAME text.
        $graph = collect($response->json('schema.@graph'));
        $product = $graph->firstWhere('@type', 'Product');
        $this->assertSame($response->json('visible.description'), $product['description']);

        // The breadcrumb trail and the BreadcrumbList are the same array.
        $crumbs = $response->json('visible.breadcrumbs');
        $list = $graph->firstWhere('@type', 'BreadcrumbList');
        $this->assertCount(count($crumbs), $list['itemListElement']);
        $this->assertSame($crumbs[0]['name'], $list['itemListElement'][0]['name']);
    }

    public function test_an_unpublished_product_has_no_seo_payload(): void
    {
        $this->makeProduct('TD-1', 'مسودة', ['status' => 'draft']);

        $this->getJson('/api/v1/seo/products/td-1')->assertNotFound();
    }

    public function test_resolve_returns_the_target_and_counts_the_hit(): void
    {
        app(RedirectService::class)->save(['from_path' => '/old/*', 'to_path' => '/new/*']);

        $this->getJson('/api/v1/redirects/resolve?path=/old/thing')
            ->assertOk()
            ->assertJsonPath('match.target', '/new/thing')
            ->assertJsonPath('match.statusCode', 301);

        $this->assertSame(1, RedirectRule::query()->value('hits'));
    }

    public function test_resolve_reports_no_match_as_a_normal_answer(): void
    {
        $this->getJson('/api/v1/redirects/resolve?path=/nothing-here')
            ->assertOk()
            ->assertJsonPath('match', null);
    }

    public function test_resolve_requires_a_path(): void
    {
        $this->getJson('/api/v1/redirects/resolve')->assertStatus(422);
    }

    public function test_reporting_a_404_aggregates_and_is_accepted(): void
    {
        $this->postJson('/api/v1/not-found', ['path' => '/gone', 'referer' => 'https://ref.test'])
            ->assertStatus(202)
            ->assertJsonPath('path', '/gone')
            ->assertJsonPath('hits', 1);

        $this->postJson('/api/v1/not-found', ['path' => '/gone'])
            ->assertStatus(202)
            ->assertJsonPath('hits', 2);

        $this->assertDatabaseCount('not_found_hits', 1);
    }

    public function test_the_not_found_endpoint_is_rate_limited(): void
    {
        // A public write endpoint must not be an open flood gate.
        // الحدّ يُقرأ من مصدره لا يُكرَّر رقمًا هنا: رقم مكتوب في الاختبار
        // ينحرف عن السياسة بصمت أوّل مرّة تُعدَّل.
        $limit = RateLimitServiceProvider::LIMITS['telemetry'];

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/v1/not-found', ['path' => '/gone-'.$i])->assertStatus(202);
        }

        $blocked = $this->postJson('/api/v1/not-found', ['path' => '/gone-over']);
        $blocked->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    }
}
