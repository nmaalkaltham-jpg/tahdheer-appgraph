<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\BundleComposition;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductGroupAuthoring;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الحزمة المختلطة — تُشترى مرّةً وتُسلَّم بطرق مكوّناتها.
 *
 * ─── ما تُثبته ───
 *
 * أنّ الحزمة ليست حالةً خاصّة في نظام الأنواع بل امتحانٌ له: تُوسَّع عند
 * الدفع إلى بنودٍ كاملة الحقوق، فيسلك كلٌّ منها مسار نوعه بلا حرفٍ جديد في
 * المنسّق. وأنّ ثمنها يبقى ثمنَها موزَّعًا، لا مجموعَ مكوّناتها.
 */
class BundleCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Product, 1: ProductGroup, 2: ProductVariant} */
    private function sellable(string $nature, int $priceMinor, ?int $weight = null): array
    {
        $n = bin2hex(random_bytes(4));

        $product = Product::create([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => "منتج {$n}",
            'status' => 'published', 'published_at' => now()->subDay(),
            'fulfillment_type' => 'digital',
        ]);
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => $priceMinor, 'currency' => 'SAR']);

        $group = app(ProductGroupAuthoring::class)->create($product, $nature);
        $group->update(['status' => 'published']);

        $variant = ProductVariant::query()->where('legacy_product_id', $product->id)->firstOrFail();
        $variant->update(['status' => 'published', 'weight_grams' => $weight]);

        ProductOffer::query()->where('product_variant_id', $variant->id)
            ->update(['status' => 'active', 'regular_minor' => $priceMinor, 'inventory_strategy' => 'UNLIMITED']);

        return [$product, $group->refresh(), $variant->refresh()];
    }

    /** @return array{0: Product, 1: ProductGroup} */
    private function bundle(int $priceMinor): array
    {
        [$product, $group] = $this->sellable('COMPOSITE', $priceMinor);

        return [$product, $group];
    }

    private function checkout(Product $bundle, array $extra = []): array
    {
        $this->postJson('/api/v1/cart/items', ['productId' => $bundle->id])->assertOk();

        return app(CheckoutService::class)->createOrder(Cart::query()->firstOrFail(), array_merge([
            'name' => 'زبون', 'email' => bin2hex(random_bytes(4)).'@test.local', 'phone' => '0500000000',
        ], $extra));
    }

    public function test_a_mixed_bundle_becomes_one_line_per_component(): void
    {
        [$bundleProduct, $bundleGroup] = $this->bundle(15000);
        [, , $digital] = $this->sellable('DIGITAL', 9000);
        [, , $physical] = $this->sellable('PHYSICAL', 12000, 700);

        $composition = app(BundleComposition::class);
        $composition->add($bundleGroup, (int) $digital->id);
        $composition->add($bundleGroup, (int) $physical->id);

        ['order' => $order] = $this->checkout($bundleProduct, [
            'shippingAddress' => ['city' => 'الرياض'],
        ]);

        $rows = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get();

        $this->assertCount(2, $rows, 'بندٌ لكلّ مكوّن — وإلّا انهارت الحزمة إلى استحقاقٍ واحد');
        $this->assertSame(
            [(int) $digital->id, (int) $physical->id],
            $rows->pluck('product_variant_id')->map(fn ($v) => (int) $v)->all(),
        );
    }

    public function test_the_bundle_price_is_allocated_exactly_not_summed(): void
    {
        [$bundleProduct, $bundleGroup] = $this->bundle(10000);
        [, , $a] = $this->sellable('DIGITAL', 9000);
        [, , $b] = $this->sellable('DIGITAL', 9000);
        [, , $c] = $this->sellable('DIGITAL', 9000);

        $composition = app(BundleComposition::class);
        foreach ([$a, $b, $c] as $variant) {
            $composition->add($bundleGroup, (int) $variant->id);
        }

        ['order' => $order] = $this->checkout($bundleProduct);

        $shares = DB::table('order_items')->where('order_id', $order->id)->pluck('price_snapshot_minor')
            ->map(fn ($v) => (int) $v)->all();

        /*
         * ١٠٠٫٠٠ على ثلاثة: القسمة الساذجة تُضيّع هللةً في كلّ طلب، والتقريب
         * لأعلى يُحصّل هللتين زيادة. و«أكبر باقٍ» يُخرج المجموع مساويًا.
         */
        $this->assertSame(10000, array_sum($shares), 'مجموع الحصص يساوي ثمن الحزمة حرفًا');
        $this->assertSame([3334, 3333, 3333], $shares);

        $this->assertSame(10000, (int) $order->total_minor,
            'والمحصَّل ثمنُ الحزمة لا مجموع مكوّناتها (٢٧٠٫٠٠)');
    }

    public function test_each_component_is_delivered_by_its_own_type(): void
    {
        [$bundleProduct, $bundleGroup] = $this->bundle(15000);
        [, , $digital] = $this->sellable('DIGITAL', 9000);
        [, , $physical] = $this->sellable('PHYSICAL', 12000, 700);

        $composition = app(BundleComposition::class);
        $composition->add($bundleGroup, (int) $digital->id);
        $composition->add($bundleGroup, (int) $physical->id);

        ['order' => $order] = $this->checkout($bundleProduct, [
            'shippingAddress' => ['city' => 'الرياض'],
        ]);

        $order->update(['status' => 'PAID', 'paid_at' => now()]);
        app(FulfillmentOrchestrator::class)->fulfill($order->fresh('items'));

        $strategies = FulfillmentUnit::query()->where('order_id', $order->id)
            ->pluck('strategy')->sort()->values()->all();

        $this->assertSame(['DIGITAL_DOWNLOAD', 'PHYSICAL_SHIPMENT'], $strategies,
            'كلّ مكوّنٍ يسلك مسار نوعه — من الحزمة نفسها، بلا حرفٍ جديد في المنسّق');
    }

    public function test_a_bundle_carrying_something_shipped_asks_for_an_address(): void
    {
        [$bundleProduct, $bundleGroup] = $this->bundle(15000);
        [, , $physical] = $this->sellable('PHYSICAL', 12000, 700);

        app(BundleComposition::class)->add($bundleGroup, (int) $physical->id);

        $this->expectException(ValidationException::class);
        $this->checkout($bundleProduct);
    }

    public function test_an_empty_bundle_is_refused_before_the_money_moves(): void
    {
        [$bundleProduct] = $this->bundle(15000);

        /* بندٌ بلا تسليم يُقفل الطلب على لا شيء — والمشتري دفع. */
        $this->expectException(ValidationException::class);
        $this->checkout($bundleProduct);
    }

    public function test_a_bundle_cannot_contain_itself(): void
    {
        [, $bundleGroup] = $this->bundle(15000);
        $own = ProductVariant::query()->where('product_group_id', $bundleGroup->id)->firstOrFail();

        try {
            app(BundleComposition::class)->add($bundleGroup, (int) $own->id);
            $this->fail('حزمةٌ تحتوي نفسها تُوسَّع بلا نهاية عند الدفع — تعليقٌ لا خطأ.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('نفسها', $e->errors()['componentVariantId'][0]);
        }
    }

    public function test_a_bundle_cannot_contain_another_bundle(): void
    {
        [, $outer] = $this->bundle(15000);
        [, $inner] = $this->bundle(9000);
        $innerVariant = ProductVariant::query()->where('product_group_id', $inner->id)->firstOrFail();

        try {
            app(BundleComposition::class)->add($outer, (int) $innerVariant->id);
            $this->fail('لا شيء يوسّع الحزم المتداخلة — فتُرفض بدل أن تُسلَّم ناقصة.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('حزمةٌ أخرى', $e->errors()['componentVariantId'][0]);
        }
    }

    public function test_the_same_component_cannot_be_added_twice(): void
    {
        [, $bundleGroup] = $this->bundle(15000);
        [, , $digital] = $this->sellable('DIGITAL', 9000);

        $composition = app(BundleComposition::class);
        $composition->add($bundleGroup, (int) $digital->id);

        /*
         * الفهرس صار `NULLS NOT DISTINCT` فيمنعه في المحرّك، والفحص هنا
         * يحوّل ٢٣٥٠٥ الخام إلى ٤٢٢ باسم حقلها (القاعدة ٣٨).
         */
        $this->expectException(ValidationException::class);
        $composition->add($bundleGroup, (int) $digital->id);
    }

    public function test_components_are_refused_on_a_product_that_is_not_a_bundle(): void
    {
        [, $digitalGroup] = $this->sellable('DIGITAL', 9000);
        [, , $other] = $this->sellable('DIGITAL', 5000);

        try {
            app(BundleComposition::class)->add($digitalGroup, (int) $other->id);
            $this->fail('المكوّنات للحزمة المركَّبة وحدها.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('productNature', $e->errors());
        }
    }
}
