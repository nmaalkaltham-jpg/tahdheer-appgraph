<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Inventory\Models\InventoryReservation;
use App\Domain\Inventory\Models\StockLevel;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * حجز المخزون عند الدفع (§٩ §٢٨).
 *
 * الحجز يقع **قبل** السداد لا بعده. بعده يكون المتجر قد باع ما لا يملك، ولا
 * يبقى إلّا الاعتذار وردّ المال — وهي أغلى طريقةٍ لاكتشاف نفاد المخزون.
 */
class CheckoutStockReservationTest extends TestCase
{
    use RefreshDatabase;

    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = InventoryLocation::create([
            'code' => 'L-'.uniqid(), 'name' => 'مستودع', 'kind' => 'WAREHOUSE',
        ]);
    }

    /** @return array{0: Product, 1: ProductVariant, 2: ProductOffer} */
    private function trackedProduct(int $stock): array
    {
        $n = uniqid();

        $product = Product::create([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => 'كتاب مطبوع',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => 5000]);

        $group = ProductGroup::create([
            'slug' => "g-{$n}", 'title' => 'كتاب', 'product_nature' => 'PHYSICAL',
            'status' => 'published', 'legacy_product_id' => $product->id,
        ]);
        $variant = ProductVariant::create([
            'product_group_id' => $group->id, 'sku' => "V-{$n}", 'slug' => "v-{$n}",
            'status' => 'published', 'legacy_product_id' => $product->id, 'weight_grams' => 400,
        ]);
        $offer = ProductOffer::create([
            'product_variant_id' => $variant->id, 'regular_minor' => 5000,
            'status' => 'active', 'inventory_strategy' => 'TRACKED',
        ]);
        OfferFulfillment::create(['product_offer_id' => $offer->id, 'strategy' => 'PHYSICAL_SHIPMENT']);

        if ($stock > 0) {
            $this->app->make(InventoryService::class)
                ->receive($variant->id, $this->location->id, $stock, 'شحنة افتتاحيّة');
        }

        return [$product, $variant, $offer];
    }

    private function cartWith(Product $p, ?ProductVariant $v, ?ProductOffer $o, int $qty = 1): Cart
    {
        $cart = Cart::create(['token' => uniqid()]);
        CartItem::create([
            'cart_id' => $cart->id, 'product_id' => $p->id,
            'product_variant_id' => $v?->id, 'product_offer_id' => $o?->id,
            'qty' => $qty, 'unit_price_snapshot_minor' => 5000,
        ]);

        return $cart->fresh('items');
    }

    private function checkout(Cart $cart): array
    {
        return $this->app->make(CheckoutService::class)->createOrder($cart, [
            'name' => 'زبون', 'email' => uniqid().'@test.local', 'phone' => '0500000000',
            /*
             * هذه السلّة تحمل شحنةً ماديّة، فالدفع يطلب عنوانًا — ووحدةُ الشحن
             * تقف بدونه. الفحص في `CheckoutRequirementsResolver` لا في نوع
             * المنتج، فسلّةٌ رقميّة بحتة تمرّ بلا عنوان كما كانت.
             */
            'shippingAddress' => ['city' => 'الرياض'],
        ]);
    }

    public function test_checkout_holds_stock_for_a_tracked_product(): void
    {
        [$p, $v, $o] = $this->trackedProduct(stock: 10);

        $this->checkout($this->cartWith($p, $v, $o, qty: 3));

        $reservation = InventoryReservation::query()->firstOrFail();
        $this->assertSame(3, $reservation->quantity);
        $this->assertSame('HELD', $reservation->status);
        // المحجوز ينزل من المتاح والموجود لم يتحرّك بعد.
        $this->assertSame(7, $this->app->make(InventoryService::class)->availableFor($v->id));
    }

    public function test_ordering_more_than_exists_is_refused_before_payment(): void
    {
        [$p, $v, $o] = $this->trackedProduct(stock: 2);

        try {
            $this->checkout($this->cartWith($p, $v, $o, qty: 5));
            $this->fail('كان يجب أن يُرفض.');
        } catch (ValidationException $e) {
            // الرسالة تُعرض كما هي: «المتاح ٢ والمطلوب ٥» أوضح من «تعذّر».
            $this->assertStringContainsString('لا يكفي المخزون', $e->getMessage());
        }

        // ولا طلبَ نصفَ مُنشأ: المعاملة كلّها تسقط.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, InventoryReservation::query()->count());
    }

    public function test_the_order_item_carries_the_variant_and_the_strategies(): void
    {
        [$p, $v, $o] = $this->trackedProduct(stock: 5);

        ['order' => $order] = $this->checkout($this->cartWith($p, $v, $o));
        $item = $order->items()->firstOrFail();

        /*
         * §١٠١ — كانت الصيغة والعرض يضيعان بين السلّة والطلب، فيعود المنسّق
         * إلى جسر المنتج القديم ولا يجد وزنًا ولا شروط رخصة.
         */
        $this->assertSame($v->id, $item->product_variant_id);
        $this->assertSame($o->id, $item->product_offer_id);
        $this->assertSame($v->sku, $item->sku_snapshot);
        $this->assertSame(['PHYSICAL_SHIPMENT'], $item->fulfillment_strategies_snapshot);
        $this->assertSame('ONE_TIME', $item->selling_model_snapshot);
    }

    public function test_an_unlimited_digital_product_reserves_nothing(): void
    {
        $n = uniqid();
        $product = Product::create([
            'sku' => "D-{$n}", 'slug' => "d-{$n}", 'title' => 'ملفّ',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => 5000]);
        $group = ProductGroup::create([
            'slug' => "gd-{$n}", 'title' => 'ملفّ', 'product_nature' => 'DIGITAL',
            'status' => 'published', 'legacy_product_id' => $product->id,
        ]);
        $variant = ProductVariant::create([
            'product_group_id' => $group->id, 'sku' => "VD-{$n}", 'slug' => "vd-{$n}",
            'status' => 'published', 'legacy_product_id' => $product->id,
        ]);
        $offer = ProductOffer::create([
            'product_variant_id' => $variant->id, 'regular_minor' => 5000,
            'status' => 'active', 'inventory_strategy' => 'UNLIMITED',
        ]);
        OfferFulfillment::create(['product_offer_id' => $offer->id, 'strategy' => 'DIGITAL_DOWNLOAD']);

        $this->checkout($this->cartWith($product, $variant, $offer));

        // الرقميّ لا ينفد؛ حجزُه صفوفٌ بلا معنى تُبطئ كلّ عمليّة شراء.
        $this->assertSame(0, InventoryReservation::query()->count());
    }

    public function test_a_legacy_line_without_a_variant_still_checks_out(): void
    {
        $n = uniqid();
        $product = Product::create([
            'sku' => "L-{$n}", 'slug' => "l-{$n}", 'title' => 'منتج قديم',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => 5000]);

        ['order' => $order] = $this->checkout($this->cartWith($product, null, null));

        // البنود الـ١٥٣ السابقة للنموذج الكونيّ لا تُكسر بهذا التغيير.
        $this->assertSame(1, $order->items()->count());
        $this->assertNull($order->items()->first()->product_variant_id);
    }

    public function test_a_paid_order_consumes_its_reservation_so_stock_actually_leaves(): void
    {
        [$p, $v, $o] = $this->trackedProduct(stock: 10);
        ['order' => $order] = $this->checkout($this->cartWith($p, $v, $o, qty: 3));

        $inventory = $this->app->make(InventoryService::class);
        $this->assertSame(7, $inventory->availableFor($v->id), 'المحجوز ينزل من المتاح');

        /*
         * ─── والتسوية كانت غائبة ───
         *
         * `consume()` مكتوبةٌ منذ بناء المخزون ولا يستدعيها شيء: فالحجز يبقى
         * معلّقًا حتّى تنتهي مهلتُه فيُفرَج عنه — أي أنّ **بيعًا تامًّا يُعيد
         * البضاعة إلى المتاح**، و`on_hand` لا ينقص أبدًا. فيقول العدُّ إنّ في
         * المستودع ما ليس فيه، ويُباع ما لا يوجد.
         */
        $this->app->make(InventoryService::class)->settleFor('order', (int) $order->id, 'CONSUMED');

        $this->assertSame('CONSUMED', InventoryReservation::query()
            ->where('holder_id', $order->id)->value('status'));
        $this->assertSame(7, $inventory->availableFor($v->id),
            'المتاح لا يتغيّر بالتسوية — ما كان محجوزًا صار مباعًا');

        $level = StockLevel::query()
            ->where('product_variant_id', $v->id)->firstOrFail();

        $this->assertSame(7, (int) $level->on_hand, 'الموجود ينقص فعلًا: ثلاثُ نسخٍ غادرت المستودع');
        $this->assertSame(0, (int) $level->reserved, 'ولا يبقى حجزٌ معلّق بعد البيع');
    }
}
