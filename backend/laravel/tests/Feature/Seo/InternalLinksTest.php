<?php

namespace Tests\Feature\Seo;

use App\Models\Grade;
use App\Models\Kind;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** كتلة الروابط الداخليّة في حمولة SEO لصفحة المنتج. */
class InternalLinksTest extends TestCase
{
    use RefreshDatabase, SeedsSeoFixtures;

    private Stage $stage;

    private Grade $grade;

    private Grade $nextGrade;

    private Subject $subject;

    private Semester $semester;

    private Semester $otherSemester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stage = Stage::create(['slug' => 'ابتدائي', 'name_ar' => 'ابتدائي']);
        $this->grade = Grade::create(['slug' => 'الأول', 'name_ar' => 'الصف الأول', 'stage_id' => $this->stage->id, 'position' => 1]);
        $this->nextGrade = Grade::create(['slug' => 'الثاني', 'name_ar' => 'الصف الثاني', 'stage_id' => $this->stage->id, 'position' => 2]);
        $this->subject = Subject::create(['slug' => 'الرياضيات', 'name_ar' => 'الرياضيات']);
        $this->semester = Semester::create(['slug' => 'الفصل-الأول', 'name_ar' => 'الفصل الأول']);
        $this->otherSemester = Semester::create(['slug' => 'الفصل-الثاني', 'name_ar' => 'الفصل الثاني']);
        Kind::create(['slug' => 'تحضير', 'name_ar' => 'تحضير']);
    }

    /** @return array<string, mixed> */
    private function taxonomy(array $overrides = []): array
    {
        return array_merge([
            'stage_id' => $this->stage->id, 'grade_id' => $this->grade->id, 'subject_id' => $this->subject->id,
            'semester_id' => $this->semester->id, 'resource_type' => 'تحضير',
        ], $overrides);
    }

    public function test_the_four_groups_are_built_from_the_real_catalog(): void
    {
        $product = $this->makeProduct('TD-MAIN', 'تحضير رياضيات أول ف١', $this->taxonomy());
        $this->makePrice($product, 2500);

        $otherSemester = $this->makeProduct('TD-S2', 'تحضير رياضيات أول ف٢', $this->taxonomy(['semester_id' => $this->otherSemester->id]));
        // الفصل نفسه لا يدخل «نفس المادة»: المجموعة تقصد الفصول الأخرى.
        $this->makeProduct('TD-S1B', 'أوراق رياضيات أول ف١', $this->taxonomy(['resource_type' => 'أوراق عمل']));
        $nextGrade = $this->makeProduct('TD-G2', 'تحضير رياضيات ثاني', $this->taxonomy(['grade_id' => $this->nextGrade->id]));
        $science = Subject::create(['slug' => 'العلوم', 'name_ar' => 'العلوم']);
        $this->makeProduct('TD-SCI', 'تحضير علوم أول ف١', $this->taxonomy(['subject_id' => $science->id]));

        $groups = $this->getJson('/api/v1/seo/products/td-main')->assertOk()->json('internalLinks');

        $this->assertSame(['نفس المادة', 'نفس النوع لصفوف أخرى', 'مواد الصف الأول'], array_column($groups, 'title'));

        $this->assertSame([
            ['label' => $otherSemester->title, 'href' => '/products/td-s2'],
            ['label' => 'كل ملفات الرياضيات الصف الأول', 'href' => '/subjects/الرياضيات'],
        ], $groups[0]['links']);

        $this->assertSame([
            ['label' => $nextGrade->title, 'href' => '/products/td-g2'],
            ['label' => 'كل تحضير الصف الأول', 'href' => '/kinds/تحضير'],
        ], $groups[1]['links']);

        $this->assertSame([
            ['label' => 'تحضير العلوم — الصف الأول', 'href' => '/subjects/العلوم'],
        ], $groups[2]['links']);

        foreach ($groups as $group) {
            $this->assertLessThanOrEqual(3, count($group['links']));
        }
    }

    public function test_a_product_without_taxonomy_has_no_groups(): void
    {
        $product = $this->makeProduct('TD-BARE', 'منتج بلا تصنيف');
        $this->makePrice($product, 2500);

        $this->getJson('/api/v1/seo/products/td-bare')->assertOk()->assertJsonPath('internalLinks', []);
    }

    public function test_archive_links_are_dropped_when_the_route_is_dormant(): void
    {
        $product = $this->makeProduct('TD-MAIN', 'تحضير', $this->taxonomy());
        $this->makePrice($product, 2500);
        $this->makeProduct('TD-S2', 'تحضير ف٢', $this->taxonomy(['semester_id' => $this->otherSemester->id]));
        $this->disableRoute('subjects');
        $this->disableRoute('kinds');

        $groups = $this->getJson('/api/v1/seo/products/td-main')->assertOk()->json('internalLinks');

        $this->assertSame(['نفس المادة'], array_column($groups, 'title'));
        $this->assertSame([['label' => 'تحضير ف٢', 'href' => '/products/td-s2']], $groups[0]['links']);
    }
}
