<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Admin\AdminRole;
use App\Domain\Search\ArabicNormalizer;
use App\Domain\Search\QueryPipeline;
use App\Models\Product;
use App\Models\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * المجموعة الذهبية — بوابة انحدار الصلة.
 *
 * كل صفّ استعلام عربي حقيقي من المجال بصيغه المكسورة: همزات مختلفة، تاء
 * مربوطة/هاء، أرقام هندية، عامية («ابي»، «ترم»)، ومزج عربي-إنجليزي. الصفّ
 * يثبّت ثلاثة أحكام:
 *
 *   · must_rank: المنتج المحدَّد يظهر في أوّل النتائج.
 *   · التطبيع: صيغتان مختلفتان لنفس المقصود تُطبَّعان إلى نفس السلسلة.
 *   · الاستخراج: الكيانات المتوقَّعة تُقرأ من النصّ لا تُخمَّن.
 *
 * أي تعديل في ArabicNormalizer أو EntityExtractor أو المرادفات أو الترتيب
 * يمرّ من هنا قبل النشر — سقوط صفّ واحد يوقف الدمج، فلا «تحسين» يكسر
 * استعلامًا كان يعمل.
 */
class GoldenDatasetTest extends TestCase
{
    // كلا السمتين تعرّف makeProduct؛ نسخة مثبّتات البحث هي المطلوبة هنا.
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

    /* ───── must_rank: الاستعلام يصل منتجه ───── */

    /**
     * @return array<string, array{query: string, mustRankSku: string, topN: int}>
     */
    public static function mustRankQueries(): array
    {
        return [
            // الصيغة الكاملة الفصيحة.
            'الاستعلام الكامل' => ['query' => 'عرض بوربوينت درس الضوء علوم سادس ابتدائي الفصل الاول', 'mustRankSku' => 'TD-2001', 'topN' => 1],
            // عامية: «ابي» + «ترم».
            'العامية' => ['query' => 'ابي بوربوينت الضوء سادس ترم اول', 'mustRankSku' => 'TD-2001', 'topN' => 3],
            // أرقام هندية للصفّ.
            'الأرقام الهندية' => ['query' => 'الضوء الصف ٦', 'mustRankSku' => 'TD-2001', 'topN' => 3],
            // مزج عربي-إنجليزي للصيغة.
            'المزج' => ['query' => 'powerpoint الضوء سادس', 'mustRankSku' => 'TD-2001', 'topN' => 3],
            // همزة زائدة على أداة التعريف.
            'الهمزة' => ['query' => 'تحاضير ألعلوم', 'mustRankSku' => 'TD-1002', 'topN' => 3],
            // SKU الحرفي.
            'SKU' => ['query' => 'TD-1001', 'mustRankSku' => 'TD-1001', 'topN' => 1],
            // مرادف مجال: تحضير/تحاضير.
            'المرادف' => ['query' => 'تحاضير المفعول به', 'mustRankSku' => 'TD-1001', 'topN' => 3],
        ];
    }

    #[DataProvider('mustRankQueries')]
    public function test_golden_query_ranks_its_product(string $query, string $mustRankSku, int $topN): void
    {
        $out = app(QueryPipeline::class)->run($query);

        $skus = $out['result']->items->pluck('sku')->take($topN)->all();

        $this->assertContains(
            $mustRankSku,
            $skus,
            "«{$query}» لم يُظهر {$mustRankSku} في أوّل {$topN}. الظاهر: ".implode('، ', $skus),
        );
    }

    /* ───── التطبيع: صيغتان ⇒ سلسلة واحدة ───── */

