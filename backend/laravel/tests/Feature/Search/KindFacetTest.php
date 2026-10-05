<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Grade;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نوعُ الملف (تحضير، أوراق عمل، اختبارات…) — البُعدُ الذي يُباع به هذا المتجر —
 * مرشِّحٌ بأعدادٍ حقيقيّة في صفحة النتائج كبقيّة الأبعاد.
 */
class KindFacetTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
        $math = ['subject_id' => Subject::query()->where('slug', 'الرياضيات')->value('id'),
            'grade_id' => Grade::query()->where('slug', 'سادس-ابتدائي')->value('id'),
            'stage_id' => Stage::query()->where('slug', 'ابتدائي')->value('id'), 'format' => 'PDF'];
        $this->makeProduct('KF-1', 'تحضير الرياضيات السادس', 1000, $math + ['resource_type' => 'تحضير']);
        $this->makeProduct('KF-2', 'تحضير الرياضيات السادس ٢', 1000, $math + ['resource_type' => 'تحضير']);
        $this->makeProduct('KF-3', 'أوراق عمل الرياضيات السادس', 1000, $math + ['resource_type' => 'أوراق عمل']);
    }

    public function test_the_taxonomy_facets_count_each_kind_in_the_result_set(): void
    {
        $facets = collect($this->getJson('/api/v1/search?q='.rawurlencode('رياضيات سادس'))->assertOk()->json('facets.taxonomy'))->keyBy('key');

        $this->assertArrayHasKey('kind', $facets->all());
        $this->assertSame('نوع الملف', $facets['kind']['label']);
        $this->assertSame([['value' => 'تحضير', 'count' => 2], ['value' => 'أوراق عمل', 'count' => 1]], $facets['kind']['values']);
    }

    public function test_choosing_a_kind_narrows_the_results(): void
    {
        $skus = $this->getJson('/api/v1/search?q='.rawurlencode('رياضيات سادس').'&kind='.rawurlencode('أوراق عمل'))->assertOk()->json('results.items.*.sku');

        $this->assertSame(['KF-3'], $skus);
    }
}
