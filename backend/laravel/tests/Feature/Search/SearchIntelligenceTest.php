<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Admin\AdminRole;
use App\Domain\Search\Analytics\SearchIntelligence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminFixtures;
use Tests\Feature\Commerce\CommerceFixtures;
use Tests\TestCase;

/**
 * ذكاءُ البحث — كلُّ نسبةٍ ببسطها ومقامها، محسوبةً من أحداثٍ معروفة سلفًا.
 */
class SearchIntelligenceTest extends TestCase
{
    use AdminFixtures, CommerceFixtures {
        CommerceFixtures::makeProduct insteadof AdminFixtures;
    }
    use RefreshDatabase;

    private function search(string $q, array $extra = []): int
    {
        return (int) DB::table('search_queries')->insertGetId($extra + [
            'raw_query' => $q, 'normalized_query' => $q, 'results_count' => 3, 'zero_result' => false,
            'provider' => 'pg', 'latency_ms' => 10, 'source' => 'search', 'created_at' => now()->subHour(),
        ]);
    }

    private function interact(int $search, int $product, string $kind, int $rank = 1, ?string $destination = null): void
    {
        DB::table('search_interactions')->insert(['search_query_id' => $search, 'product_id' => $product, 'kind' => $kind, 'rank' => $rank, 'destination' => $destination, 'created_at' => now()->subHour()]);
    }

    private function attribute(int $search, int $product, string $stage, ?int $order = null, ?int $value = null): void
    {
        DB::table('search_attributions')->insert(['search_query_id' => $search, 'product_id' => $product, 'visitor_hash' => str_repeat('v', 64), 'session_hash' => str_repeat('s', 64), 'stage' => $stage, 'order_id' => $order, 'value_minor' => $value, 'idempotency_key' => "{$stage}:{$search}:{$product}:{$order}", 'created_at' => now()->subHour()]);
    }

    private function order(string $status): int
    {
        return (int) DB::table('orders')->insertGetId([
            'number' => 'SI-'.bin2hex(random_bytes(4)), 'email' => 'buyer@example.com', 'status' => $status,
            'total_minor' => 1000, 'refunded_total_minor' => $status === 'REFUNDED' ? 1000 : 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_funnel_and_rates_come_from_real_events_with_their_denominators(): void
    {
        $a = $this->makeProduct('SI-A', 1000);
        $b = $this->makeProduct('SI-B', 2000);
        $s = str_repeat('s', 64);

        $converted = $this->search('كسور', ['session_hash' => $s]);
        $this->interact($converted, $a->id, 'impression', 1);
        $this->interact($converted, $a->id, 'click', 1, 'pdp');
        $this->attribute($converted, $a->id, 'add_to_cart');
        $paid = $this->order('PAID');
        $this->attribute($converted, $a->id, 'checkout', $paid, 1000);

        $noClick = $this->search('خلية', ['session_hash' => $s, 'created_at' => now()->subMinutes(30)]);
        $this->interact($noClick, $b->id, 'impression', 1);

        $this->search('قطط فضائية', ['zero_result' => true, 'results_count' => 0]);
        $this->search('بلا جلسة');

        $r = app(SearchIntelligence::class)->report(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addMinute());

        $this->assertSame(4, $r['overview']['searches']);
        $this->assertSame(['numerator' => 2, 'denominator' => 4, 'rate' => 0.5], $r['overview']['consentedShare']);
        $this->assertSame(['numerator' => 1, 'denominator' => 4, 'rate' => 0.25], $r['overview']['zeroResult']);
        $this->assertSame(['numerator' => 1, 'denominator' => 2, 'rate' => 0.5], $r['overview']['ctr'], 'CTR denominator = searches with a visible impression');
        $this->assertSame(['numerator' => 1, 'denominator' => 2, 'rate' => 0.5], $r['overview']['noClickSearches']);

        $f = $r['funnel'];
        $this->assertSame(2, $f['consentedSearches']);
        $this->assertSame(['numerator' => 1, 'denominator' => 2, 'rate' => 0.5], $f['searchToCart']);
        $this->assertSame(['numerator' => 1, 'denominator' => 2, 'rate' => 0.5], $f['searchToPurchase']);
        $this->assertSame(1000, $f['revenueInfluencedMinor']);

        $this->assertSame(['rank' => 1, 'numerator' => 1, 'denominator' => 2, 'rate' => 0.5], $r['rankCtr'][0]);
        $this->assertSame('قطط فضائية', $r['queries']['zeroResult'][0]['query']);
        $this->assertSame('خلية', $r['queries']['noClick'][0]['query']);
        $this->assertSame('كسور', $r['queries']['topConverting'][0]['query']);
    }

    public function test_an_unpaid_or_refunded_order_is_not_a_purchase(): void
    {
        $a = $this->makeProduct('SI-A', 1000);
        $search = $this->search('كسور', ['session_hash' => str_repeat('s', 64)]);
        $this->attribute($search, $a->id, 'checkout', $this->order('AWAITING_PAYMENT'), 1000);
        $refundedSearch = $this->search('كسور', ['session_hash' => str_repeat('t', 64)]);
        $this->attribute($refundedSearch, $a->id, 'checkout', $this->order('REFUNDED'), 700);

        $f = app(SearchIntelligence::class)->report(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addMinute())['funnel'];

        $this->assertSame(0, $f['searchToPurchase']['numerator']);
        $this->assertSame(2, $f['searchToCheckout']['numerator']);
        $this->assertSame(0, $f['revenueInfluencedMinor']);
        $this->assertSame(700, $f['revenueRefundedMinor']);
    }

    public function test_an_empty_window_reports_no_data_not_zero_percent(): void
    {
        $r = app(SearchIntelligence::class)->report(CarbonImmutable::now()->subDay(), CarbonImmutable::now());

        $this->assertNull($r['overview']['ctr']['rate']);
        $this->assertNull($r['funnel']['searchToPurchase']['rate']);
    }

    public function test_the_admin_endpoint_requires_analytics_read(): void
    {
        $this->getJson('/api/v1/admin/search/intelligence')->assertUnauthorized();

        $this->actingAsAdmin($this->makeAdmin(AdminRole::SearchManager));
        $this->getJson('/api/v1/admin/search/intelligence?from=2026-09-01&to=2026-09-30')->assertOk()->assertJsonStructure(['data' => ['overview', 'funnel', 'rankCtr', 'queries', 'products', 'filtersUsed', 'recovery', 'engines', 'latency']]);
    }
}
