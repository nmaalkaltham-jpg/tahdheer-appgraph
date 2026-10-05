<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Domain\Curriculum\CurriculumHubs;
use App\Models\Grade;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الصفحة المحورية «مادة × صف» — التقاطع الذي هو صياغة الطلب نفسها.
 *
 * ثلاث قواعد من المواصفة، وكل اختبار هنا يحرس واحدة منها:
 * العتبة، ومنع الضرب الكارتيزي، والمطابقة بدل تحليل المعرّف.
 */
class CurriculumHubTest extends TestCase
{
    use RefreshDatabase;

    private Subject $subject;

    private Grade $grade;

    protected function setUp(): void
    {
        parent::setUp();
        config(['seo.site_url' => 'https://example.test']);

        $this->subject = Subject::query()->create([
            'slug' => 'اللغة الإنجليزية',
            'name_ar' => 'اللغة الإنجليزية',
        ]);
        $stage = Stage::query()->create(['slug' => 'ابتدائي', 'name_ar' => 'ابتدائي']);
        $this->grade = Grade::query()->create([
            'slug' => 'رابع-ابتدائي',
            'name_ar' => 'الصف الرابع الابتدائي',
            'stage_id' => $stage->id,
        ]);
    }

    /** المسار كما يرسله المتصفّح: جزء مسار مُرمَّز بالنسب المئوية. */
    private function hubUrl(string $slug = 'اللغة-الإنجليزية-رابع-ابتدائي'): string
    {
        return '/api/v1/curriculum/subject-grade/'.rawurlencode($slug);
    }

    private function makeProducts(int $count): void
    {
        foreach (range(1, $count) as $index) {
            $product = Product::query()->create([
                'sku' => 'TD-'.$index,
                'slug' => 'p-'.$index,
                'title' => 'ملف '.$index,
                'status' => 'published',
                'published_at' => now()->subDay(),
                'subject_id' => $this->subject->id,
                'grade_id' => $this->grade->id,
            ]);

            ProductPrice::query()->create([
                'product_id' => $product->id,
                'regular_minor' => 2500,
                'currency' => 'SAR',
            ]);
        }
    }

    /* ───── العتبة ───── */

    /**
     * دون ثلاثة منتجات لا صفحة — لا صفحة رقيقة بـnoindex.
     *
     * «صفحة فارغة بمظهر ممتلئ أسوأ من غيابها»: عشرات الصفحات المتشابهة بمنتج
     * واحد هي بالضبط ما يُصنَّف محتوى ضعيفًا، وأثره على الموقع كلّه.
     */
    public function test_a_cluster_under_three_products_has_no_page_at_all(): void
    {
        $this->makeProducts(2);

        $this->assertSame([], app(CurriculumHubs::class)->eligible());

        $this->getJson($this->hubUrl())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'HUB_NOT_ELIGIBLE');
    }

    public function test_the_page_appears_the_moment_the_cluster_reaches_three(): void
    {
        $this->makeProducts(3);

        $body = $this->getJson($this->hubUrl())
            ->assertOk()
            ->json('data');

        $this->assertSame('اللغة الإنجليزية — الصف الرابع الابتدائي', $body['label']);
        $this->assertSame(3, $body['count']);
        // مفردات المرشِّح تُعاد كما فُهرست — لا كما كُتبت في المسار.
        $this->assertSame('اللغة الإنجليزية', $body['subjectSlug']);
        $this->assertSame('رابع-ابتدائي', $body['gradeSlug']);
    }

    /* ───── لا ضرب كارتيزي ───── */

    /** العناقيد تُحسب من صفوف حقيقية، لا من كل تقاطع ممكن بين المصطلحات. */
    public function test_only_intersections_real_products_occupy_are_generated(): void
    {
        $this->makeProducts(3);
        Subject::query()->create(['slug' => 'الفيزياء', 'name_ar' => 'الفيزياء']);
        Grade::query()->create([
            'slug' => 'أول-ثانوي',
            'name_ar' => 'الصف الأول الثانوي',
            'stage_id' => Stage::query()->value('id'),
        ]);

        // مادّتان وصفّان = أربعة تقاطعات ممكنة، وواحد فقط يشغله منتج.
        $hubs = app(CurriculumHubs::class)->eligible();

        $this->assertCount(1, $hubs);
        $this->assertSame('/curriculum/subject-grade/اللغة-الإنجليزية-رابع-ابتدائي', $hubs[0]['path']);
    }

    /* ───── المطابقة لا التحليل ───── */

    /**
     * المعرّف يُطابَق ولا يُفكّ.
     *
     * `اللغة-الإنجليزية-رابع-ابتدائي` فيه أربع شرطات، وواحدة منها فقط تفصل
     * المادة عن الصف. أي محاولة لتحليل السلسلة تخطئ؛ والمطابقة على المعرّفات
     * المولَّدة تصيب دائمًا.
     */
    public function test_a_slug_whose_parts_contain_hyphens_still_resolves(): void
    {
        $this->makeProducts(3);

        $hub = app(CurriculumHubs::class)->find('subject-grade', 'اللغة-الإنجليزية-رابع-ابتدائي');

        $this->assertNotNull($hub, 'فشلت المطابقة على معرّف جزءاه يحملان شرطات');
        $this->assertSame($this->subject->id, Subject::query()->where('slug', $hub['subjectSlug'])->value('id'));
    }

    public function test_an_unknown_hub_type_is_not_served(): void
    {
        $this->makeProducts(3);

        $this->getJson('/api/v1/curriculum/subject-semester/whatever')->assertNotFound();
    }

    /* ───── الخريطة ───── */

    /** الخريطة تعلن نفس العنوان الذي يخدمه المتجر — وإلّا أعلنت 404. */
    public function test_the_sitemap_advertises_exactly_the_served_path(): void
    {
        $this->makeProducts(3);

        $listed = collect($this->getJson('/api/v1/sitemap/curriculum')->assertOk()->json('urls'))
            ->pluck('loc')->all();

        $this->assertSame(['/curriculum/subject-grade/اللغة-الإنجليزية-رابع-ابتدائي'], $listed);
    }
}
