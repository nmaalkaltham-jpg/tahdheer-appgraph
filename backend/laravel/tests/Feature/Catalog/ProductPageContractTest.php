<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\BundleComposition;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductGroupAuthoring;
use App\Domain\Licensing\Models\LicenseTerms;
use App\Models\Grade;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * حقول صفحة المنتج المضافة للتصميم: الأكثر مبيعًا، الإصدار، آخر تعديل،
 * الترخيص، الحزمة، والمنتجات ذات الصلة — كلٌّ من مصدره الحقيقيّ.
 */
class ProductPageContractTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** @return array{0: Product, 1: ProductGroup, 2: ProductVariant} */
    private function sellable(string $nature, int $priceMinor, array $attributes = []): array
    {
        $n = ++$this->seq;

        $product = Product::create(array_merge([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => "منتج {$n}",
            'status' => 'published', 'published_at' => now()->subDays($n),
            'fulfillment_type' => 'digital',
        ], $attributes));
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => $priceMinor, 'currency' => 'SAR']);

        $group = app(ProductGroupAuthoring::class)->create($product, $nature);
        $group->update(['status' => 'published']);

        $variant = ProductVariant::query()->where('legacy_product_id', $product->id)->firstOrFail();
        $variant->update(['status' => 'published']);

        ProductOffer::query()->where('product_variant_id', $variant->id)
            ->update(['status' => 'active', 'regular_minor' => $priceMinor]);

        return [$product, $group->refresh(), $variant->refresh()];
    }

    private function paidOrder(Product $product, int $units = 1, string $status = 'PAID'): void
    {
        $n = ++$this->seq;
        $orderId = DB::table('orders')->insertGetId([
            'number' => 'ORD-'.$n, 'email' => "buyer{$n}@example.test", 'status' => $status,
            'totals_gross_minor' => 100 * $units, 'total_minor' => 100 * $units, 'refunded_total_minor' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 0; $i < $units; $i++) {
            DB::table('order_items')->insert([
                'order_id' => $orderId, 'product_id' => $product->id, 'title_snapshot' => $product->title,
                'price_snapshot_minor' => 100, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_best_seller_is_true_only_for_products_with_paid_sales(): void
    {
        [$sold] = $this->sellable('DIGITAL', 1000);
        [$unsold] = $this->sellable('DIGITAL', 1000);
        [$pending] = $this->sellable('DIGITAL', 1000);

        $this->paidOrder($sold);
        $this->paidOrder($pending, 3, 'PENDING');

        $this->getJson('/api/v1/products/'.$sold->slug)->assertOk()->assertJsonPath('data.bestSeller', true);
        $this->getJson('/api/v1/products/'.$unsold->slug)->assertOk()->assertJsonPath('data.bestSeller', false);
        $this->getJson('/api/v1/products/'.$pending->slug)->assertOk()->assertJsonPath('data.bestSeller', false);

        // البطاقة في القائمة تحمل الشارة نفسها من المصدر نفسه.
        $cards = collect($this->getJson('/api/v1/products?source=new')->assertOk()->json('data'))->keyBy('slug');
        $this->assertTrue($cards[$sold->slug]['bestSeller']);
        $this->assertFalse($cards[$unsold->slug]['bestSeller']);
        $this->assertSame(1, $cards[$sold->slug]['purchaseCount']);
        $this->assertSame(0, $cards[$unsold->slug]['purchaseCount']);
        $this->assertSame(0, $cards[$pending->slug]['purchaseCount'], 'الطلب غير المدفوع لا يُعدّ');
        $this->assertSame(1, $this->getJson('/api/v1/products/'.$sold->slug)->json('data.purchaseCount'));
    }

    public function test_version_and_updated_at_are_exposed_read_only(): void
    {
        [$product] = $this->sellable('DIGITAL', 1000);
        $product->update(['version' => 'إصدار ١٤٤٧هـ']);

        $response = $this->getJson('/api/v1/products/'.$product->slug)->assertOk()
            ->assertJsonPath('data.version', 'إصدار ١٤٤٧هـ');

        $this->assertSame($product->refresh()->updated_at->toIso8601String(), $response->json('data.updatedAt'));

        [$unversioned] = $this->sellable('DIGITAL', 1000);
        $this->getJson('/api/v1/products/'.$unversioned->slug)->assertOk()->assertJsonPath('data.version', null);
    }

    public function test_license_label_comes_from_the_offer_terms_or_is_null(): void
    {
        [$licensed, , $variant] = $this->sellable('DIGITAL', 1000);
        [$plain] = $this->sellable('DIGITAL', 1000);

        $terms = LicenseTerms::create([
            'code' => 'SCHOOL', 'name' => 'رخصة مدرسة', 'license_type' => 'INSTITUTION', 'terms_version' => '1',
        ]);
        ProductOffer::query()->where('product_variant_id', $variant->id)->update(['license_terms_id' => $terms->id]);

        $this->getJson('/api/v1/products/'.$licensed->slug)->assertOk()->assertJsonPath('data.licenseLabel', 'رخصة مدرسة');
        $this->getJson('/api/v1/products/'.$plain->slug)->assertOk()->assertJsonPath('data.licenseLabel', null);
    }

    public function test_bundle_is_null_without_membership_and_summarised_when_published(): void
    {
        [$bundleProduct, $bundleGroup] = $this->sellable('COMPOSITE', 15000);
        [$member, , $memberVariant] = $this->sellable('DIGITAL', 9000);
        [, , $other] = $this->sellable('DIGITAL', 12000);
        [$loner] = $this->sellable('DIGITAL', 5000);

        $this->getJson('/api/v1/products/'.$member->slug)->assertOk()->assertJsonPath('data.bundle', null);

        $composition = app(BundleComposition::class);
        $composition->add($bundleGroup, (int) $memberVariant->id);
        $composition->add($bundleGroup, (int) $other->id);

        $this->getJson('/api/v1/products/'.$member->slug)->assertOk()
            ->assertJsonPath('data.bundle.slug', $bundleGroup->slug)
            ->assertJsonPath('data.bundle.title', $bundleProduct->title)
            ->assertJsonPath('data.bundle.itemsCount', 2)
            ->assertJsonPath('data.bundle.priceMinor', 15000)
            ->assertJsonPath('data.bundle.regularMinor', 21000)
            ->assertJsonPath('data.bundle.currency', 'SAR')
            ->assertJsonPath('data.bundle.url', '/bundles/'.$bundleGroup->slug);

        $this->getJson('/api/v1/products/'.$loner->slug)->assertOk()->assertJsonPath('data.bundle', null);

        // حزمةٌ غير منشورة لا تُعلَن.
        $bundleGroup->update(['status' => 'draft']);
        $this->getJson('/api/v1/products/'.$member->slug)->assertOk()->assertJsonPath('data.bundle', null);
    }

    public function test_related_prefers_same_grade_and_subject_and_caps_at_four(): void
    {
        $stage = Stage::create(['slug' => 'ابتدائي', 'name_ar' => 'ابتدائي']);
        $grade = Grade::create(['slug' => 'الأول', 'name_ar' => 'الصف الأول', 'stage_id' => $stage->id]);
        $subject = Subject::create(['slug' => 'الرياضيات', 'name_ar' => 'الرياضيات']);
        $taxonomy = ['stage_id' => $stage->id, 'grade_id' => $grade->id, 'subject_id' => $subject->id];

        [$product] = $this->sellable('DIGITAL', 1000, $taxonomy);
        $siblings = [];
        for ($i = 0; $i < 5; $i++) {
            [$siblings[]] = $this->sellable('DIGITAL', 1000, $taxonomy);
        }
        [$stranger] = $this->sellable('DIGITAL', 1000);
        $this->paidOrder($siblings[4]);

        $related = $this->getJson('/api/v1/products/'.$product->slug)->assertOk()->json('data.related');

        $this->assertCount(4, $related);
        $this->assertSame($siblings[4]->slug, $related[0]['slug'], 'الأكثر بيعًا أوّلًا');
        $this->assertNotContains($stranger->slug, array_column($related, 'slug'));
        $this->assertNotContains($product->slug, array_column($related, 'slug'));

        $this->assertSame([], $this->getJson('/api/v1/products/'.$stranger->slug)->assertOk()->json('data.related'));
    }
}
