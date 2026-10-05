<?php

namespace Tests\Feature\Search;

use App\Domain\Search\ArabicNormalizer;
use App\Domain\Search\EntityExtractor;
use Database\Seeders\CurriculumSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EntityExtractorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurriculumSeeder::class);
    }

    /** @return array{filters: array<string, ?string>, ids: array<string, ?int>, editable: ?bool, residual: string} */
    private function extract(string $query): array
    {
        return (new EntityExtractor)->extract(ArabicNormalizer::normalize($query));
    }

    public function test_golden_fully_specified_query_extracts_every_dimension(): void
    {
        $out = $this->extract('عرض بوربوينت علوم سادس الفصل الأول قابل للتعديل');

        $this->assertSame('عرض بوربوينت', $out['filters']['kind']);
        $this->assertSame('PPTX', $out['filters']['format']);
        $this->assertSame('العلوم', $out['filters']['subject']);
        $this->assertSame('سادس-ابتدائي', $out['filters']['grade']);
        $this->assertSame('الفصل-الأول', $out['filters']['semester']);
        $this->assertSame('ابتدائي', $out['filters']['stage']);
        $this->assertTrue($out['editable']);
        $this->assertSame('', $out['residual']);
    }

    public function test_mixed_script_query_extracts_grade_and_keeps_the_lexical_rest(): void
    {
        // «math» is a subject now (SearchLexicon), so only the unknown noun stays lexical.
        $out = $this->extract('ملزمة math اول ثانوي');

        $this->assertSame('أول-ثانوي', $out['filters']['grade']);
        $this->assertSame('ثانوي', $out['filters']['stage']);
        $this->assertSame('الرياضيات', $out['filters']['subject']);
        $this->assertNull($out['editable']);
        $this->assertSame('ملزمه', $out['residual']);
    }

    public function test_bare_ordinal_is_used_only_when_unambiguous(): void
    {
        // «سادس» exists in one stage only → resolves.
        $unique = $this->extract('علوم سادس');
        $this->assertSame('سادس-ابتدائي', $unique['filters']['grade']);
        $this->assertSame('ابتدائي', $unique['filters']['stage']);

        // «اول» exists in three stages → never guessed.
        $ambiguous = $this->extract('اختبار اول');
        $this->assertSame('اختبارات', $ambiguous['filters']['kind']);
        $this->assertNull($ambiguous['filters']['grade']);
        $this->assertSame('اول', $ambiguous['residual']);
    }

    public function test_format_word_survives_being_part_of_a_kind_alias(): void
    {
        $out = $this->extract('بوربوينت');

        $this->assertSame('عرض بوربوينت', $out['filters']['kind']);
        $this->assertSame('PPTX', $out['filters']['format']);
        $this->assertSame('', $out['residual']);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function lexiconQueries(): array
    {
        return [
            'english subject + grade' => ['math grade 6', ['subject' => 'الرياضيات', 'grade' => 'سادس-ابتدائي', 'stage' => 'ابتدائي']],
            'english kind' => ['science lesson plan', ['subject' => 'العلوم', 'kind' => 'تحضير']],
            'english term' => ['physics term 2', ['subject' => 'الفيزياء', 'semester' => 'الفصل-الثاني']],
            'two-word english subject' => ['islamic studies', ['subject' => 'الدراسات الإسلامية']],
            'article-less alias' => ['تاريخ ثالث ثانوي', ['subject' => 'الدراسات الاجتماعية', 'grade' => 'ثالث-ثانوي']],
            'colloquial english' => ['انقلش ثاني متوسط', ['subject' => 'اللغة الإنجليزية', 'grade' => 'ثاني-متوسط']],
            'feminine ordinal' => ['علوم اولى متوسط', ['subject' => 'العلوم', 'grade' => 'أول-متوسط']],
            'abbreviated stage' => ['رياضيات ثاني ث', ['subject' => 'الرياضيات', 'grade' => 'ثاني-ثانوي']],
            'english stage' => ['chemistry secondary', ['subject' => 'الكيمياء', 'stage' => 'ثانوي']],
        ];
    }

    /**
     * @param  array<string, string>  $expected
     */
    #[DataProvider('lexiconQueries')]
    public function test_how_people_type_resolves_to_curriculum_entities(string $query, array $expected): void
    {
        $out = $this->extract($query);

        foreach ($expected as $dim => $slug) {
            $this->assertSame($slug, $out['filters'][$dim], "{$query} → {$dim}");
        }
        $this->assertSame('', $out['residual'], $query);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function typoQueries(): array
    {
        return [
            'swapped letters in subject' => ['ريضايات سادس ابتدائي', ['subject' => 'الرياضيات', 'grade' => 'سادس-ابتدائي']],
            'swapped letters, short word' => ['لتغي ثالث ابتدائي', ['subject' => 'لغتي', 'grade' => 'ثالث-ابتدائي']],
            'wrong letter in stage' => ['علوم اول متوسظ', ['subject' => 'العلوم', 'grade' => 'أول-متوسط']],
            'hamza typed for ya' => ['رياضيات خامس ابتداءي', ['subject' => 'الرياضيات', 'grade' => 'خامس-ابتدائي']],
            'wrong letter in ordinal' => ['علوم خامص ابتدائي', ['subject' => 'العلوم', 'grade' => 'خامس-ابتدائي']],
            'kind split in two' => ['بور بونت علوم', ['kind' => 'عرض بوربوينت', 'subject' => 'العلوم']],
            'duplicated letter' => ['علووم رابع ابتدائي', ['subject' => 'العلوم', 'grade' => 'رابع-ابتدائي']],
            // One edit from «علوم» and from «علم» (a fragment of «علم البيئة»): the whole alias wins the tie.
            'tie goes to a standalone alias' => ['علزم ثالث متوسط', ['subject' => 'العلوم', 'grade' => 'ثالث-متوسط']],
            // «علم» is only a fragment of «علم البيئة», which is absent; a grade gives curricular context.
            'orphan phrase fragment, grade four' => ['علم رابع ابتدائي', ['subject' => 'العلوم', 'grade' => 'رابع-ابتدائي']],
            'orphan phrase fragment, grade five' => ['علم خامس ابتدائي', ['subject' => 'العلوم', 'grade' => 'خامس-ابتدائي']],
        ];
    }

    /**
     * @param  array<string, string>  $expected
     */
    #[DataProvider('typoQueries')]
    public function test_typos_resolve_to_a_unique_entity(string $query, array $expected): void
    {
        $out = $this->extract($query);

        foreach ($expected as $dim => $slug) {
            $this->assertSame($slug, $out['filters'][$dim], "{$query} → {$dim}");
        }
        $this->assertSame('', $out['residual'], $query);
        $this->assertNotSame([], $out['corrected'], $query);
    }

    public function test_orphan_fragment_rule_leaves_whole_phrases_bare_words_and_constructs_alone(): void
    {
        // The phrase is present: «علم» belongs to it.
        $phrase = $this->extract('علم البيئة ثالث ثانوي');
        $this->assertSame([], $phrase['corrected']);
        $this->assertNotSame('العلوم', $phrase['filters']['subject']);

        // No curricular context: a bare word stays a text search.
        $this->assertSame([], $this->extract('علم')['corrected']);

        // An idafa onto an unknown word is a different subject, not a typo.
        $construct = $this->extract('علم النفس ثانوي');
        $this->assertSame([], $construct['corrected']);
        $this->assertNull($construct['filters']['subject']);
    }

    public function test_fuzzy_resolution_never_touches_content_words_or_short_tokens(): void
    {
        // A lesson topic is not a misspelled entity.
        $topic = $this->extract('الضوء علوم سادس');
        $this->assertSame('الضوء', $topic['residual']);
        $this->assertSame([], $topic['corrected']);

        // Nonsense stays nonsense — no entity is invented for it.
        $nonsense = $this->extract('قطط فضائية');
        $this->assertSame([], array_filter($nonsense['filters']));
        $this->assertSame('قطط فضاييه', $nonsense['residual']);

        // A common word one edit from an alias is left alone without curriculum context.
        $car = $this->extract('سيارات رياضية للبيع');
        $this->assertNull($car['filters']['subject']);
        $this->assertSame([], $car['corrected']);

        // An article-less alias heading an idafa phrase is the phrase, not the subject:
        // «توحيد المملكة» is Saudi history, not «التوحيد» (Islamic studies).
        $idafa = $this->extract('توحيد المملكة');
        $this->assertNull($idafa['filters']['subject']);
        $this->assertSame('توحيد المملكه', $idafa['residual']);
        // The idafa decision is per occurrence: the same word as a phrase head AND as a subject.
        $twice = $this->extract('تاريخ المملكة تاريخ ثالث ثانوي');
        $this->assertSame('الدراسات الاجتماعية', $twice['filters']['subject']);
        $this->assertSame('ثالث-ثانوي', $twice['filters']['grade']);
        $this->assertSame('تاريخ المملكه', $twice['residual']);
        // …while an explicit alias, or one followed by curriculum words, still resolves.
        $this->assertSame('الدراسات الاجتماعية', $this->extract('تاريخ ثالث ثانوي')['filters']['subject']);
        $this->assertSame('الدراسات الإسلامية', $this->extract('التوحيد المملكة')['filters']['subject']);

        // Three letters are too short to correct safely.
        $short = $this->extract('ساد ابتدائي');
        $this->assertNull($short['filters']['grade']);
        $this->assertSame('ساد', $short['residual']);
    }

    public function test_the_last_word_is_completed_only_when_the_completion_is_certain(): void
    {
        $complete = fn (string $q): string => (new EntityExtractor)->completeLast(ArabicNormalizer::normalize($q));

        $this->assertSame('رياضيات سادس', $complete('رياضيات سا'));
        // «الابت» begins «الابتدايي» and «الابتداييه»; the shorter heads both, so it is taken.
        $this->assertSame('رياضيات الاول الابتدايي', $complete('رياضيات الاول الابت'));
        // Two unrelated completions: no guess.
        $this->assertSame('رياضيات ال', $complete('رياضيات ال'));
        // A complete word after non-curriculum words is left alone («الرياض» is Riyadh).
        $this->assertSame('تذاكر طيران الرياض', $complete('تذاكر طيران الرياض'));
        // A known word is never "completed" into a longer one.
        $this->assertSame('علوم', $complete('علوم'));
    }
}
