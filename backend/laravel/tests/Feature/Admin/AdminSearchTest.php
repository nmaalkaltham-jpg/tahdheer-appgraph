<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Search\Models\SearchSynonym;
use App\Models\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSearchTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function asSearchManager(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::SearchManager));
    }

    private function logQuery(string $query, int $times, bool $zero): void
    {
        for ($i = 0; $i < $times; $i++) {
            SearchQuery::query()->create([
                'raw_query' => $query,
                'normalized_query' => $query,
                'results_count' => $zero ? 0 : 7,
                'zero_result' => $zero,
                'created_at' => now(),
            ]);
        }
    }

    public function test_query_analytics_on_an_empty_log_returns_empty_lists_not_samples(): void
    {
        $this->asSearchManager();

        $this->getJson('/api/v1/admin/search/queries')
            ->assertOk()
            ->assertJsonPath('totals.queriesLogged', 0)
            ->assertJsonPath('totals.zeroResultQueries', 0)
            ->assertJsonPath('topQueries', [])
            ->assertJsonPath('zeroResultQueries', []);
    }

    public function test_query_analytics_are_real_aggregates_over_the_search_log(): void
    {
        $this->logQuery('تحضير رياضيات', 5, false);
        $this->logQuery('تحضير علوم', 2, false);
        $this->logQuery('منهج غير موجود', 3, true);
        $this->asSearchManager();

        $response = $this->getJson('/api/v1/admin/search/queries')->assertOk();

        $this->assertSame(10, $response->json('totals.queriesLogged'));
        $this->assertSame(3, $response->json('totals.zeroResultQueries'));
        $this->assertSame(3, $response->json('totals.distinctQueries'));

        $top = collect($response->json('topQueries'))->keyBy('query');
        $this->assertSame(5, $top['تحضير رياضيات']['count']);
        $this->assertSame(0, $top['تحضير رياضيات']['zeroResultCount']);
        $this->assertSame(3, $top['منهج غير موجود']['zeroResultCount']);

        $zero = $response->json('zeroResultQueries');
        $this->assertCount(1, $zero);
        $this->assertSame('منهج غير موجود', $zero[0]['query']);
        $this->assertSame(3, $zero[0]['count']);
    }

    public function test_the_analytics_window_excludes_rows_outside_the_requested_range(): void
    {
        $this->logQuery('قديم', 4, false);
        SearchQuery::query()->update(['created_at' => now()->subDays(90)]);
        $this->logQuery('حديث', 1, false);
        $this->asSearchManager();

        $this->getJson('/api/v1/admin/search/queries?days=7')
            ->assertOk()
            ->assertJsonPath('totals.queriesLogged', 1)
            ->assertJsonCount(1, 'topQueries');

        $this->getJson('/api/v1/admin/search/queries?days=365')
            ->assertOk()
            ->assertJsonPath('totals.queriesLogged', 5);
    }

    public function test_synonyms_can_be_listed_created_and_filtered(): void
    {
        SearchSynonym::query()->create([
            'term' => 'تحضير', 'synonym' => 'تحاضير', 'source' => 'seed', 'state' => 'active',
        ]);
        $this->asSearchManager();

        $this->getJson('/api/v1/admin/search/synonyms')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'seed');

        $this->postJson('/api/v1/admin/search/synonyms', [
            'term' => 'رياضيات', 'synonym' => 'حساب', 'direction' => 'two_way',
        ])
            ->assertCreated()
            ->assertJsonPath('data.term', 'رياضيات')
            ->assertJsonPath('data.direction', 'two_way')
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.source', 'manual');

        $this->getJson('/api/v1/admin/search/synonyms?source=manual')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.synonym', 'حساب');

        $this->postJson('/api/v1/admin/search/synonyms', ['term' => 'رياضيات', 'synonym' => 'حساب'])
            ->assertStatus(422)->assertJsonPath('code', 'SYNONYM_DUPLICATE');
    }

    public function test_the_source_tag_survives_deactivation_and_reactivation(): void
    {
        $seeded = SearchSynonym::query()->create([
            'term' => 'علوم', 'synonym' => 'ساينس', 'source' => 'seed', 'state' => 'active',
        ]);
        $this->asSearchManager();

        // A forged `source` in the payload must be ignored, not honoured.
        $this->patchJson("/api/v1/admin/search/synonyms/{$seeded->id}", [
            'state' => 'negative', 'source' => 'manual',
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'negative')
            ->assertJsonPath('data.source', 'seed');

        $this->patchJson("/api/v1/admin/search/synonyms/{$seeded->id}", ['state' => 'active'])
            ->assertOk()->assertJsonPath('data.state', 'active')->assertJsonPath('data.source', 'seed');

        $this->assertSame('seed', $seeded->fresh()->source);
    }

    public function test_rollback_restores_the_state_recorded_in_the_audit_log(): void
    {
        $synonym = SearchSynonym::query()->create([
            'term' => 'انجليزي', 'synonym' => 'english', 'source' => 'learned', 'state' => 'active',
        ]);
        $this->asSearchManager();

        // Nothing recorded yet — rollback has no previous version to restore.
        $this->patchJson("/api/v1/admin/search/synonyms/{$synonym->id}", ['action' => 'rollback'])
            ->assertStatus(422)->assertJsonPath('code', 'NO_PREVIOUS_VERSION');

        $this->patchJson("/api/v1/admin/search/synonyms/{$synonym->id}", ['state' => 'negative'])->assertOk();
        $this->assertSame('negative', $synonym->fresh()->state);

        $this->patchJson("/api/v1/admin/search/synonyms/{$synonym->id}", ['action' => 'rollback'])
            ->assertOk()->assertJsonPath('data.state', 'active')->assertJsonPath('data.source', 'learned');

        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'search.synonym.rolled_back', 'entity_type' => 'search_synonym',
        ]);
    }

    public function test_an_empty_patch_is_refused_rather_than_silently_accepted(): void
    {
        $synonym = SearchSynonym::query()->create([
            'term' => 'متوسط', 'synonym' => 'المتوسطه', 'source' => 'seed',
        ]);
        $this->asSearchManager();

        $this->patchJson("/api/v1/admin/search/synonyms/{$synonym->id}", [])
            ->assertStatus(422)->assertJsonPath('code', 'NOTHING_TO_UPDATE');
    }

    public function test_every_synonym_source_the_schema_allows_can_be_filtered(): void
    {
        // The DB check constraint allows ai_suggested (2026_09_15_000036); the filter rejected it.
        SearchSynonym::query()->create(['term' => 'حساب', 'synonym' => 'رياضيات', 'direction' => 'one_way', 'state' => 'pending', 'source' => 'ai_suggested', 'evidence_count' => 0]);
        $this->asSearchManager();

        $response = $this->getJson('/api/v1/admin/search/synonyms?source=ai_suggested')->assertOk();

        $this->assertSame(['حساب'], array_column($response->json('data'), 'term'));
        $this->assertContains('ai_suggested', $response->json('filters.sources'));
    }
}