    /**
     * @return array<string, array{a: string, b: string}>
     */
    public static function normalizationPairs(): array
    {
        return [
            'الهمزات' => ['a' => 'أحياء', 'b' => 'احياء'],
            'المدّة' => ['a' => 'آداب', 'b' => 'اداب'],
            'الياء والألف المقصورة' => ['a' => 'مصطفى', 'b' => 'مصطفي'],
            'الأرقام الهندية' => ['a' => 'الصف ٣', 'b' => 'الصف 3'],
            'الأرقام الفارسية' => ['a' => 'ترم ۲', 'b' => 'ترم 2'],
            'التطويل' => ['a' => 'ريـــاضيات', 'b' => 'رياضيات'],
            'التشكيل' => ['a' => 'العُلُوم', 'b' => 'العلوم'],
            'الفواصل العربية' => ['a' => 'علوم، سادس', 'b' => 'علوم سادس'],
        ];
    }

    #[DataProvider('normalizationPairs')]
    public function test_variant_spellings_normalize_identically(string $a, string $b): void
    {
        $this->assertSame(
            ArabicNormalizer::normalize($b),
            ArabicNormalizer::normalize($a),
            "«{$a}» و«{$b}» يجب أن يتطابقا بعد التطبيع",
        );
    }

    /* ───── الاستخراج: الكيانات تُقرأ لا تُخمَّن ───── */

    public function test_the_fully_specified_query_extracts_every_entity(): void
    {
        $out = app(QueryPipeline::class)->run('بوربوينت علوم سادس ابتدائي الفصل الاول قابل للتعديل');

        $this->assertSame('PPTX', $out['filters']['format']);
        $this->assertNotNull($out['filters']['subject'], 'المادة لم تُستخرج');
        $this->assertNotNull($out['filters']['grade'], 'الصف لم يُستخرج');
        $this->assertNotNull($out['filters']['semester'], 'الفصل لم يُستخرج');
        $this->assertTrue($out['editable']);
    }

    /**
     * قاعدة الترتيب المجرّد: يُرقّى فقط حين يحلّ لصفٍّ واحد مزروع.
     *
     * «خامس» في هذا المنهج لا يحتمل إلا الخامس الابتدائي ⇒ حتميّ لا تخمين.
     * «ثالث» يحتمل ابتدائي ومتوسّطًا وثانويًّا ⇒ يبقى نصًّا حرًّا.
     */
    public function test_bare_ordinals_promote_only_when_unambiguous(): void
    {
        $unique = app(QueryPipeline::class)->run('خامس');
        $this->assertSame('خامس-ابتدائي', $unique['filters']['grade']);

        $ambiguous = app(QueryPipeline::class)->run('ثالث');
        $this->assertNull($ambiguous['filters']['grade'], 'رُقّي ترتيبٌ ملتبس بلا مرحلة تحسمه');
    }

    /**
     * التخفيف التدريجي: انحراف resource_type عن مصطلح التصنيف لا يصنع صفرًا
     * حين تحمل الصيغة القصد كاملًا — والنتائج المخفَّفة تُعلَن لا تتنكّر.
     */
    public function test_relaxation_rescues_a_kind_mismatch_and_declares_itself(): void
    {
        // منتج بصيغة صحيحة وresource_type منحرف عن مصطلح «عرض بوربوينت».
        $this->makeProduct('TD-DRIFT', 'عرض درس الكسور لمادة الرياضيات', 2000, [
            'resource_type' => 'عرض', 'format' => 'PPTX',
        ]);

        $body = $this->getJson('/api/v1/search?q='.rawurlencode('بوربوينت الكسور'))
            ->assertOk()->json();

        $this->assertContains('TD-DRIFT', array_column($body['results']['items'], 'sku'));
        $this->assertSame(['kind'], $body['meta']['relaxed'], 'التخفيف وقع ولم يُعلَن');

        // وبلا انحراف لا تخفيف: القائمة فارغة في الاستعلام السليم.
        $clean = $this->getJson('/api/v1/search?q='.rawurlencode('علوم'))->assertOk()->json();
        $this->assertSame([], $clean['meta']['relaxed']);
    }

    /* ───── العقد الجديد: التوزيعات والمعرّف ───── */

