<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Models\Order;
use App\Models\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    public function test_dashboard_reports_real_counts_from_real_tables(): void
    {
        $this->makeProduct('TD-DB-1');                       // draft
        $this->makeProduct('TD-DB-2', ['status' => 'published']);
        $this->makeProduct('TD-DB-3', ['status' => 'published']);

        Order::query()->create(['number' => 'TD-1', 'email' => 'a@example.com', 'status' => 'PAID', 'total_minor' => 40000, 'vat_minor' => 5217]);
        Order::query()->create(['number' => 'TD-2', 'email' => 'b@example.com', 'status' => 'COMPLETED', 'total_minor' => 25000, 'vat_minor' => 3261]);
        // Not money in the bank — must be excluded from revenue.
        Order::query()->create(['number' => 'TD-3', 'email' => 'c@example.com', 'status' => 'AWAITING_PAYMENT', 'total_minor' => 99000, 'vat_minor' => 12913]);

        SearchQuery::query()->create(['raw_query' => 'أ', 'normalized_query' => 'أ', 'results_count' => 3, 'zero_result' => false]);
        SearchQuery::query()->create(['raw_query' => 'ب', 'normalized_query' => 'ب', 'results_count' => 0, 'zero_result' => true]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('measured.products.total', 3)
            ->assertJsonPath('measured.products.byStatus.draft', 1)
            ->assertJsonPath('measured.products.byStatus.published', 2)
            ->assertJsonPath('measured.products.byStatus.retired', 0)
            ->assertJsonPath('measured.orders.total', 3)
            ->assertJsonPath('measured.orders.byStatus.PAID', 1)
            ->assertJsonPath('measured.revenue.ordersCount', 2)
            ->assertJsonPath('measured.revenue.totalMinor', 65000)
            ->assertJsonPath('measured.revenue.vatMinor', 8478)
            ->assertJsonPath('measured.revenue.paidStatuses', ['PAID', 'PROCESSING', 'COMPLETED'])
            ->assertJsonPath('measured.search.queriesLogged', 2)
            ->assertJsonPath('measured.search.zeroResultQueries', 1)
            ->assertJsonPath('measured.entitlements.total', 0);
    }

    public function test_unmeasurable_metrics_are_not_configured_with_a_reason_never_a_zero(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $response = $this->getJson('/api/v1/admin/dashboard')->assertOk();

        $notConfigured = collect($response->json('notConfigured'))->keyBy('key');

        $this->assertNotEmpty($notConfigured);
        $this->assertTrue($notConfigured->has('visitors'));
        $this->assertTrue($notConfigured->has('conversionRate'));

        foreach ($notConfigured as $metric) {
            $this->assertSame('NOT_CONFIGURED', $metric['state']);
            $this->assertNotEmpty($metric['reason']);
            $this->assertArrayNotHasKey('value', $metric); // never a number standing in for absence
        }

        // And they are never smuggled into the measured block as zeros.
        $this->assertArrayNotHasKey('visitors', $response->json('measured'));
        $this->assertArrayNotHasKey('conversionRate', $response->json('measured'));
    }

    public function test_an_empty_platform_reports_observed_zeros_only_for_things_it_actually_counted(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $response = $this->getJson('/api/v1/admin/dashboard')->assertOk();

        // These ARE measured: the tables exist and were queried, and are empty.
        $this->assertSame(0, $response->json('measured.products.total'));
        $this->assertSame(0, $response->json('measured.orders.total'));
        $this->assertSame(0, $response->json('measured.revenue.totalMinor'));
        $this->assertNotNull($response->json('generatedAt'));
    }
}
