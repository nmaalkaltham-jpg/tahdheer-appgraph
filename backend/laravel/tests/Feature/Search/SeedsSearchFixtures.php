<?php

namespace Tests\Feature\Search;

use App\Models\Grade;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\SynonymSeeder;

/**
 * Small evaluation-corpus fixture: product titles use REAL curriculum
 * vocabulary (these are test fixtures, not storefront data). Prices are
 * explicit; nothing is invented at runtime.
 */
trait SeedsSearchFixtures
{
    protected function seedSearchWorld(): void
    {
        $this->seed(CurriculumSeeder::class);
        $this->seed(SynonymSeeder::class);
    }

    /** @param array<string, mixed> $attributes */
    protected function makeProduct(string $sku, string $title, int $priceMinor, array $attributes = []): Product
    {
        $product = Product::query()->create(array_merge([
            'sku' => $sku,
            'slug' => 'p-'.strtolower($sku),
            'title' => $title,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ], $attributes));

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'regular_minor' => $priceMinor,
            'currency' => 'SAR',
        ]);

        return $product;
    }

    /** The corpus used by the relevance and API tests. */
    protected function seedCorpus(): void
    {
        $this->makeProduct('TD-1001', 'تحضير درس المفعول به', 1500, [
            'resource_type' => 'تحضير', 'format' => 'PDF',
        ]);

        $this->makeProduct('TD-1002', 'تحاضير مادة العلوم', 2000, [
            'resource_type' => 'تحضير', 'format' => 'DOCX',
            'subject_id' => Subject::query()->where('slug', 'العلوم')->value('id'),
        ]);

        $this->makeProduct('TD-2001', 'عرض بوربوينت درس الضوء لمادة العلوم سادس ابتدائي الفصل الأول', 2500, [
            'resource_type' => 'عرض بوربوينت', 'format' => 'PPTX', 'editable' => 'yes',
            'stage_id' => Stage::query()->where('slug', 'ابتدائي')->value('id'),
            'grade_id' => Grade::query()->where('slug', 'سادس-ابتدائي')->value('id'),
            'subject_id' => Subject::query()->where('slug', 'العلوم')->value('id'),
            'semester_id' => Semester::query()->where('slug', 'الفصل-الأول')->value('id'),
        ]);

        $this->makeProduct('TD-3001', 'ملزمة مراجعة نهائية', 1000, ['format' => 'PDF']);
        $this->makeProduct('TD-3002', 'ملزمة مراجعة نهائية الوحدة الثالثة', 1200, ['format' => 'PDF']);
        $this->makeProduct('TD-4001', 'بوربوينت درس التجويد', 1800, ['format' => 'PPTX']);
    }
}