    public function test_the_search_response_carries_taxonomy_facets_with_real_counts(): void
    {
        $body = $this->getJson('/api/v1/search?q='.rawurlencode('علوم'))->assertOk()->json();

        $taxonomy = collect($body['facets']['taxonomy']);
        $subjects = $taxonomy->firstWhere('key', 'subject');

        $this->assertNotNull($subjects, 'بُعد المادة غائب عن التوزيعات');
        // العدد يساوي مجموع النتائج الحاملة للمادة — يُحسب من نفس المجموعة.
        foreach ($subjects['values'] as $row) {
            $this->assertGreaterThan(0, $row['count']);
        }
        $this->assertIsInt($body['meta']['queryId']);
    }

    /**
     * دورة التوزيع الكاملة في الوضع المتدهور: القيمة المعروضة تعمل مرشِّحًا.
     *
     * هذا هو العقد الذي انكسر أوّل مرّة: المحرّك يفهرس slugs والاحتياطي عدّ
     * الأسماء، فرابط «الصف الخامس الابتدائي» أعاد صفرًا بينما «خامس-ابتدائي»
     * يعمل. القيمة التي تخرج في التوزيع يجب أن تعود مقبولةً كمرشِّح.
     */
    public function test_facet_values_round_trip_as_filters_on_the_fallback_path(): void
    {
        $first = $this->getJson('/api/v1/search?q='.rawurlencode('الضوء'))->assertOk()->json();

        // بُعد الصف يخرج slugs — نفس مفردات المحرّك حرفيًّا.
        $grade = collect($first['facets']['taxonomy'])->firstWhere('key', 'grade');
        $this->assertNotNull($grade, 'بُعد الصف غائب');
        $this->assertSame('سادس-ابتدائي', $this->facetValue($grade, 0));

        // والدورة الكاملة تُثبَّت عبر stage — المعامل المعلَن في العقد العام.
        $stage = collect($first['facets']['taxonomy'])->firstWhere('key', 'stage');
        $stageValue = $this->facetValue($stage, 0);

        $roundTrip = $this->getJson('/api/v1/search?q='.rawurlencode('الضوء').'&stage='.rawurlencode($stageValue))
            ->assertOk()->json();

        $this->assertGreaterThan(0, $roundTrip['results']['total'],
            "قيمة التوزيع «{$stageValue}» لم تعمل مرشِّحًا — مفردات المسارين منحرفة");
    }

    public function test_a_click_is_recorded_once_and_junk_is_ignored(): void
    {
        $body = $this->getJson('/api/v1/search?q='.rawurlencode('علوم'))->assertOk()->json();
        $queryId = $body['meta']['queryId'];
        $token = $body['meta']['clickToken'];
        $productId = $body['results']['items'][0]['id'];

        $this->postJson('/api/v1/search/click', [
            'queryId' => $queryId, 'token' => $token, 'productId' => $productId, 'position' => 2,
        ])->assertNoContent();

        // النقرة الثانية لا تمحو الأولى: أوّل انخراط هو الإشارة.
        $this->postJson('/api/v1/search/click', [
            'queryId' => $queryId, 'token' => $token, 'productId' => $productId, 'position' => 9,
        ])->assertNoContent();

        $row = SearchQuery::query()->findOrFail($queryId);
        $this->assertSame($productId, $row->clicked_product_id);
        $this->assertSame(2, $row->position);

        // منتج مُلفَّق يُهمل بصمت — قياس لا معاملة.
        $this->postJson('/api/v1/search/click', [
            'queryId' => $queryId, 'token' => $token, 'productId' => 999999, 'position' => 1,
        ])->assertNoContent();
        $this->assertSame($productId, $row->refresh()->clicked_product_id);
    }

