<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Admin\AdminRole;
use App\Models\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * ما خدم البحث فعلًا — مقيسًا من `search_queries` لا متوقَّعًا من الإعداد.
 *
 * شاشة «البحث الداخلي» في اللوحة تقرأ `measured` منذ aee3921، والخادم لم
 * يُصدره قطّ: فكانت الشاشة كلّها تنكسر عند التصيير («تعذّر عرض الشاشة»).
 * وأعمدة التتبّع كانت في قاعدة التطوير وحدها — هجرتُها لم تدخل المستودع.
 */
class ServingTraceTest extends TestCase
{
    use AdminFixtures, SeedsSearchFixtures {
        SeedsSearchFixtures::makeProduct insteadof AdminFixtures;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
        $this->seedCorpus();
    }

    public function test_every_search_records_who_served_it_and_how_long_it_took(): void
    {
        $this->getJson('/api/v1/search?q='.rawurlencode('ملزمة'))->assertOk();

        $row = SearchQuery::query()->latest('id')->firstOrFail();
        $this->assertSame('pg', $row->provider);
        $this->assertFalse((bool) $row->degraded);
        $this->assertNull($row->degraded_reason);
        $this->assertIsInt($row->latency_ms);
    }

    public function test_a_configured_engine_that_is_down_is_recorded_as_a_degraded_search(): void
    {
        config(['search.provider' => 'meilisearch']);
        Http::fake(['*/health' => Http::response(['status' => 'unavailable'], 503)]);

        $this->getJson('/api/v1/search?q='.rawurlencode('ملزمة'))->assertOk();

        $row = SearchQuery::query()->latest('id')->firstOrFail();
        $this->assertSame('pg', $row->provider);
        $this->assertTrue((bool) $row->degraded);
        $this->assertSame('unhealthy', $row->degraded_reason);
    }

    public function test_the_index_status_reports_measured_serving_beside_the_prediction(): void
    {
        $log = fn (array $extra) => SearchQuery::query()->create($extra + ['raw_query' => 'q', 'normalized_query' => 'q', 'results_count' => 1, 'zero_result' => false, 'created_at' => now()]);
        $log(['provider' => 'pg', 'latency_ms' => 10]);
        $log(['provider' => 'pg', 'latency_ms' => 20]);
        $log(['provider' => 'pg', 'latency_ms' => 30, 'degraded' => true, 'degraded_reason' => 'unhealthy']);
        $log([]); // written before the trace existed: provider «unknown»
        $log([]);
        $log(['provider' => 'pg', 'latency_ms' => 99, 'created_at' => now()->subDays(3)]); // outside the window
        $this->actingAsAdmin($this->makeAdmin(AdminRole::SearchManager));

        $data = $this->getJson('/api/v1/admin/search/index')->assertOk()->json('data');
        $m = $data['measured'];

        $this->assertSame(24, $m['windowHours']);
        $this->assertSame(3, $m['totalQueries']);
        $this->assertSame(2, $m['unmeasuredRows']);
        $this->assertSame(['pg' => 3, 'unknown' => 2], $m['byProvider']);
        $this->assertSame(1, $m['degradedQueries']);
        $this->assertEqualsWithDelta(1 / 3, $m['degradedRate'], 0.0001);
        $this->assertSame(['unhealthy' => 1], $m['reasons']);
        $this->assertSame(20, $m['latencyP50Ms']);
        $this->assertSame('pg', $m['servedPath']);
        $this->assertFalse($data['predictionDiverges']);
    }

    public function test_an_empty_window_is_unmeasured_not_healthy(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::SearchManager));

        $data = $this->getJson('/api/v1/admin/search/index')->assertOk()->json('data');

        $this->assertSame(0, $data['measured']['totalQueries']);
        $this->assertNull($data['measured']['degradedRate']);
        $this->assertNull($data['measured']['servedPath']);
        $this->assertNull($data['predictionDiverges']);
    }
}
