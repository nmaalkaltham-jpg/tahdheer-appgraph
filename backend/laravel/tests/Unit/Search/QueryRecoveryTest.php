<?php

namespace Tests\Unit\Search;

use App\Domain\Search\Providers\MeilisearchProvider;
use App\Domain\Search\Recovery\QueryRecovery;
use App\Domain\Search\Recovery\RecoveryBase;
use App\Domain\Search\Recovery\RecoveryOutcome;
use App\Domain\Search\SearchRequest;
use App\Domain\Search\SearchResult;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * سلّمُ التعافي وحده — بلا قاعدةِ بياناتٍ ولا فهرس.
 *
 * المحرّك منطقُ قرارٍ خالص: يُعطى «أيعرف الكتالوج هذه الكلمة؟» و«ابحث بهذا»
 * دالّتين، فتُمسك الاختباراتُ بالقرار نفسه لا بأثره. ولذلك تثبّت هنا
 * الحدودُ والحارسان والترتيب — وهي ما ينكسر صامتًا لو تغيّر وزنٌ أو سقف.
 */
class QueryRecoveryTest extends TestCase
{
    /** @param array<string, string|null> $filters */
    private function base(string $residual = '', array $filters = []): RecoveryBase
    {
        return new RecoveryBase($residual, array_merge(
            ['stage' => null, 'grade' => null, 'subject' => null, 'semester' => null, 'kind' => null, 'format' => null],
            $filters,
        ));
    }

    private function hit(int $total): SearchResult
    {
        return new SearchResult(new Collection(array_fill(0, $total, (object) ['id' => 1])), $total, $total);
    }

    private function miss(): SearchResult
    {
        return new SearchResult(new Collection, 0, 0);
    }

    /** الأرضيّة مشتقّة: وزنُ المادّة ÷ مجموع أوزان الأبعاد. لو انفصلا صمتًا سقط الحارس. */
    public function test_the_coverage_floor_equals_subject_weight_over_all_dimension_weight(): void
    {
        $this->assertSame(0.40, QueryRecovery::MIN_COVERAGE);
        // 100 / (100+60+40+25+15+10) = 0.40
        $this->assertSame(0.40, round(100 / (100 + 60 + 40 + 25 + 15 + 10), 2));
    }

    /** المادّةُ وحدها تقف على الأرضيّة تمامًا فتمرّ — وهي حالةُ العطب الأصليّ. */
    public function test_over_specified_query_relaxes_down_to_the_subject(): void
    {
        $calls = [];
        $outcome = (new QueryRecovery)->recover(
            base: $this->base('', ['subject' => 'العلوم', 'grade' => 'أول-متوسط', 'stage' => 'متوسط', 'kind' => 'عرض بوربوينت', 'format' => 'PPTX']),
            typoBase: null,
            knows: static fn (string $t): bool => true,
            // لا شيء يعود إلّا حين تبقى المادّة وحدها.
            search: function (string $q, array $f) use (&$calls): SearchResult {
                $calls[] = array_keys(array_filter($f, static fn ($v) => $v !== null));

                return array_filter($f, static fn ($v) => $v !== null) === ['subject' => 'العلوم']
                    ? $this->hit(2)
                    : $this->miss();
            },
        );

        $this->assertSame(RecoveryOutcome::OVER_SPECIFIED, $outcome->mode);
        $this->assertSame(['kind', 'format', 'grade', 'stage'], $outcome->removed);
        $this->assertSame(['subject'], $outcome->retained);
        $this->assertEqualsWithDelta(0.4444, $outcome->coverage, 0.001);
        $this->assertGreaterThan(0.0, $outcome->confidence);
    }

    /** ترتيبُ الإسقاط ثابت: النوعُ ثمّ الصيغة ثمّ الفصل ثمّ الصفّ ثمّ المرحلة. */
    public function test_drop_order_is_fixed_and_grade_precedes_stage(): void
    {
        $seen = [];
        (new QueryRecovery)->recover(
            base: $this->base('', ['subject' => 'العلوم', 'grade' => 'أول-متوسط', 'stage' => 'متوسط', 'semester' => 'الفصل-الأول', 'kind' => 'ك', 'format' => 'PPTX']),
            typoBase: null,
            knows: static fn (string $t): bool => true,
            search: function (string $q, array $f) use (&$seen): SearchResult {
                // المقارنة على **المجموعة** لا على ترتيب مفاتيح المصفوفة:
                // ترتيبُ المفاتيح يتبع بناءَ المُستخرِج، وليس عقدًا يُثبَّت.
                $keys = array_keys(array_filter($f, static fn ($v) => $v !== null));
                sort($keys);
                $seen[] = $keys;

                return $this->miss();
            },
        );

        // كلُّ درجةٍ تُسقط واحدًا زيادةً على سابقتها، بالترتيب المعلن.
        $this->assertSame(['format', 'grade', 'semester', 'stage', 'subject'], $seen[0], 'الدرجة الأولى تُسقط النوع');
        $this->assertSame(['grade', 'semester', 'stage', 'subject'], $seen[1], 'ثمّ الصيغة');
        $this->assertSame(['grade', 'stage', 'subject'], $seen[2], 'ثمّ الفصل');
        $this->assertSame(['stage', 'subject'], $seen[3], 'ثمّ الصفّ — والمرحلة باقية');
        $this->assertSame(['subject'], $seen[4], 'ثمّ المرحلة — والمادّة لا تُسقَط أبدًا');
        $this->assertCount(5, $seen, 'السلّم تجاوز عمقه المعلن');
    }

