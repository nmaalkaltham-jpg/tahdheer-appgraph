<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\ProductCompatibility;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عقد صفحة المنتج بعد وصل النموذج الكونيّ بمسار القراءة العامّ.
 *
 * الكتالوج الحيّ اليوم منتجٌ واحد = صيغةٌ واحدة، فالمُبدِّل لا يُصيَّر. هذه
 * الاختبارات تثبّت السلوك في الحالتين معًا — الواحدة والمتعدّدة — كي لا يمرّ
 * تأليفُ صيغةٍ ثانية بلا سعرٍ صحيح، ولا يظهر مُبدِّلٌ لخيارٍ وحيد.
 *
 * والقاعدة الحاكمة: ما لا صفَّ له لا مفتاح له. لا صفر ولا مصفوفة فارغة.
 */
class ProductVariantContractTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attrs = []): Product
    {
        return Product::create([
            'sku' => 'VAR-'.uniqid(),
            'slug' => 'var-'.uniqid(),
            'title' => 'تحضير العلوم — الخامس الابتدائي',
            'status' => 'published',
            'published_at' => now(),
            'fulfillment_type' => 'digital',
            ...$attrs,
        ]);
    }

    private function group(Product $product): ProductGroup
    {
        return ProductGroup::create([
            'slug' => 'g-'.uniqid(),
            'title' => $product->title,
            'product_nature' => 'DIGITAL',
            'status' => 'published',
            'legacy_product_id' => $product->id,
        ]);
    }

    private function variant(ProductGroup $group, string $format, int $regular, ?int $sale = null): ProductVariant
    {
        $variant = ProductVariant::create([
            'product_group_id' => $group->id,
            'sku' => 'V-'.uniqid(),
            'slug' => 'v-'.uniqid(),
            'title' => $format,
            'format' => $format,
            'status' => 'published',
        ]);

        ProductOffer::create([
            'product_variant_id' => $variant->id,
            'selling_model' => 'ONE_TIME',
            'inventory_strategy' => 'UNLIMITED',
            'regular_minor' => $regular,
            'sale_minor' => $sale,
            'currency' => 'SAR',
            'status' => 'active',
        ]);

        return $variant;
    }

    public function test_a_single_format_product_emits_exactly_one_variant(): void
    {
        $product = $this->product();
        $this->variant($this->group($product), 'DOCX', 4500);

        $data = $this->getJson("/api/v1/products/{$product->slug}")
            ->assertOk()->json('data');

        $this->assertCount(1, $data['variants']);
        $this->assertSame('DOCX', $data['variants'][0]['format']);
        $this->assertTrue($data['variants'][0]['purchasable']);
        $this->assertSame(4500, $data['variants'][0]['priceMinor']);
    }

    public function test_each_format_carries_its_own_price_and_discount(): void
    {
        $product = $this->product();
        $group = $this->group($product);
        $this->variant($group, 'DOCX', 5900, 4500);
        $this->variant($group, 'PPTX', 7200, 5500);
        $this->variant($group, 'PDF', 3500);

        $variants = collect(
            $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data.variants')
        )->keyBy('format');

        $this->assertCount(3, $variants);

        $this->assertSame(4500, $variants['DOCX']['priceMinor']);
        $this->assertSame(5900, $variants['DOCX']['regularMinor']);
        $this->assertSame(24, $variants['DOCX']['discountPct']);

        $this->assertSame(5500, $variants['PPTX']['priceMinor']);
        $this->assertSame(24, $variants['PPTX']['discountPct']);

        /* بلا تخفيض: لا `discountPct` ولا سعرٌ مشطوب — لا صفرًا يُعرض. */
        $this->assertSame(3500, $variants['PDF']['priceMinor']);
        $this->assertFalse($variants['PDF']['onSale']);
        $this->assertArrayNotHasKey('discountPct', $variants['PDF']);
    }

    public function test_an_expired_sale_window_does_not_price_the_variant(): void
    {
        $product = $this->product();
        $variant = $this->variant($this->group($product), 'DOCX', 5900, 4500);
        $variant->offer->update(['sale_from' => now()->subDays(10), 'sale_to' => now()->subDay()]);

        $row = $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data.variants.0');

        $this->assertFalse($row['onSale']);
        $this->assertSame(5900, $row['priceMinor'], 'نافذة منتهية ومع ذلك سُعِّرت بسعر التخفيض');
    }

    public function test_a_variant_without_an_active_offer_is_not_purchasable_and_has_no_price(): void
    {
        $product = $this->product();
        $variant = $this->variant($this->group($product), 'DOCX', 4500);
        /* `paused` حالةٌ حقيقيّة في المخطّط: العرض قائمٌ ولا يُباع اليوم. */
        $variant->offer->update(['status' => 'paused']);

        $row = $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data.variants.0');

        $this->assertFalse($row['purchasable']);
        $this->assertArrayNotHasKey('priceMinor', $row, 'صيغةٌ بلا عرضٍ صدَّرت سعرًا');
    }

    public function test_a_draft_variant_never_reaches_the_storefront(): void
    {
        $product = $this->product();
        $group = $this->group($product);
        $this->variant($group, 'DOCX', 4500);
        $this->variant($group, 'PPTX', 5500)->update(['status' => 'draft']);

        $variants = $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data.variants');

        $this->assertCount(1, $variants);
        $this->assertSame('DOCX', $variants[0]['format']);
    }

    public function test_a_product_outside_the_universal_model_omits_the_variants_key(): void
    {
        $product = $this->product();

        $data = $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data');

        $this->assertNull($data['variants'] ?? null, 'منتجٌ بلا مجموعة صدَّر مصفوفة صيغ');
    }

    public function test_compatibility_rows_surface_and_absence_omits_the_key(): void
    {
        $product = $this->product();
        $group = $this->group($product);
        $this->variant($group, 'DOCX', 4500);

        $this->assertArrayNotHasKey(
            'compatibility',
            $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data'),
            'تبويب توافقٍ فارغ يَعِد بمعلومةٍ ثمّ يخلف',
        );

        ProductCompatibility::create([
            'product_group_id' => $group->id,
            'target_kind' => 'SOFTWARE',
            'target_name' => 'Microsoft Word',
            'minimum_version' => '2016 وأحدث',
            'note' => 'تعديل كامل · خطوط عربية مرفقة',
        ]);

        $rows = $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data.compatibility');

        $this->assertCount(1, $rows);
        $this->assertSame('Microsoft Word', $rows[0]['name']);
        $this->assertSame('2016 وأحدث', $rows[0]['minVersion']);
    }

    public function test_purchase_count_is_zero_before_the_first_paid_order(): void
    {
        $product = $this->product();

        // صفرٌ صريح: الواجهة هي التي تقرّر إخفاء «٠ عملية شراء» لا الخادم.
        $this->assertSame(
            0,
            $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data.purchaseCount'),
        );
    }
}
