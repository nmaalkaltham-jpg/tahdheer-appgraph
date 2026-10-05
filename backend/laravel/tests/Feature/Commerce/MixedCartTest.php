<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Checkout\CheckoutRequirementsResolver;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * السلّة المختلطة ومتطلّبات الدفع (§٩٨ §١٠٠ §٣٢).
 *
 * المقصود إثبات أنّ المطلوب من الزبون **يُشتقّ ممّا في سلّته**، لا من علمٍ
 * في الواجهة ولا من نوع المنتج. طلبُ عنوانٍ لمن يشتري ملفًّا مسألةُ خصوصيّة
 * قبل أن يكون مسألة تحويل (§١٠٦).
 */
class MixedCartTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Product, 1: ProductVariant, 2: ProductOffer} */
    private function sellable(array $strategies, string $nature = 'DIGITAL'): array
    {
        $n = uniqid();

        $product = Product::create([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => 'منتج',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);

        $group = ProductGroup::create([
            'slug' => "g-{$n}", 'title' => 'مجموعة', 'product_nature' => $nature,
            'status' => 'published', 'legacy_product_id' => $product->id,
        ]);

        $variant = ProductVariant::create([
            'product_group_id' => $group->id, 'sku' => "V-{$n}", 'slug' => "v-{$n}",
            'status' => 'published', 'legacy_product_id' => $product->id,
        ]);

        $offer = ProductOffer::create([
            'product_variant_id' => $variant->id, 'regular_minor' => 5000, 'status' => 'active',
        ]);

        foreach ($strategies as $i => $s) {
            OfferFulfillment::create(['product_offer_id' => $offer->id, 'strategy' => $s, 'position' => $i]);
        }

        return [$product, $variant, $offer];
    }

    private function cartWith(array ...$lines): Cart
    {
        $cart = Cart::create(['token' => uniqid()]);

        foreach ($lines as [$product, $variant, $offer]) {
            CartItem::create([
                'cart_id' => $cart->id, 'product_id' => $product->id,
                'product_variant_id' => $variant->id, 'product_offer_id' => $offer->id,
                'qty' => 1, 'unit_price_snapshot_minor' => 5000,
            ]);
        }

        return $cart->fresh('items');
    }

    private function requirements(Cart $cart)
    {
        return $this->app->make(CheckoutRequirementsResolver::class)->for($cart);
    }

    public function test_a_digital_only_cart_asks_for_no_address(): void
    {
        $req = $this->requirements($this->cartWith($this->sellable(['DIGITAL_DOWNLOAD'])));

        // §٣٢ — لا يُطلب ما لا يلزم.
        $this->assertFalse($req->requiresShippingAddress);
        $this->assertFalse($req->requiresBookingDetails);
        $this->assertFalse($req->isMixed);
        $this->assertTrue($req->canProceed());
    }

    public function test_adding_one_physical_line_makes_the_whole_cart_need_an_address(): void
    {
        $req = $this->requirements($this->cartWith(
            $this->sellable(['DIGITAL_DOWNLOAD']),
            $this->sellable(['PHYSICAL_SHIPMENT'], 'PHYSICAL'),
        ));

        // السبب شحنةٌ في السلّة، لا «نوع منتج».
        $this->assertTrue($req->requiresShippingAddress);
        $this->assertTrue($req->isMixed);
        $this->assertEqualsCanonicalizing(['DIGITAL_DOWNLOAD', 'PHYSICAL_SHIPMENT'], $req->strategies);
    }

    public function test_a_service_cart_asks_for_booking_details_not_an_address(): void
    {
        $req = $this->requirements($this->cartWith($this->sellable(['APPOINTMENT'], 'SERVICE')));

        $this->assertTrue($req->requiresBookingDetails);
        $this->assertFalse($req->requiresShippingAddress);
    }

    public function test_a_hybrid_line_alone_already_needs_an_address(): void
    {
        $req = $this->requirements($this->cartWith(
            $this->sellable(['DIGITAL_DOWNLOAD', 'PHYSICAL_SHIPMENT'], 'HYBRID'),
        ));

        $this->assertTrue($req->requiresShippingAddress);
        $this->assertTrue($req->isMixed);
    }

    public function test_two_variants_of_one_product_are_two_lines_not_one(): void
    {
        [$product, $variantA, $offerA] = $this->sellable(['DIGITAL_DOWNLOAD']);

        $group = $variantA->group;
        $variantB = ProductVariant::create([
            'product_group_id' => $group->id, 'sku' => 'V-B-'.uniqid(), 'slug' => 'v-b-'.uniqid(),
            'status' => 'published',
        ]);
        $offerB = ProductOffer::create([
            'product_variant_id' => $variantB->id, 'regular_minor' => 2500, 'status' => 'active',
        ]);
        OfferFulfillment::create(['product_offer_id' => $offerB->id, 'strategy' => 'DIGITAL_DOWNLOAD']);

        $cart = $this->cartWith([$product, $variantA, $offerA]);
        CartItem::create([
            'cart_id' => $cart->id, 'product_id' => $product->id,
            'product_variant_id' => $variantB->id, 'product_offer_id' => $offerB->id,
            'qty' => 1, 'unit_price_snapshot_minor' => 2500,
        ]);

        /*
         * تحت القيد القديم `(cart_id, product_id)` كان الصفّ الثاني يُرفض:
         * A4 وA5 من المخطّط نفسه لا يجتمعان — وهو ما يجعل الصيغ غير قابلة
         * للبيع مهما نُمذجت.
         */
        $this->assertSame(2, $cart->fresh('items')->items->count());
    }

    public function test_the_same_variant_and_offer_still_cannot_be_duplicated(): void
    {
        [$product, $variant, $offer] = $this->sellable(['DIGITAL_DOWNLOAD']);
        $cart = $this->cartWith([$product, $variant, $offer]);

        $this->expectException(QueryException::class);
        CartItem::create([
            'cart_id' => $cart->id, 'product_id' => $product->id,
            'product_variant_id' => $variant->id, 'product_offer_id' => $offer->id,
            'qty' => 1, 'unit_price_snapshot_minor' => 5000,
        ]);
    }

    public function test_an_offer_without_a_variant_is_refused_by_the_database(): void
    {
        [$product, , $offer] = $this->sellable(['DIGITAL_DOWNLOAD']);
        $cart = Cart::create(['token' => uniqid()]);

        // نصفُ ربطٍ يُكتشف عند الدفع لا عند الإضافة — فيُمنع عند الإضافة.
        $this->expectException(QueryException::class);
        CartItem::create([
            'cart_id' => $cart->id, 'product_id' => $product->id,
            'product_offer_id' => $offer->id, 'qty' => 1, 'unit_price_snapshot_minor' => 5000,
        ]);
    }

    public function test_an_unresolvable_line_blocks_checkout_with_a_reason(): void
    {
        $orphan = Product::create([
            'sku' => 'ORPH-'.uniqid(), 'slug' => 'orph-'.uniqid(), 'title' => 'بلا عرض',
            'status' => 'published', 'fulfillment_type' => 'unknown-kind',
        ]);
        $cart = Cart::create(['token' => uniqid()]);
        CartItem::create(['cart_id' => $cart->id, 'product_id' => $orphan->id, 'qty' => 1, 'unit_price_snapshot_minor' => 100]);

        $req = $this->requirements($cart->fresh('items'));

        // يُكتشف قبل أخذ المال لا بعده.
        $this->assertFalse($req->canProceed());
        $this->assertNotEmpty($req->unresolvable);
    }
}
