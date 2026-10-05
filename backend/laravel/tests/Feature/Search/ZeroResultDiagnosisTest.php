<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Admin\AdminRole;
use App\Domain\Demand\MaterialRequest;
use App\Domain\Search\ZeroResultDiagnosis;
use App\Models\Grade;
use App\Models\SearchQuery;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * لماذا لم يجد الزائر شيئًا — سببٌ واحد وفعلٌ واحد لكلّ سؤالٍ فاشل.
 */
class ZeroResultDiagnosisTest extends TestCase
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

    /** @return array{reason: string, action: string, detail: array<string, mixed>} */
    private function diagnose(string $q): array
    {
        return app(ZeroResultDiagnosis::class)->diagnose($q);
    }

    public function test_a_query_the_store_answers_now_is_resolved(): void
    {
        $this->assertSame('resolved', $this->diagnose('تحضير')['reason']);
    }

    public function test_an_understood_intent_without_a_product_names_the_product_to_make(): void
    {
        $d = $this->diagnose('بوربوينت رياضيات ثاني ثانوي الدوال');

        $this->assertSame('missing_inventory', $d['reason']);
        $this->assertSame(['عرض بوربوينت', 'الرياضيات', 'الصف الثاني الثانوي'], $d['detail']['entities']);
        $this->assertSame('الدوال', $d['detail']['topic']);
        $this->assertStringContainsString('«الدوال»', $d['action']);
    }

    public function test_a_word_near_a_catalog_word_is_a_missing_synonym(): void
    {
        // «مراجعت» (ت typed for ة) is one edit from «مراجعه» in the corpus titles; «كواكب»
        // is unknown, so neither the typo correction nor recovery can answer the pair.
        $d = $this->diagnose('مراجعت كواكب');

        $this->assertSame('unknown_term', $d['reason'], json_encode($d, JSON_UNESCAPED_UNICODE));
        $this->assertSame('مراجعت', $d['detail']['term']);
        $this->assertSame('مراجعه', $d['detail']['nearest']);
    }

    public function test_nothing_the_store_knows_is_off_catalog(): void
    {
        $this->assertSame('off_catalog', $this->diagnose('xyzqwerty')['reason']);
        // A short word two edits from a catalog word is not a synonym suggestion.
        $this->assertSame('off_catalog', $this->diagnose('قطط')['reason']);
    }

    public function test_diagnosing_never_writes_to_the_search_log(): void
    {
        $before = SearchQuery::query()->count();
        $this->diagnose('بوربوينت رياضيات ثاني ثانوي الدوال');
        $this->diagnose('xyzqwerty');

        $this->assertSame($before, SearchQuery::query()->count());
    }

    public function test_the_admin_zero_result_list_carries_the_diagnosis(): void
    {
        foreach (['xyzqwerty', 'بوربوينت رياضيات ثاني ثانوي الدوال', ''] as $q) {
            SearchQuery::query()->create(['raw_query' => $q, 'normalized_query' => $q, 'results_count' => 0, 'zero_result' => true, 'created_at' => now()]);
        }
        $this->actingAsAdmin($this->makeAdmin(AdminRole::SearchManager));

        $rows = collect($this->getJson('/api/v1/admin/search/queries')->assertOk()->json('zeroResultQueries'))->keyBy('query');

        $this->assertSame('off_catalog', $rows['xyzqwerty']['diagnosis']['reason']);
        $this->assertSame('missing_inventory', $rows['بوربوينت رياضيات ثاني ثانوي الدوال']['diagnosis']['reason']);
        // An empty query is a facet browse whose filters the log does not keep: undiagnosable, not «resolved».
        $this->assertNull($rows['']['diagnosis']);
    }

    public function test_a_missing_product_counts_the_explicit_requests_for_the_same_thing(): void
    {
        $math = Subject::query()->where('slug', 'الرياضيات')->value('id');
        $g11 = Grade::query()->where('slug', 'ثاني-ثانوي')->value('id');
        $request = fn (array $a) => MaterialRequest::query()->create($a + [
            'fingerprint' => bin2hex(random_bytes(16)), 'status' => 'open', 'source' => 'form',
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $request(['subject_id' => $math, 'grade_id' => $g11, 'kind' => 'عرض بوربوينت', 'hits' => 4]);
        $request(['subject_id' => $math, 'grade_id' => $g11, 'hits' => 3, 'status' => 'declined']); // closed: not demand
        $request(['subject_id' => Subject::query()->where('slug', 'العلوم')->value('id'), 'grade_id' => $g11, 'hits' => 9]); // other subject
        $request(['query' => 'بوربوينت رياضيات ثاني ثانوي الدوال', 'source' => 'zero_result', 'hits' => 2]); // same words, no dimensions

        $d = $this->diagnose('بوربوينت رياضيات ثاني ثانوي الدوال');

        $this->assertSame('missing_inventory', $d['reason']);
        $this->assertSame(6, $d['detail']['requested']);
        $this->assertStringContainsString('6', $d['action']);
    }
}
