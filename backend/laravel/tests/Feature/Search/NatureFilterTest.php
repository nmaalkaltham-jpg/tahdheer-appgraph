<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Catalog\ProductGroupAuthoring;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البحث يعرف نوع المنتج — والمسار المتدهور يعرفه كما يعرفه المحرّك.
 *
 * ─── لماذا `nature` لا `type` ───
 *
 * `type` تعني امتداد الملفّ منذ أوّل يوم (عروض · PDF · Word)، ولها مستهلكون
 * أحياء وعناوين محفوظة. فتوسيعُها لتعني «ماديّ/رقميّ» كان يجعل رابطًا قديمًا
 * يعني شيئًا آخر بعد النشر — كسرٌ صامت لا يظهر في اختبار.
 *
 * ─── ولماذا يُختبَر المسار المتدهور خاصّةً ───
 *
 * هذه الاختبارات تجري بلا Meilisearch، فتصيب `PgFallbackProvider`. ومرشِّحٌ
 * يعمل في المحرّك ويُهمَل في المتدهور يُعيد النتائج كاملةً وكأنّه طُبِّق —
 * أي أنّ المتجر يكذب تحديدًا حين يكون أضعف.
 */
class NatureFilterTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
    }

    private function published(string $sku, string $title, string $nature): Product
    {
        $product = $this->makeProduct($sku, $title, 5000, [
            'format' => 'PDF',
            'fulfillment_type' => 'digital',
        ]);

        app(ProductGroupAuthoring::class)->create($product, $nature);

        return $product;
    }

    public function test_the_filter_narrows_to_one_nature(): void
    {
        $this->published('TD-N1', 'أطلس مطبوع', 'PHYSICAL');
        $this->published('TD-N2', 'أطلس رقميّ', 'DIGITAL');

        $all = $this->getJson('/api/v1/search?q='.rawurlencode('أطلس'))->assertOk();
        $this->assertSame(2, $all->json('results.total'));

        $physical = $this->getJson('/api/v1/search?q='.rawurlencode('أطلس').'&nature=physical')->assertOk();

        $this->assertSame(1, $physical->json('results.total'));
        $this->assertSame('TD-N1', $physical->json('results.items.0.sku'));
    }

    public function test_the_value_is_lowercase_like_every_other_search_dimension(): void
    {
        $this->published('TD-N3', 'معجم مطبوع', 'PHYSICAL');
        $this->published('TD-N4', 'معجم رقميّ', 'DIGITAL');

        /*
         * صيغةٌ واحدة مقبولة، كما في `type=pdf` و`stage=ابتدائي`. وقبولُ
         * الصيغتين كان يُنتج عنوانين لصفحةٍ واحدة — وهو ما تمنعه سياسة
         * العناوين القانونيّة. والقيمة تُرفع في الطرف مرّةً فلا يُعيد كلّ
         * مزوّدٍ حسمَ الحالة بنفسه.
         */
        $this->getJson('/api/v1/search?q='.rawurlencode('معجم').'&nature=physical')
            ->assertOk()
            ->assertJsonPath('results.total', 1);

        $this->getJson('/api/v1/search?q='.rawurlencode('معجم').'&nature=PHYSICAL')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nature']);
    }

    public function test_an_unknown_nature_is_refused_by_field_not_ignored(): void
    {
        /*
         * الإسقاط الصامت أسوأ من الرفض: يُعيد النتائج كاملةً وكأنّ المرشِّح
         * طُبِّق، فيظنّ الزائر أنّ كلّ ما يراه من ذلك النوع.
         */
        $this->getJson('/api/v1/search?q=x&nature=gaseous')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nature']);
    }

    public function test_the_facet_counts_each_nature_and_hides_itself_when_there_is_no_choice(): void
    {
        $this->published('TD-N5', 'كرّاسة مطبوعة', 'PHYSICAL');
        $this->published('TD-N6', 'كرّاسة رقميّة', 'DIGITAL');
        $this->published('TD-N7', 'كرّاسة رقميّة أخرى', 'DIGITAL');

        $facets = $this->getJson('/api/v1/search?q='.rawurlencode('كرّاسة'))
            ->assertOk()
            ->json('facets.natures');

        $byKey = collect($facets)->keyBy('key');
        $this->assertSame(2, $byKey['DIGITAL']['count']);
        $this->assertSame(1, $byKey['PHYSICAL']['count']);
        $this->assertSame('ماديّ', $byKey['PHYSICAL']['label'], 'التسمية من سجلّ القدرات لا من الواجهة');

        /* والترتيب ترتيبُ المفردة لا ترتيبُ الأعداد: مرشِّحٌ يقفز يُربك. */
        $this->assertSame(['DIGITAL', 'PHYSICAL'], collect($facets)->pluck('key')->all());
    }

    public function test_a_single_nature_offers_no_filter_at_all(): void
    {
        $this->published('TD-N8', 'ملفّ رقميّ كيمياء', 'DIGITAL');
        $this->published('TD-N9', 'ملفّ رقميّ آخر كيمياء', 'DIGITAL');

        $this->getJson('/api/v1/search?q='.rawurlencode('كيمياء'))
            ->assertOk()
            ->assertJsonPath('facets.natures', []);
    }

    public function test_a_legacy_product_without_a_group_is_still_filterable(): void
    {
        /* منتجٌ سبق النموذج الكونيّ: طبيعتُه تُقرأ من عموده القديم. */
        $this->makeProduct('TD-N10', 'ملزمة قديمة تاريخ', 5000, [
            'format' => 'PDF', 'fulfillment_type' => 'digital',
        ]);
        $this->published('TD-N11', 'كتاب مطبوع تاريخ', 'PHYSICAL');

        $this->getJson('/api/v1/search?q='.rawurlencode('تاريخ').'&nature=digital')
            ->assertOk()
            ->assertJsonPath('results.total', 1)
            ->assertJsonPath('results.items.0.sku', 'TD-N10');
    }

    public function test_the_file_format_tab_still_means_the_file_format(): void
    {
        $this->published('TD-N12', 'كتاب مطبوع جغرافيا', 'PHYSICAL');

        /* البُعد القديم لم يتبدّل معناه — وذاك شرط «بلا كسرٍ للخلف». */
        $this->getJson('/api/v1/search?q='.rawurlencode('جغرافيا').'&type=pdf')
            ->assertOk()
            ->assertJsonPath('results.total', 1);

        $this->getJson('/api/v1/search?q='.rawurlencode('جغرافيا').'&type=docx')
            ->assertOk()
            ->assertJsonPath('results.total', 0);
    }
}
