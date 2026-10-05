<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\BundleComposition;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductGroupAuthoring;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** تلميح الصيغة `variants[].note` — مشتقٌّ من الصيغة وقرار التعديل ومكوّنات الحزمة. */
class VariantNoteTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** @return array{0: Product, 1: ProductGroup, 2: ProductVariant} */
    private function sellable(string $nature, string $format, array $attributes = []): array
    {
        $n = ++$this->seq;

        $product = Product::create(array_merge([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => "منتج {$n}", 'format' => $format,
            'status' => 'published', 'published_at' => now()->subDay(), 'fulfillment_type' => 'digital',
        ], $attributes));
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => 1000, 'currency' => 'SAR']);

        $group = app(ProductGroupAuthoring::class)->create($product, $nature);
        $group->update(['status' => 'published']);

        $variant = ProductVariant::query()->where('legacy_product_id', $product->id)->firstOrFail();
        $variant->update(['status' => 'published', 'format' => $format]);
        ProductOffer::query()->where('product_variant_id', $variant->id)->update(['status' => 'active']);

        return [$product, $group->refresh(), $variant->refresh()];
    }

    private function note(Product $product): ?string
    {
        return $this->getJson('/api/v1/products/'.$product->slug)->assertOk()->json('data.variants.0.note');
    }

    public function test_pdf_is_for_print_and_office_formats_need_the_editable_decision(): void
    {
        [$pdf] = $this->sellable('DIGITAL', 'PDF');
        [$docx] = $this->sellable('DIGITAL', 'DOCX', ['editable' => 'yes']);
        [$pptxUndecided] = $this->sellable('DIGITAL', 'PPTX');
        [$docxLocked] = $this->sellable('DIGITAL', 'DOCX', ['editable' => 'no']);
        [$unknown] = $this->sellable('DIGITAL', 'XYZ');

        $this->assertSame('للطباعة', $this->note($pdf));
        $this->assertSame('قابل للتعديل', $this->note($docx));
        $this->assertNull($this->note($pptxUndecided));
        $this->assertNull($this->note($docxLocked));
        $this->assertNull($this->note($unknown));
    }

    public function test_a_bundle_lists_its_component_formats_in_display_order(): void
    {
        [$bundle, $group] = $this->sellable('COMPOSITE', 'ZIP');
        [, , $pdf] = $this->sellable('DIGITAL', 'PDF');
        [, , $pptx] = $this->sellable('DIGITAL', 'PPTX');
        [, , $docx] = $this->sellable('DIGITAL', 'DOCX');

        $this->assertNull($this->note($bundle), 'حزمةٌ بلا مكوّنات لا تلميح لها');

        $composition = app(BundleComposition::class);
        foreach ([$pdf, $pptx, $docx] as $variant) {
            $composition->add($group, (int) $variant->id);
        }

        $this->assertSame('Word + PPT + PDF', $this->note($bundle));
    }
}
