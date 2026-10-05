<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Search\Models\SearchSynonym;
use App\Domain\Search\SynonymLearner;
use App\Models\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرادفات المتعلَّمة — من «كلمةٌ مجهولة قريبةٌ من كلمةٍ يعرفها الكتالوج»
 * (`ZeroResultDiagnosis`) إلى مرادفٍ **معلّق** ينتظر موافقة المشرف.
 */
class SynonymLearnerTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
        $this->seedCorpus();
    }

    private function zero(string $q, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            SearchQuery::query()->create(['raw_query' => $q, 'normalized_query' => $q, 'results_count' => 0, 'zero_result' => true, 'created_at' => now()]);
        }
    }

    private function learned(): ?SearchSynonym
    {
        return SearchSynonym::query()->where('term', 'مراجعت')->where('synonym', 'مراجعه')->first();
    }

    public function test_a_repeated_near_miss_becomes_a_pending_learned_synonym_with_its_evidence(): void
    {
        $this->zero('مراجعت كواكب', 3);

        $summary = app(SynonymLearner::class)->learn();

        $row = $this->learned();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->state);
        $this->assertSame('learned', $row->source);
        $this->assertSame('one_way', $row->direction);
        $this->assertSame(3, (int) $row->evidence_count);
        $this->assertSame(1, $summary['created']);
    }

    public function test_learning_twice_refreshes_the_evidence_and_never_duplicates(): void
    {
        $this->zero('مراجعت كواكب', 2);
        app(SynonymLearner::class)->learn();
        $this->zero('مراجعت كواكب', 3);

        $summary = app(SynonymLearner::class)->learn();

        $this->assertSame(1, SearchSynonym::query()->where('term', 'مراجعت')->count());
        $this->assertSame(5, (int) $this->learned()->evidence_count);
        $this->assertSame(['created' => 0, 'updated' => 1, 'kept' => 0], $summary);
    }

    public function test_a_single_miss_is_noise_not_evidence(): void
    {
        $this->zero('مراجعت كواكب', 1);

        app(SynonymLearner::class)->learn();

        $this->assertNull($this->learned());
    }

    public function test_a_decision_the_admin_already_made_is_never_overwritten(): void
    {
        SearchSynonym::query()->create(['term' => 'مراجعت', 'synonym' => 'مراجعه', 'direction' => 'one_way', 'state' => 'negative', 'source' => 'manual', 'evidence_count' => 0]);
        $this->zero('مراجعت كواكب', 4);

        $summary = app(SynonymLearner::class)->learn();

        $this->assertSame('negative', $this->learned()->state);
        $this->assertSame(0, (int) $this->learned()->evidence_count);
        $this->assertSame(1, $summary['kept']);
    }

    public function test_only_a_near_miss_teaches_a_synonym(): void
    {
        $this->zero('xyzqwerty', 5);
        $this->zero('بوربوينت رياضيات ثاني ثانوي الدوال', 5);

        app(SynonymLearner::class)->learn();

        $this->assertSame(0, SearchSynonym::query()->where('source', 'learned')->count());
    }

    public function test_a_learned_synonym_changes_nothing_until_approved_then_rescues_the_query(): void
    {
        $this->zero('مراجعت كواكب', 3);
        app(SynonymLearner::class)->learn();

        $this->getJson('/api/v1/search?q='.rawurlencode('مراجعت كواكب'))->assertJsonPath('zeroResult', true);

        $this->learned()->update(['state' => 'active']);

        $this->getJson('/api/v1/search?q='.rawurlencode('مراجعت كواكب'))->assertJsonPath('zeroResult', false);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->zero('مراجعت كواكب', 3);

        $summary = app(SynonymLearner::class)->learn(dryRun: true);

        $this->assertNull($this->learned());
        $this->assertSame(1, $summary['created']);
    }
}
