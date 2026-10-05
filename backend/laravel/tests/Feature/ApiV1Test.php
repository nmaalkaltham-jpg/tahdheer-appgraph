<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPrice;
use Database\Seeders\CurriculumSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    /**
     * الجاهزية تفحص كل تبعية حرجة، لا قاعدة البيانات وحدها.
     *
     * النسخة السابقة كانت تفحص DB فقط وتردّ 200 وValkey ساقط — وعليه
     * الجلسات والسلة والكاش وحدود المعدّل. مُنسّق يقرأ ذلك يُبقي الحاوية في
     * الخدمة بينما كل عملية شراء تفشل.
     */
    public function test_health_checks_every_critical_dependency(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        $checks = $response->json('checks');
        foreach (['database', 'cache', 'queue', 'storage'] as $name) {
            $this->assertArrayHasKey($name, $checks, "الفحص {$name} غائب");
            $this->assertTrue($checks[$name]['ok'], "الفحص {$name} فاشل");
            $this->assertIsFloat((float) $checks[$name]['ms']);
        }

        $response->assertJsonPath('status', 'ok');
    }

    /** لا اسم مضيف ولا منفذ ولا نصّ استثناء يخرج من نقطة عامّة. */
    public function test_health_leaks_no_infrastructure_detail(): void
    {
        $body = $this->getJson('/api/v1/health')->getContent();

        foreach (['127.0.0.1', 'localhost', 'redis', 'valkey', 'pgsql', 'password', 'Exception'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $body);
        }
    }

    /** معرّف الارتباط يُعاد دائمًا — بدونه لا يُتتبَّع عطل عبر الطبقات. */
    public function test_every_api_response_carries_a_request_id(): void
    {
        $this->getJson('/api/v1/health')
            ->assertHeader('X-Request-Id');

        // ويُحترم الوارد فتبقى السلسلة واحدة من Next إلى العامل.
        $this->withHeaders(['X-Request-Id' => 'trace-abc-123'])
            ->getJson('/api/v1/health')
            ->assertHeader('X-Request-Id', 'trace-abc-123');

        // ولا يُوثَق بنصّ حرّ: قيمة مشوّهة تُستبدل بمعرّف مُولَّد.
        $forged = $this->withHeaders(['X-Request-Id' => 'has spaces; and ;semicolons'])
            ->getJson('/api/v1/health');
        $this->assertNotSame(
            'has spaces; and ;semicolons',
            $forged->headers->get('X-Request-Id'),
        );
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->getJson('/api/v1/health')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-site');
    }

    public function test_catalog_endpoints_serve_the_seeded_vocabulary(): void
    {
        $this->seed(CurriculumSeeder::class);

        $this->getJson('/api/v1/catalog/stages')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/catalog/subjects')->assertOk()->assertJsonCount(18, 'data');
        $this->getJson('/api/v1/catalog/kinds')->assertOk()->assertJsonCount(7, 'data');

        $grades = $this->getJson('/api/v1/catalog/grades')->assertOk()->assertJsonCount(12, 'data');
        $grades->assertJsonPath('data.0.stage.slug', 'ابتدائي');

        $this->getJson('/api/v1/catalog/nonsense')->assertNotFound();
    }

    public function test_products_lists_published_only_with_honest_pricing(): void
    {
        Product::query()->create(['sku' => 'TD-D1', 'slug' => 'draft-item', 'title' => 'مسودة', 'status' => 'draft']);
        $published = Product::query()->create([
            'sku' => 'TD-P1', 'slug' => 'published-item', 'title' => 'تحضير لغتي',
            'status' => 'published', 'published_at' => now(),
        ]);
        ProductPrice::query()->create(['product_id' => $published->id, 'regular_minor' => 40000, 'sale_minor' => 30000]);

        $response = $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', 'published-item')
            ->assertJsonPath('data.0.priceMinor', 30000)
            ->assertJsonPath('data.0.discountPct', 25)
            ->assertJsonPath('data.0.onSale', true);
        // No fabricated ratings: the field simply does not exist.
        $this->assertArrayNotHasKey('rating_avg', $response->json('data.0'));

        $this->getJson('/api/v1/products/published-item')->assertOk()
            ->assertJsonPath('data.sku', 'TD-P1');
        $this->getJson('/api/v1/products/draft-item')->assertNotFound();
    }

    // The /search behavior moved to the Part 6 contract — covered by
    // tests/Feature/Search/SearchApiTest and RelevanceTest.
}
