<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Admin\AdminRole;
use App\Domain\Search\Explain\ScoreProbe;
use App\Domain\Search\Providers\PgFallbackProvider;
use App\Domain\Search\QueryPipeline;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * مصحّح الصلة (§٣٧ و§١٥٠).
 *
 * الحارس الأهمّ هنا ليس «هل يردّ ٢٠٠» بل **هل ما يقوله صحيح**: الدرجة التي
 * يعرضها تُقارَن بالدرجة التي تحسبها `PgFallbackProvider::score()` نفسها،
 * والطبقات المعروضة تُقارَن بثوابت الصنف. اختبارٌ يكتفي بشكل الاستجابة كان
 * سيمرّ على مفسِّرٍ يخترع أرقامًا.
 */
class SearchExplainTest extends TestCase
{
    /*
     * السِّمتان تحملان `makeProduct` ببصمتين مختلفتين. الفصل صريح: مجموعةُ
     * البحث هي معيار هذا الاختبار (عناوينٌ بمفردات المنهج الحقيقيّة)، وسِمة
     * الإدارة تُؤخذ لتسجيل الدخول وحده.
     */
    use AdminFixtures, RefreshDatabase, SeedsSearchFixtures {
        SeedsSearchFixtures::makeProduct insteadof AdminFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
        $this->seedCorpus();
    }

    /** الدرجة المعروضة هي درجة الإنتاج حرفًا — لا تقديرًا ولا نسخةً ثانية. */
    public function test_reported_score_equals_the_live_provider_score(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $response = $this->getJson('/api/v1/admin/search/explain?q='.rawurlencode('ملزمة مراجعة'))
            ->assertOk()
            ->assertJsonPath('data.provider.explainable', true);

        $results = $response->json('data.results');
        $this->assertNotEmpty($results, 'الاستعلام يجب أن يعيد نتائج ليكون للاختبار معنى.');

        /*
         * إعادة الحساب من الدالّة الخاصّة نفسها عبر الانعكاس: لو كان المفسِّر
         * يحمل نسخةً ثانية من المنطق لانكسر هذا السطر عند أوّل تباعد.
         */
        $probe = app(ScoreProbe::class);
        $pipeline = app(QueryPipeline::class)->run('ملزمة مراجعة');

        foreach ($results as $row) {
            $product = Product::query()->findOrFail($row['id']);
            $this->assertSame(
                $probe->score($product, $pipeline['request']),
                $row['score'],
                "درجة «{$row['sku']}» المعروضة تخالف درجة المزوِّد.",
            );
        }
    }

    /** الأوزان تُقرأ من الصنف لا تُنسَخ: أيّ تعديل في الثوابت يظهر هنا فورًا. */
    public function test_tier_table_mirrors_the_provider_constants(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $tiers = $this->getJson('/api/v1/admin/search/explain?q='.rawurlencode('ملزمة'))
            ->assertOk()
            ->json('data.tiers');

        $expected = [];
        foreach ((new ReflectionClass(PgFallbackProvider::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'W_')) {
                $expected[$name] = $value;
            }
        }

        $this->assertSame($expected, array_column($tiers, 'value', 'name'));
        // وترتيبها تنازليّ — «الأعلى أوّلًا» هو ما تعنيه كلمة طبقة.
        $this->assertSame(array_values($expected), array_column($tiers, 'value'));
        arsort($expected);
        $this->assertSame(array_values($expected), array_column($tiers, 'value'));
    }

    /**
     * الإسناد سببيّ: الحقل «الحاسم» يجب أن يُسقط الدرجة تحت أرضيّة طبقتها
     * عند إزالته — وإلّا فالمفسِّر يروي قصّةً لا يسندها قياس.
     */
    public function test_decisive_field_ablation_drops_below_its_tier_floor(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $results = $this->getJson('/api/v1/admin/search/explain?q='.rawurlencode('ملزمة مراجعة'))
            ->assertOk()
            ->json('data.results');

        foreach ($results as $row) {
            $this->assertFalse($row['unattributed'], "«{$row['sku']}» بلا حقلٍ حاسم — الوحدات لم تعد تصف ما تقرؤه score().");
            $this->assertNotNull($row['decisiveUnit']);

            $decisive = null;
            foreach ($row['fieldEffects'] as $effect) {
                if ($effect['unit'] === $row['decisiveUnit']) {
                    $decisive = $effect;
                }
            }

            $this->assertNotNull($decisive);
            $this->assertLessThan(
                $row['tier']['value'],
                $decisive['scoreWithout'],
                "إزالة «{$row['decisiveUnit']}» من «{$row['sku']}» لم تُسقط الدرجة تحت أرضيّة طبقتها.",
            );
        }
    }

    /** رمزٌ مطابق ⇒ أعلى طبقة، وحقلُها هو `sku` — أوضح حالةٍ يعرفها المزوِّد. */
    public function test_exact_sku_is_attributed_to_the_sku_field_at_the_top_tier(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $this->getJson('/api/v1/admin/search/explain?q=TD-3002')
            ->assertOk()
            ->assertJsonPath('data.results.0.sku', 'TD-3002')
            ->assertJsonPath('data.results.0.tier.name', 'W_SKU_EXACT')
            ->assertJsonPath('data.results.0.decisiveUnit', 'sku')
            ->assertJsonPath('data.results.0.tiersNotReached', []);
    }

    /**
     * الفحص لا يصنع طلبًا: خطّ الأنابيب يكتب صفًّا في `search_queries` عند كلّ
     * تشغيل، ومشغّلٌ يجرّب استعلامًا عشر مرّات كان سيزوّر «الأكثر بحثًا».
     */
    public function test_explaining_never_writes_a_visitor_search_row(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $before = \DB::table('search_queries')->count();

        $this->getJson('/api/v1/admin/search/explain?q='.rawurlencode('ملزمة'))->assertOk();
        $this->getJson('/api/v1/admin/search/explain?q='.rawurlencode('ملزمة'))->assertOk();

        $this->assertSame($before, \DB::table('search_queries')->count());
    }

    /** الأبعاد الغائبة تُعلَن لا تُحذف — مصحّحٌ يسكت عن بُعدٍ يُعلّم نموذجًا خاطئًا. */
    public function test_absent_signals_are_declared_not_omitted(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $factors = $this->getJson('/api/v1/admin/search/explain?q='.rawurlencode('ملزمة'))
            ->assertOk()
            ->json('data.notFactors');

        $keys = array_column($factors, 'status', 'key');

        $this->assertSame('absent', $keys['semantic'] ?? null);
        $this->assertSame('declared_unfed', $keys['conversion_score'] ?? null);
        $this->assertSame('recorded_unfed', $keys['clicks'] ?? null);
    }

    /** الحراسة بالصلاحية كجارتها `GET /search/index`: مدير المنتجات لا يملك `analytics.read`. */
    public function test_requires_the_analytics_read_permission(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));

        $this->getJson('/api/v1/admin/search/explain?q='.rawurlencode('ملزمة'))
            ->assertForbidden();
    }

    /** استعلامٌ فارغٌ يُرفض بدل أن يمرّ إلى مسارٍ لا يُقيَّم فيه شيء. */
    public function test_empty_query_is_rejected(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));

        $this->getJson('/api/v1/admin/search/explain?q=')
            ->assertStatus(422);
    }
}