    /** رمز مزوَّر أو معرّف غريب: المعرّف التسلسلي وحده ليس إذنًا بالكتابة. */
    public function test_a_forged_click_token_cannot_poison_another_visitors_query(): void
    {
        $victim = $this->getJson('/api/v1/search?q='.rawurlencode('علوم'))->assertOk()->json();
        $victimId = $victim['meta']['queryId'];
        $productId = $victim['results']['items'][0]['id'];

        // مهاجم عدّد المعرّف وصنع رمزًا: 64 خانة صحيحة الشكل خاطئة التوقيع.
        $this->postJson('/api/v1/search/click', [
            'queryId' => $victimId,
            'token' => str_repeat('ab', 32),
            'productId' => $productId,
            'position' => 1,
        ])->assertNoContent();

        $this->assertNull(
            SearchQuery::query()->findOrFail($victimId)->clicked_product_id,
            'رمز مزوَّر كتب نقرةً في استعلام زائر آخر',
        );

        // ورمز استجابةٍ أخرى لا يفتح هذا المعرّف: التوقيع مربوط بالمعرّف نفسه.
        $other = $this->getJson('/api/v1/search?q='.rawurlencode('الضوء'))->assertOk()->json();
        $this->postJson('/api/v1/search/click', [
            'queryId' => $victimId,
            'token' => $other['meta']['clickToken'],
            'productId' => $productId,
            'position' => 1,
        ])->assertNoContent();

        $this->assertNull(SearchQuery::query()->findOrFail($victimId)->clicked_product_id);
    }

    /** نقرة من صفحة عميقة (موضع > 200) تُسجَّل — كانت تُسقَط صامتةً. */
    public function test_a_deep_page_click_is_recorded(): void
    {
        $body = $this->getJson('/api/v1/search?q='.rawurlencode('علوم'))->assertOk()->json();

        $this->postJson('/api/v1/search/click', [
            'queryId' => $body['meta']['queryId'],
            'token' => $body['meta']['clickToken'],
            'productId' => $body['results']['items'][0]['id'],
            'position' => 217,
        ])->assertNoContent();

        $this->assertSame(
            217,
            SearchQuery::query()->findOrFail($body['meta']['queryId'])->position,
        );
    }

    public function test_admin_metrics_are_null_without_data_not_zero(): void
    {
        SearchQuery::query()->delete();

        // بلا استعلامات: CTR غير معرَّف، لا «صفر» يُقرأ أداءً سيّئًا.
        $this->assertSame(
            ['zeroResultRate' => null, 'ctr' => null, 'meanClickPosition' => null, 'clickedQueries' => 0],
            $this->asSearchManager()->getJson('/api/v1/admin/search/queries')->assertOk()->json('metrics'),
        );
    }

    public function test_index_status_reports_the_real_serving_path(): void
    {
        $data = $this->asSearchManager()->getJson('/api/v1/admin/search/index')->assertOk()->json('data');

        // بيئة الاختبار بلا محرّك وبلا فهرس: الحقيقة هي الاحتياطي، وتُقال.
        $this->assertFalse($data['everBuilt']);
        $this->assertSame('never_built', $data['status']);
        $this->assertSame('postgres_fallback', $data['servingPath']);
        // العدد من القاعدة نفسها — لا رقم مكتوب ينكسر مع كل توسعة للمثبّتات.
        $this->assertSame(
            Product::query()->published()->count(),
            $data['publishedProducts'],
        );
    }

    private function asSearchManager(): static
    {
        return $this->actingAsAdmin($this->makeAdmin(AdminRole::SearchManager));
    }

    /**
     * قيمةُ وجهٍ بترتيبٍ مثبَّت.
     *
     * الفهرسة `[0]` على مصفوفةٍ لا يَعِد المحرّك بترتيبها تُنتج إخفاقًا
     * يظهر ويختفي بلا تغييرٍ في الشيفرة.
     */
    private function facetValue(array $facet, int $index): string
    {
        $values = array_map(static fn (array $v): string => (string) $v['value'], $facet['values']);
        sort($values);

        return $values[$index];
    }
}
