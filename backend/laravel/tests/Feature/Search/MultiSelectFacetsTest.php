<?php

namespace Tests\Feature\Search;

use App\Domain\Search\Providers\MeilisearchProvider;
use App\Domain\Search\SearchRequest;
use App\Models\Grade;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * اختيارٌ متعدّد في الأوجه: «أو» داخل البُعد، «و» بين الأبعاد، وتوزيعُ البُعد
 * المختار محسوبٌ بلا مرشِّحه — وإلّا لما أمكن إضافةُ قيمةٍ ثانية أصلًا.
 */
class MultiSelectFacetsTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
        $this->seedCorpus();
        $this->makeProduct('TD-5001', 'تحضير رياضيات خامس ابتدائي', 1500, [
            'resource_type' => 'تحضير', 'format' => 'PDF',
            'stage_id' => Stage::query()->where('slug', 'ابتدائي')->value('id'),
            'grade_id' => Grade::query()->where('slug', 'خامس-ابتدائي')->value('id'),
            'subject_id' => Subject::query()->where('slug', 'الرياضيات')->value('id'),
        ]);
    }

    /** @return list<string> */
    private function skus(string $query): array
    {
        $skus = $this->getJson('/api/v1/search?'.$query)->assertOk()->json('results.items.*.sku');
        sort($skus);

        return $skus;
    }

    public function test_values_within_a_dimension_are_ored(): void
    {
        $this->assertSame(['TD-1002', 'TD-2001'], $this->skus('subject='.rawurlencode('العلوم')));
        $this->assertSame(['TD-5001'], $this->skus('subject='.rawurlencode('الرياضيات')));
        $this->assertSame(
            ['TD-1002', 'TD-2001', 'TD-5001'],
            $this->skus('subject[]='.rawurlencode('العلوم').'&subject[]='.rawurlencode('الرياضيات')),
        );
    }

    public function test_dimensions_are_anded(): void
    {
        $both = 'subject[]='.rawurlencode('العلوم').'&subject[]='.rawurlencode('الرياضيات');

        $this->assertSame(['TD-2001'], $this->skus($both.'&grade='.rawurlencode('سادس-ابتدائي')));
        $this->assertSame(
            ['TD-2001', 'TD-5001'],
            $this->skus($both.'&grade[]='.rawurlencode('سادس-ابتدائي').'&grade[]='.rawurlencode('خامس-ابتدائي')),
        );
        $this->assertSame(['TD-1002', 'TD-5001'], $this->skus($both.'&kind='.rawurlencode('تحضير')));
    }

    public function test_the_chosen_dimension_counts_ignore_its_own_filter(): void
    {
        $taxonomy = $this->getJson('/api/v1/search?subject='.rawurlencode('العلوم'))->assertOk()->json('facets.taxonomy');
        $subject = collect($taxonomy)->firstWhere('key', 'subject');
        $counts = collect($subject['values'] ?? [])->pluck('count', 'value')->all();

        // «الرياضيات» still offered, so a second subject can be added.
        $this->assertSame(1, $counts['الرياضيات'] ?? null);
        $this->assertSame(2, $counts['العلوم'] ?? null);

        // Other dimensions stay conjunctive: grades are counted inside «العلوم» only.
        $grade = collect($taxonomy)->firstWhere('key', 'grade');
        $this->assertSame(['سادس-ابتدائي' => 1], collect($grade['values'] ?? [])->pluck('count', 'value')->all());
    }

    public function test_a_single_value_keeps_the_old_contract_and_many_become_a_list(): void
    {
        $this->getJson('/api/v1/search?subject='.rawurlencode('العلوم'))
            ->assertJsonPath('filtersExtracted.subject', 'العلوم');
        $this->getJson('/api/v1/search?subject[]='.rawurlencode('العلوم'))
            ->assertJsonPath('filtersExtracted.subject', 'العلوم');
        $this->getJson('/api/v1/search?subject[]='.rawurlencode('العلوم').'&subject[]='.rawurlencode('الرياضيات'))
            ->assertJsonPath('filtersExtracted.subject', ['العلوم', 'الرياضيات']);
    }

    public function test_too_many_values_or_non_strings_are_rejected(): void
    {
        $many = implode('&', array_map(fn (int $i): string => 'subject[]=s'.$i, range(1, 11)));
        $this->getJson('/api/v1/search?'.$many)->assertStatus(422);
        $this->getJson('/api/v1/search?subject[0][x]=1')->assertStatus(422);
    }

    public function test_meilisearch_expresses_the_same_or_as_in(): void
    {
        Http::fake(['http://meili.test/*' => Http::response(['hits' => [], 'totalHits' => 0, 'facetDistribution' => []])]);

        (new MeilisearchProvider('http://meili.test', 'k'))->search(new SearchRequest(
            query: '',
            rawQuery: '',
            filters: ['stage' => null, 'grade' => null, 'subject' => ['العلوم', 'الرياضيات'], 'semester' => null, 'kind' => 'تحضير', 'format' => null],
        ));

        Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), '/search')
            && in_array('subject IN ["العلوم", "الرياضيات"]', $sent['filter'], true)
            && in_array('resource_type = "تحضير"', $sent['filter'], true));
    }
}
