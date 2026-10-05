<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\Grade;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Semester;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سطر بيانات بطاقة المنتج — الصفّ والفصل وصيغ الملفّ وعلَم العيّنة.
 *
 * `ProductCardResource` يُصدرها من علاقاتٍ حمّلتها القائمة (لا استعلام لكلّ
 * بطاقة)، والغائب يغيب: لا صفّ ولا فصل ولا صيغة تُكتب ولم تُسجَّل. والمفتاحان
 * `gradeName`/`semesterName` لا يصطدمان بكائنَي `grade`/`semester` في صفحة المنتج.
 */
class ProductCardMetadataTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku, array $attributes = []): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'slug' => strtolower($sku),
            'title' => 'تحضير الرياضيات — '.$sku,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'fulfillment_type' => 'digital',
            ...$attributes,
        ]);
        ProductPrice::query()->create(['product_id' => $product->id, 'regular_minor' => 3500]);

        return $product;
    }

    /** @return array<string, array<string, mixed>> */
    private function cardsBySku(): array
    {
        $response = $this->getJson('/api/v1/products?source=new&limit=24')->assertOk();

        return collect($response->json('data'))->keyBy('sku')->all();
    }

    public function test_the_list_card_carries_grade_semester_formats_and_sample_flag(): void
    {
        $stage = Stage::create(['slug' => 'ابتدائي', 'name_ar' => 'ابتدائي']);
        $grade = Grade::create(['slug' => 'خامس', 'name_ar' => 'الصف الخامس الابتدائي', 'stage_id' => $stage->id]);
        $semester = Semester::create(['slug' => 'الأول', 'name_ar' => 'الفصل الدراسي الأول']);

        $full = $this->product('TD-M1', ['stage_id' => $stage->id, 'grade_id' => $grade->id, 'semester_id' => $semester->id]);
        $group = ProductGroup::create([
            'slug' => 'g-m1',
            'title' => $full->title,
            'product_nature' => 'DIGITAL',
            'status' => 'published',
            'legacy_product_id' => $full->id,
        ]);
        foreach (['PDF', 'docx', 'PDF'] as $i => $format) {
            ProductVariant::create([
                'product_group_id' => $group->id,
                'sku' => 'V-M1-'.$i,
                'slug' => 'v-m1-'.$i,
                'title' => $format,
                'format' => $format,
                'status' => 'published',
            ]);
        }
        ProductVariant::create([
            'product_group_id' => $group->id,
            'sku' => 'V-M1-draft',
            'slug' => 'v-m1-draft',
            'title' => 'PPTX',
            'format' => 'PPTX',
            'status' => 'draft',
        ]);
        $asset = Asset::create([
            'provider' => 'local',
            'provider_ref' => 'assets/m1.pdf',
            'original_name' => 'عيّنة.pdf',
            'sha256' => hash('sha256', 'm1'),
            'size_bytes' => 2,
            'mime' => 'application/pdf',
            'visibility' => 'public_sample',
        ]);
        ProductFile::create(['product_id' => $full->id, 'asset_id' => $asset->id, 'role' => 'sample', 'position' => 0]);

        $this->product('TD-M2');

        $cards = $this->cardsBySku();

        $card = $cards['TD-M1'];
        $this->assertSame('الصف الخامس الابتدائي', $card['gradeName']);
        $this->assertSame('الفصل الدراسي الأول', $card['semesterName']);
        /* المنشورة وحدها، بلا تكرار، بحروفٍ كبيرة — والمسوّدة لا تُعلن صيغتها. */
        $this->assertSame(['PDF', 'DOCX'], $card['formats']);
        $this->assertTrue($card['hasSample']);

        /* بلا صفّ ولا فصل ولا صيغ منشورة: المفاتيح تغيب، والعيّنة «لا» صريحة. */
        $bare = $cards['TD-M2'];
        $this->assertArrayNotHasKey('gradeName', $bare);
        $this->assertArrayNotHasKey('semesterName', $bare);
        $this->assertArrayNotHasKey('formats', $bare);
        $this->assertFalse($bare['hasSample']);
    }

    public function test_a_paid_file_is_not_a_sample(): void
    {
        $product = $this->product('TD-M3');
        $asset = Asset::create([
            'provider' => 'local',
            'provider_ref' => 'assets/m3.pdf',
            'original_name' => 'ملف.pdf',
            'sha256' => hash('sha256', 'm3'),
            'size_bytes' => 2,
            'mime' => 'application/pdf',
            'visibility' => 'paid_private',
        ]);
        ProductFile::create(['product_id' => $product->id, 'asset_id' => $asset->id, 'role' => 'sample', 'position' => 0]);

        $this->assertFalse($this->cardsBySku()['TD-M3']['hasSample']);
    }

    public function test_the_product_page_keeps_its_grade_and_semester_objects(): void
    {
        $stage = Stage::create(['slug' => 'ابتدائي', 'name_ar' => 'ابتدائي']);
        $grade = Grade::create(['slug' => 'خامس', 'name_ar' => 'الصف الخامس الابتدائي', 'stage_id' => $stage->id]);
        $product = $this->product('TD-M4', ['stage_id' => $stage->id, 'grade_id' => $grade->id]);

        $data = $this->getJson('/api/v1/products/'.$product->slug)->assertOk()->json('data');

        $this->assertIsArray($data['grade']);
        $this->assertSame('الصف الخامس الابتدائي', $data['grade']['name']);
    }
}