    /** إسقاطُ الموضوع ثمّ البناءُ على كلمةٍ عامّة = رفض (§٦). */
    public function test_a_result_built_only_on_generic_tokens_is_rejected(): void
    {
        $outcome = (new QueryRecovery)->recover(
            // «الجهاز الهضمي» موضوعٌ مجهول؛ والباقي صيغةٌ وصفٌّ ومرحلة.
            base: $this->base('الجهاز الهضمي', ['grade' => 'أول-متوسط', 'stage' => 'متوسط', 'format' => 'PPTX']),
            typoBase: null,
            knows: static fn (string $t): bool => false,
            search: fn (string $q, array $f): SearchResult => $this->hit(40),
        );

        $this->assertSame(RecoveryOutcome::NONE, $outcome->mode);
        $this->assertStringContainsString('الموضوع', $outcome->reason);
    }

    /** سؤالٌ لا موضوعَ فيه أصلًا يُجاب هيكليًّا — الحارس يمنع الإسقاط لا الغياب. */
    public function test_a_structural_query_without_any_topic_is_still_answerable(): void
    {
        $outcome = (new QueryRecovery)->recover(
            // «ابغي» كلمةُ مخاطبة؛ والنوعُ والصفُّ يحملان السؤال كلَّه.
            base: $this->base('ابغي', ['kind' => 'أوراق عمل', 'grade' => 'ثالث-ابتدائي', 'stage' => 'ابتدائي']),
            typoBase: null,
            knows: static fn (string $t): bool => false,
            search: fn (string $q, array $f): SearchResult => $q === '' ? $this->hit(1) : $this->miss(),
        );

        $this->assertSame(RecoveryOutcome::UNKNOWN_TOKEN, $outcome->mode);
        $this->assertSame(['token:ابغي'], $outcome->removed);
    }

    /** لا نداءَ للمحرّك يتجاوز السقف مهما تضخّم السؤال. */
    public function test_backend_searches_never_exceed_the_cap(): void
    {
        $calls = 0;
        $outcome = (new QueryRecovery)->recover(
            base: $this->base('الف باء تاء ثاء جيم حاء', ['subject' => 'العلوم', 'grade' => 'ج', 'stage' => 'م', 'semester' => 'ف', 'kind' => 'ك', 'format' => 'PPTX']),
            typoBase: $this->base('الف باء', ['subject' => 'العلوم']),
            knows: static fn (string $t): bool => false,
            search: function () use (&$calls): SearchResult {
                $calls++;

                return $this->miss();
            },
        );

        $this->assertLessThanOrEqual(QueryRecovery::MAX_BACKEND_SEARCHES, $calls);
        $this->assertLessThanOrEqual(QueryRecovery::MAX_CANDIDATE_QUERIES, $calls);
        $this->assertSame(RecoveryOutcome::NONE, $outcome->mode);
    }

    /** التصحيحُ يُجرَّب أوّلًا وتغطيتُه كاملة — لم يُسقط قيدًا. */
    public function test_typo_candidate_runs_first_with_full_coverage(): void
    {
        $outcome = (new QueryRecovery)->recover(
            base: $this->base('الريضيات'),
            typoBase: $this->base('', ['subject' => 'الرياضيات']),
            knows: static fn (string $t): bool => false,
            search: fn (string $q, array $f): SearchResult => ($f['subject'] ?? null) !== null ? $this->hit(3) : $this->miss(),
        );

        $this->assertSame(RecoveryOutcome::TYPO, $outcome->mode);
        $this->assertSame(1.0, $outcome->coverage);
        $this->assertSame([], $outcome->removed);
        $this->assertSame(['typo'], $outcome->legacyRelaxed);
    }

