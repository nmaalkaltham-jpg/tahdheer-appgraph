<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Admin\AdminRole;
use App\Domain\Sitemaps\Models\SitemapAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * الحلقة التي كانت مفقودة بين أمرٍ يعمل وشاشةٍ مغلقة.
 *
 * `sitemap:audit` مجدولٌ كلّ ليلة ويقيس النقاء والتغطية وما طُرد ولماذا،
 * ثمّ يطبع نتيجته على المخرَج القياسيّ — وفي حاوية لا يقرؤه أحد. فالقياس
 * يجري كلّ ليلة ويضيع كلّ ليلة، وتبقى شاشة `/seo-health/sitemap` مغلقةً
 * ببوّابةٍ تسمّي الناقص: «تخزين نتائج `sitemap:audit` وعرضها».
 */
class SitemapAuditPersistenceTest extends TestCase
{
    /*
     * بلا `SeedsSeoFixtures`: تثبيتتا SEO والإدارة تعرّفان `makeProduct`
     * كلتاهما فيتصادمان. والتدقيق لا يحتاج كتالوجًا مزروعًا — يقيس ما يجده،
     * وقياسُ خريطةٍ خاوية نتيجةٌ صحيحة تُحفظ وتُقرأ كغيرها.
     */
    use AdminFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://example.test']);
    }

    public function test_the_scheduled_audit_stores_its_measurement(): void
    {
        $this->assertSame(0, SitemapAudit::query()->count());

        $this->artisan('sitemap:audit');

        $row = SitemapAudit::query()->firstOrFail();

        $this->assertNotNull($row->ran_at);
        $this->assertSame($row->offered - $row->expelled, $row->kept);
        /* التقرير كاملًا لا ملخّصه: العيّنات وأسبابها هي ما يُصلَح به شيء. */
        $this->assertArrayHasKey('providers', $row->report);
        $this->assertArrayHasKey('dormant', $row->report);
    }

    /** ليلتان صفّان: القيمة في الاتّجاه، وصفٌّ يُدهَس يجعل كلّ ليلةٍ هي الأولى. */
    public function test_each_run_is_kept_so_the_trend_is_readable(): void
    {
        $this->artisan('sitemap:audit');
        $this->artisan('sitemap:audit');

        $this->assertSame(2, SitemapAudit::query()->count());
    }

    public function test_the_admin_reads_the_stored_measurement(): void
    {
        $this->artisan('sitemap:audit');
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/seo/sitemap/audit')
            ->assertOk()
            ->assertJsonPath('latest.clean', SitemapAudit::query()->value('clean'))
            ->assertJsonStructure([
                'latest' => ['ranAt', 'clean', 'offered', 'kept', 'expelled', 'coveragePct', 'missingCount', 'report'],
                'history',
            ]);
    }

    /**
     * لا قياسَ بعد ≠ خريطةٌ نظيفة.
     *
     * `latest: null` صريحةٌ كي تقول الشاشة «لم يجرِ تدقيقٌ بعد» بدل أن تُقرأ
     * أصفارٌ نتيجةً — والصفر هنا يعني «لا رابط عُرض»، وهو عطلٌ لا نجاح.
     */
    public function test_before_the_first_run_the_answer_is_an_explicit_absence(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/seo/sitemap/audit')
            ->assertOk()
            ->assertJsonPath('latest', null)
            ->assertJsonPath('history', []);
    }

    /**
     * تقريرُ ظهورٍ يُقرأ بصلاحية التحليلات.
     *
     * ومدير المنتجات لا يملكها، فيُردّ ٤٠٣ صريحةً — لا شاشةً بأصفارٍ تُقرأ
     * «الخريطة خاوية» بينما هي محجوبةٌ عنه وحده. ومدير SEO يملكها، وهو صاحب
     * هذا التقرير أصلًا، فيُقرأ الاتّجاهان معًا.
     */
    public function test_reading_the_audit_needs_the_analytics_permission(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));
        $this->getJson('/api/v1/admin/seo/sitemap/audit')->assertForbidden();

        $this->actingAsAdmin($this->makeAdmin(AdminRole::SeoManager));
        $this->getJson('/api/v1/admin/seo/sitemap/audit')->assertOk();
    }
}
