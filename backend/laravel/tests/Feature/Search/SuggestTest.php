<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Grade;
use App\Models\SearchQuery;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الإكمال التلقائيّ يفهم ما يفهمه البحث.
 *
 * قيس بـ`search:eval --suggest`: ٩٪ فقط من الأسئلة المكتوبة حتّى منتصف كلمتها
 * الأخيرة أظهرت منتجها، و٩١٪ لم تُظهر شيئًا — لأنّ الاقتراح كان مطابقةَ
 * سلسلةٍ على العنوان: «رياضيات سادس» لا تطابق «تحضير الرياضيات — الصف
 * السادس»، و«math» و«ريضايات» لا تطابق شيئًا.
 */
class SuggestTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
        $this->seedCorpus();
        $this->makeProduct('SG-M6', 'تحضير الرياضيات — الصف السادس الابتدائي', 1500, [
            'resource_type' => 'تحضير', 'format' => 'DOCX',
            'subject_id' => Subject::query()->where('slug', 'الرياضيات')->value('id'),
            'grade_id' => Grade::query()->where('slug', 'سادس-ابتدائي')->value('id'),
            'stage_id' => Stage::query()->where('slug', 'ابتدائي')->value('id'),
        ]);
    }

    /** @return list<string> */
    private function suggest(string $q): array
    {
        return array_column($this->getJson('/api/v1/search/suggest?q='.rawurlencode($q))->assertOk()->json('items'), 'title');
    }

    /** @return array<string, array{0: string}> */
    public static function understoodPrefixes(): array
    {
        return [
            'subject + half a grade word' => ['رياضيات سا'],
            'full subject and grade' => ['رياضيات سادس ابتدائي'],
            'english' => ['math grade 6'],
            'typo' => ['ريضايات سادس'],
            'colloquial kind' => ['تحاضير رياضيات سادس'],
        ];
    }

    #[DataProvider('understoodPrefixes')]
    public function test_a_prefix_suggests_the_product_search_would_find(string $q): void
    {
        $this->assertSame('تحضير الرياضيات — الصف السادس الابتدائي', $this->suggest($q)[0] ?? null, $q);
    }

    public function test_a_title_prefix_still_completes_when_understanding_finds_nothing(): void
    {
        $this->assertContains('ملزمة مراجعة نهائية', $this->suggest('ملزمة مرا'));
    }

    public function test_suggesting_never_writes_to_the_search_log(): void
    {
        $before = SearchQuery::query()->count();
        $this->suggest('رياضيات سا');
        $this->suggest('math grade 6');

        $this->assertSame($before, SearchQuery::query()->count());
    }

    public function test_nonsense_suggests_nothing(): void
    {
        $this->assertSame([], $this->suggest('تذاكر طيران الرياض'));
    }
}
