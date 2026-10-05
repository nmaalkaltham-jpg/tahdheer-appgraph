<?php

namespace Tests\Feature\Search;

use App\Domain\Search\QueryPipeline;
use App\Domain\Search\Recovery\RecoveryOutcome;
use App\Models\Grade;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مصفوفةُ التعافي من طرفٍ إلى طرف — على كتالوجٍ مصنوعٍ لهذا الغرض.
 *
 * الحالةُ المحوريّة مبنيّةٌ عمدًا: مادّةٌ موجودة وصفٌّ موجود، واجتماعُهما
 * خالٍ. وهي الحالةُ التي لا يراها أيُّ مخفِّفٍ يقرأ الكلمات، لأنّ كلّ كلمةٍ
 * فيها معروفة — ولا يراها مخفِّفُ المرشِّحات القديم، لأنّ سببَ الفراغ ليس
 * النوعَ ولا الصيغة.
 */
class QueryRecoveryMatrixTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSearchFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
        $this->seedCorpus();

        // علومٌ ابتدائيّة موجودة… ولا علومَ متوسّطة. هنا يولد الإفراط.
        $this->makeProduct('TD-SCI-4', 'عرض بوربوينت العلوم رابع ابتدائي', 2200, [
            'resource_type' => 'عرض بوربوينت', 'format' => 'PPTX',
            'stage_id' => Stage::query()->where('slug', 'ابتدائي')->value('id'),
            'grade_id' => Grade::query()->where('slug', 'رابع-ابتدائي')->value('id'),
            'subject_id' => Subject::query()->where('slug', 'العلوم')->value('id'),
        ]);

        // ولغةٌ عربيّة في أوّل متوسط: الصفُّ موجود، لكنّه لمادّةٍ أخرى.
        $this->makeProduct('TD-ARA-1M', 'مذكرة اللغة العربية أول متوسط', 1900, [
            'format' => 'PDF',
            'stage_id' => Stage::query()->where('slug', 'متوسط')->value('id'),
            'grade_id' => Grade::query()->where('slug', 'أول-متوسط')->value('id'),
            'subject_id' => Subject::query()->where('slug', 'اللغة العربية')->value('id'),
        ]);
    }

    /** @return array<string, mixed> */
    private function ask(string $q): array
    {
        return app(QueryPipeline::class)->run($q);
    }

    /* ───── العطب المحوريّ ───── */

    public function test_the_all_known_incompatible_query_recovers_on_the_subject(): void
    {
        // كلُّ جزءٍ يعمل وحده…
        $this->assertGreaterThan(0, $this->ask('بوربوينت علوم')['result']->total);
        $this->assertGreaterThan(0, $this->ask('اول متوسط')['result']->total);

        // …والاجتماعُ خالٍ، فيتعافى بإسقاط الصفّ والمرحلة لا المادّة.
        $out = $this->ask('بوربوينت علوم اول متوسط');

        $this->assertGreaterThan(0, $out['result']->total, 'العطب المحوريّ ما زال يعود بصفر');
        $this->assertSame(RecoveryOutcome::OVER_SPECIFIED, $out['recoveryMeta']['mode']);
        $this->assertContains('grade', $out['recoveryMeta']['removed']);
        $this->assertContains('subject', $out['recoveryMeta']['retained']);

        // ولا يُسلَّم منتجُ أوّل متوسط لمادّةٍ أخرى بوصفه إجابة.
        $skus = $out['result']->items->pluck('sku')->all();
        $this->assertNotContains('TD-ARA-1M', $skus, 'سُلّم منتجٌ من صفٍّ صحيح ومادّةٍ خاطئة');
    }

    public function test_recovery_never_crosses_two_products_into_one_answer(): void
    {
        $out = $this->ask('بوربوينت علوم اول متوسط');

        // كلُّ نتيجةٍ تحمل المادّة المطلوبة — لا دمجَ بين «علوم» و«أول متوسط».
        $subject = Subject::query()->where('slug', 'العلوم')->value('id');
        foreach ($out['result']->items as $product) {
            $this->assertSame($subject, $product->subject_id, 'نتيجةٌ خارج المادّة المطلوبة');
        }
    }

    /* ───── المطابقةُ التامّة لا تُمسّ ───── */

    public function test_an_exact_hit_triggers_no_recovery_and_no_extra_search(): void
    {
        $out = $this->ask('ملزمة مراجعة نهائية');

        $this->assertSame(2, $out['result']->total);
        $this->assertSame(RecoveryOutcome::NONE, $out['recoveryMeta']['mode']);
        $this->assertSame(0, $out['recoveryMeta']['searches'], 'المطابقةُ التامّة دفعت ثمن نداءٍ إضافيّ');
        $this->assertSame([], $out['relaxed']);
    }

    public function test_exact_title_ranking_is_unchanged_by_recovery(): void
    {
        $out = $this->ask('ملزمة مراجعة نهائية');

        $this->assertSame(
            ['TD-3001', 'TD-3002'],
            $out['result']->items->pluck('sku')->all(),
            'ترتيبُ المطابقة التامّة تغيّر',
        );
    }

    /* ───── الحارسان ───── */

    public function test_a_generic_token_cannot_manufacture_results(): void
    {
        // موضوعٌ صريحٌ مجهول + صيغةٌ وصفّ: إسقاطُ الموضوع يُفرغ السؤال.
        $out = $this->ask('بوربوينت اول متوسط الجهاز الهضمي');

        $this->assertSame(0, $out['result']->total, 'صُنعت نتائج من «بوربوينت» وحدها');
        $this->assertSame(RecoveryOutcome::NONE, $out['recoveryMeta']['mode']);
        $this->assertStringContainsString('الموضوع', $out['recoveryMeta']['reason']);
    }

    public function test_nonsense_stays_zero(): void
    {
        foreach (['zzqqxx', 'زقنبوطية', 'قطط فضائية'] as $q) {
            $out = $this->ask($q);
            $this->assertSame(0, $out['result']->total, sprintf(
                '«%s» أعاد %d نتيجة — نمط=%s أُسقط=%s تغطية=%.2f',
                $q,
                $out['result']->total,
                $out['recoveryMeta']['mode'],
                json_encode($out['recoveryMeta']['removed'], JSON_UNESCAPED_UNICODE),
                $out['recoveryMeta']['coverage'],
            ));
            $this->assertSame(RecoveryOutcome::NONE, $out['recoveryMeta']['mode']);
        }
    }

    public function test_a_genuinely_absent_concept_stays_zero(): void
    {
        // لا «تاريخ» في هذا الكتالوج — لا في عنوانٍ ولا في مفردة.
        $out = $this->ask('تاريخ');

        $this->assertSame(0, $out['result']->total);
        $this->assertSame(RecoveryOutcome::NONE, $out['recoveryMeta']['mode']);
    }

    /* ───── الأصناف ───── */

    public function test_unknown_token_recovery_reports_its_own_mode(): void
    {
        /*
         * الشكلُ الإنتاجيّ بعينه: المستخرِجُ يبتلع «علوم رابع ابتدائي»
         * مرشِّحاتٍ، فلا يبقى في المتن إلّا كلمةٌ مجهولة واحدة — وهي وحدها
         * تُصفِّر السؤال، لأنّ المرشَّح الوحيد لا يحمل نصَّها.
         */
        $out = $this->ask('زقنبوطية علوم رابع ابتدائي');

        $this->assertGreaterThan(0, $out['result']->total, 'الكلمةُ المجهولة ما زالت تمحو السؤال');
        $this->assertContains(
            $out['recoveryMeta']['mode'],
            [RecoveryOutcome::UNKNOWN_TOKEN, RecoveryOutcome::MIXED],
        );
        $this->assertContains('token:زقنبوطيه', $out['recoveryMeta']['removed']);
    }

    public function test_recovery_declares_itself_in_the_legacy_relaxed_contract(): void
    {
        $out = $this->ask('بوربوينت علوم اول متوسط');

        $this->assertNotSame([], $out['relaxed'], 'تعافٍ صامت — تنكّر مطابقةً تامّة');
        $this->assertContains('grade', $out['relaxed']);
    }

    /* ───── ما اختاره الزائر بيده ───── */

    public function test_an_explicitly_chosen_facet_is_never_relaxed_away(): void
    {
        /*
         * الرقاقةُ المعروضة والنتائجُ يجب أن تقولا الشيء نفسه. فلو أُسقط
         * الصفُّ المنقور بقيت «أول متوسط» معروضةً فوق نتائجَ ابتدائيّة —
         * وهو كذبٌ في الواجهة أسوأُ من صفرٍ صريح.
         */
        $out = app(QueryPipeline::class)->run('علوم', 'all', 'relevance', 1, ['grade' => 'أول-متوسط']);

        $this->assertSame(0, $out['result']->total, 'أُسقط صفٌّ نقره الزائر بيده');
        $this->assertNotContains('grade', $out['recoveryMeta']['removed']);
        $this->assertSame('أول-متوسط', $out['filters']['grade'], 'المرشِّح المعروض تغيّر');
    }

    /** وبلا نقرةٍ صريحة يبقى الصفُّ قابلًا للتخفيف — الحماية للنقرة لا للبُعد. */
    public function test_the_same_dimension_is_still_relaxable_when_only_inferred(): void
    {
        $out = $this->ask('علوم اول متوسط');

        $this->assertGreaterThan(0, $out['result']->total);
        $this->assertContains('grade', $out['recoveryMeta']['removed']);
    }

    /* ───── قيدٌ خاطئ لا يُقبل بلا إعلان ───── */

    public function test_a_relaxed_constraint_is_always_named_in_the_response(): void
    {
        $out = $this->ask('بوربوينت علوم اول متوسط');

        // كلُّ بُعدٍ سقط يُذكر باسمه — لا تخفيفَ مجهولَ السبب.
        foreach ($out['recoveryMeta']['removed'] as $key) {
            $this->assertContains($key, $out['relaxed'], "أُسقط «{$key}» بلا إعلان");
        }
        $this->assertSame(
            count($out['recoveryMeta']['removed']),
            count($out['relaxed']),
            'عددُ ما أُعلن يخالف عددَ ما أُسقط',
        );
    }

    /* ───── الحدود ───── */

    public function test_recovery_stays_within_its_declared_budget(): void
    {
        $out = $this->ask('ابغي بوربوينت علوم اول متوسط الفصل الأول');

        $this->assertLessThanOrEqual(8, $out['recoveryMeta']['searches']);
        $this->assertLessThanOrEqual(5, $out['recoveryMeta']['depth']);
    }

    /* ───── الحتميّة ───── */

    public function test_repeated_runs_agree_on_ids_order_and_recovery_metadata(): void
    {
        $queries = [
            'بوربوينت علوم اول متوسط',
            'ملزمة مراجعة نهائية',
            'ابغي ملزمة مراجعة نهائية',
            'بوربوينت اول متوسط الجهاز الهضمي',
            'زقنبوطية',
        ];

        foreach ($queries as $q) {
            $seen = [];
            for ($i = 0; $i < 3; $i++) {
                $out = $this->ask($q);
                $seen[] = json_encode([
                    $out['result']->items->pluck('id')->all(),
                    $out['recoveryMeta'],
                ], JSON_UNESCAPED_UNICODE);
            }

            $this->assertCount(1, array_unique($seen), "«{$q}» أعطى نتيجتين مختلفتين");
        }
    }
}