    /** إملاءٌ ثمّ إفراط = MIXED، وما بعد التصحيح يُبنى على المُصحَّح. */
    public function test_typo_then_over_specification_reports_mixed(): void
    {
        $outcome = (new QueryRecovery)->recover(
            base: $this->base('الريضيات اول ثانوي'),
            typoBase: $this->base('', ['subject' => 'الرياضيات', 'grade' => 'أول-ثانوي', 'stage' => 'ثانوي']),
            knows: static fn (string $t): bool => true,
            search: fn (string $q, array $f): SearchResult => array_filter($f, static fn ($v) => $v !== null) === ['subject' => 'الرياضيات']
                ? $this->hit(3)
                : $this->miss(),
        );

        $this->assertSame(RecoveryOutcome::MIXED, $outcome->mode);
        $this->assertSame(['grade', 'stage'], $outcome->removed);
        $this->assertContains('typo', $outcome->legacyRelaxed);
    }

    /** لا مرشَّحَ يعود بنتيجة ⇒ صفرٌ صادق، لا اختلاق. */
    public function test_nothing_is_manufactured_when_no_candidate_hits(): void
    {
        $outcome = (new QueryRecovery)->recover(
            base: $this->base('زقنبوطية', ['subject' => 'العلوم']),
            typoBase: null,
            knows: static fn (string $t): bool => false,
            search: fn (): SearchResult => $this->miss(),
        );

        $this->assertSame(RecoveryOutcome::NONE, $outcome->mode);
        $this->assertNull($outcome->result);
        $this->assertFalse($outcome->recovered());
    }

    /** السلّم حتميّ: نفس المدخل ⇒ نفس النداءات ونفس الحصيلة، مرّاتٍ ثلاثًا. */
    public function test_the_ladder_is_deterministic_across_repeated_runs(): void
    {
        $runs = [];
        for ($i = 0; $i < 3; $i++) {
            $seen = [];
            $outcome = (new QueryRecovery)->recover(
                base: $this->base('ابغي الضوء', ['subject' => 'العلوم', 'grade' => 'أول-متوسط', 'stage' => 'متوسط', 'format' => 'PPTX']),
                typoBase: null,
                knows: static fn (string $t): bool => $t === 'الضوء',
                search: function (string $q, array $f) use (&$seen): SearchResult {
                    $seen[] = $q.'|'.implode(',', array_keys(array_filter($f, static fn ($v) => $v !== null)));

                    return count($seen) >= 3 ? $this->hit(1) : $this->miss();
                },
            );
            $runs[] = json_encode([$seen, $outcome->toArray()], JSON_UNESCAPED_UNICODE);
        }

        $this->assertCount(1, array_unique($runs), 'السلّم أعطى مسارين مختلفين لنفس المدخل');
    }

    /**
     * البُعدُ المُسقَط يختفي عند **المزوّدَين** لا عند واحد.
     *
     * التعافي يقع فوق المزوّد: يبني طلبًا ويُعيد النداء. فلو قرأ مزوّدٌ
     * `null` على أنّه قيمة، لَوسّع مسارٌ ولم يوسّع الآخر — واختلافٌ كهذا لا
     * يظهر ما دام `SEARCH_PROVIDER=pg`، ثمّ ينفجر يوم يُشغَّل المحرّك.
     * فيُثبَّت هنا أنّ `filterExpression` لا يُصدر شرطًا لبُعدٍ مُفرَّغ.
     */
    public function test_a_dropped_dimension_produces_no_meilisearch_filter_clause(): void
    {
        $full = new SearchRequest(
            query: '', rawQuery: '',
            filters: ['stage' => 'متوسط', 'grade' => 'أول-متوسط', 'subject' => 'العلوم', 'semester' => null, 'kind' => null, 'format' => 'PPTX'],
        );
        // نفس الطلب بعد إسقاط الصفّ — كما يبنيه سلّمُ التعافي تمامًا.
        $relaxed = new SearchRequest(
            query: '', rawQuery: '',
            filters: ['stage' => 'متوسط', 'grade' => null, 'subject' => 'العلوم', 'semester' => null, 'kind' => null, 'format' => 'PPTX'],
        );

        $method = new \ReflectionMethod(MeilisearchProvider::class, 'filterExpression');
        $provider = (new \ReflectionClass(MeilisearchProvider::class))->newInstanceWithoutConstructor();

        $before = $method->invoke($provider, $full, false);
        $after = $method->invoke($provider, $relaxed, false);

        $this->assertTrue(
            (bool) array_filter($before, static fn (string $c): bool => str_starts_with($c, 'grade ')),
            'المزوّد لا يُصدر شرط الصفّ أصلًا — الاختبار يقيس لا شيء',
        );
        $this->assertSame(
            [],
            array_values(array_filter($after, static fn (string $c): bool => str_starts_with($c, 'grade '))),
            'الصفُّ المُسقَط ما زال يُقيّد المحرّك',
        );
        // وما لم يُسقَط يبقى كما هو — التخفيف لا يمسّ جاره.
        $this->assertSame(
            array_values(array_filter($before, static fn (string $c): bool => ! str_starts_with($c, 'grade '))),
            $after,
        );
    }
}
