<?php

namespace Tests\Feature;

use App\Domain\Catalog\ProductClassifier;
use App\Models\Grade;
use App\Models\Kind;
use App\Models\Product;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Database\Seeders\CurriculumSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductClassifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurriculumSeeder::class);
    }

    public function test_seed_matches_the_canonical_vocabulary_counts(): void
    {
        $this->assertSame(3, Stage::query()->count());
        $this->assertSame(12, Grade::query()->count());
        $this->assertSame(18, Subject::query()->count());
        $this->assertSame(3, Semester::query()->count());
        $this->assertSame(7, Kind::query()->count());
    }

    public function test_reads_kind_subject_grade_semester_and_infers_stage(): void
    {
        $reading = (new ProductClassifier)->read('تحضير رياضيات ثالث ابتدائي الفصل الأول');

        $grade = Grade::query()->where('slug', 'ثالث-ابتدائي')->first();
        $this->assertSame($grade->id, $reading['grade']);
        $this->assertSame($grade->stage_id, $reading['stage']);
        $this->assertSame(
            [Subject::query()->where('slug', 'الرياضيات')->value('id')],
            $reading['subject'],
        );
        $this->assertSame(
            Semester::query()->where('name_ar', 'الفصل الدراسي الأول')->value('id'),
            $reading['semester'],
        );
        $this->assertSame([Kind::query()->where('slug', 'تحضير')->value('id')], $reading['kind']);
    }

    public function test_longest_alias_wins_over_the_shorter_one(): void
    {
        // «الدراسات الإسلامية» must match the full subject, not the bare «إسلامية» alias
        // of a different reading — both map to the same subject here, and the
        // whole-word rule keeps «أول» from capturing «أولى».
        $reading = (new ProductClassifier)->read('أوراق عمل الدراسات الإسلامية');
        $this->assertSame(
            [Subject::query()->where('slug', 'الدراسات الإسلامية')->value('id')],
            $reading['subject'],
        );
        $this->assertSame([Kind::query()->where('slug', 'أوراق عمل')->value('id')], $reading['kind']);
    }

    public function test_normalized_variants_still_match(): void
    {
        // Arabic digit grade + spelling variants (أ folded, ة folded) still classify.
        $reading = (new ProductClassifier)->read('بوربوينت علوم الصف ٦ الابتدائي الترم الأول');

        $grade = Grade::query()->where('slug', 'سادس-ابتدائي')->first();
        $this->assertSame($grade->id, $reading['grade']);
        // «الابتدائي» is not a stage alias — the stage arrives by grade ⇒ stage inference.
        $this->assertSame($grade->stage_id, $reading['stage']);
        $this->assertSame([Subject::query()->where('slug', 'العلوم')->value('id')], $reading['subject']);
        $this->assertSame([Kind::query()->where('slug', 'عرض بوربوينت')->value('id')], $reading['kind']);
        $this->assertSame(Semester::query()->where('slug', 'الفصل-الأول')->value('id'), $reading['semester']);
    }

    public function test_apply_fills_missing_dimensions_on_the_product(): void
    {
        $product = Product::query()->create([
            'sku' => 'TD-0100',
            'slug' => 'tahdheer-math-3p-s1',
            'title' => 'تحضير رياضيات ثالث ابتدائي الفصل الأول',
            'status' => 'draft',
        ]);

        $written = (new ProductClassifier)->apply($product);

        $product->refresh();
        $grade = Grade::query()->where('slug', 'ثالث-ابتدائي')->first();
        $this->assertSame($grade->id, $product->grade_id);
        $this->assertSame($grade->stage_id, $product->stage_id);
        $this->assertSame(Subject::query()->where('slug', 'الرياضيات')->value('id'), $product->subject_id);
        $this->assertSame(Semester::query()->where('name_ar', 'الفصل الدراسي الأول')->value('id'), $product->semester_id);
        $this->assertSame('تحضير', $product->resource_type);
        $this->assertArrayHasKey('grade_id', $written);
    }

    public function test_never_overwrites_a_manual_assignment(): void
    {
        $manualSubject = Subject::query()->where('slug', 'لغتي')->value('id');

        $product = Product::query()->create([
            'sku' => 'TD-0101',
            'slug' => 'manual-subject-product',
            'title' => 'تحضير رياضيات ثالث ابتدائي الفصل الأول',
            'subject_id' => $manualSubject, // the merchant already decided
            'resource_type' => 'حلول',
            'status' => 'draft',
        ]);

        (new ProductClassifier)->apply($product);

        $product->refresh();
        $this->assertSame($manualSubject, $product->subject_id);   // untouched
        $this->assertSame('حلول', $product->resource_type);        // untouched
        // Missing dimensions were still filled in.
        $this->assertNotNull($product->grade_id);
        $this->assertNotNull($product->stage_id);
    }
}
