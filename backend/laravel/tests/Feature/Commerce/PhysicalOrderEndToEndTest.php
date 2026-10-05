<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Domain\Admin\AdminRole;
use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductGroupAuthoring;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Shipping\Models\Carrier;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use App\Domain\Shipping\ShipmentTransitionService;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * منتجٌ ماديّ من الإنشاء إلى الشحنة — الرحلة التي كانت مستحيلة.
 *
 * ─── لماذا لم تكن ممكنة ───
 *
 * ثلاثة أقفال متتالية: `product_nature` بلا كاتب، وبوّابةُ سلّةٍ ترفض كلّ ما
 * ليس رقميًّا، و`order_addresses` بلا كاتبٍ في التطبيق كلّه. فكان منفّذُ
 * الشحن وآلةُ حالات الشحنات مبنيَّين ولا يبلغهما شيء.
 *
 * وهذا الاختبار يمرّ بالأقفال الثلاثة بالترتيب.
 */
class PhysicalOrderEndToEndTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    public function test_a_physical_product_goes_from_creation_to_a_shipment(): void
    {
        $n = bin2hex(random_bytes(4));

        // ١ — الإنشاء بطبيعةٍ ماديّة، عبر مسار الكتابة لا بإدراجٍ مباشر.
        $product = Product::create([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => 'كتاب مطبوع',
            'status' => 'published', 'fulfillment_type' => 'physical',
        ]);
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => 9000, 'currency' => 'SAR']);

        $group = app(ProductGroupAuthoring::class)->create($product, 'PHYSICAL');

        $this->assertSame('PHYSICAL', $group->product_nature);

        $variant = ProductVariant::query()->where('legacy_product_id', $product->id)->firstOrFail();
        $variant->update(['status' => 'published', 'weight_grams' => 600]);
        $group->update(['status' => 'published']);

        $offer = ProductOffer::query()->where('product_variant_id', $variant->id)->firstOrFail();
        $offer->update(['status' => 'active', 'regular_minor' => 9000]);

        // ٢ — السلّة تقبله الآن، وتُعلن أنّه يحتاج عنوانًا.
        $payload = $this->postJson('/api/v1/cart/items', [
            'productId' => $product->id, 'qty' => 2,
        ])->assertOk();

        $this->assertSame(2, $payload->json('items.0.qty'));
        $this->assertSame(18000, $payload->json('totals.grossMinor'), 'القيمة تُضرب في الكميّة');
        $this->assertTrue($payload->json('requirements.requiresShippingAddress'));

        $cart = Cart::query()->firstOrFail();

        // ٣ — الدفع يرفض بلا عنوان، ويقبل معه.
        try {
            app(CheckoutService::class)->createOrder($cart, [
                'name' => 'زبون', 'email' => "{$n}@test.local", 'phone' => '0500000000',
            ]);
            $this->fail('طلبٌ ماديّ بلا عنوان يقف عند الشحن بعد أخذ المال — يجب أن يُرفض قبله.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shippingAddress', $e->errors());
        }

        ['order' => $order] = app(CheckoutService::class)->createOrder($cart, [
            'name' => 'زبون', 'email' => "{$n}@test.local", 'phone' => '0500000000',
            'shippingAddress' => [
                'city' => 'الرياض', 'district' => 'العليا', 'street' => 'طريق الملك فهد',
                'recipientName' => 'مستلِم آخر',
            ],
        ]);

        $address = DB::table('order_addresses')->where('order_id', $order->id)->first();
        $this->assertNotNull($address, 'العنوان يُكتب في معاملة الطلب');
        $this->assertSame('الرياض', $address->city);
        $this->assertSame('مستلِم آخر', $address->recipient_name, 'المستلِم قد يكون غير المشتري');
        $this->assertNull($address->postal_code, 'ما لم يُكتب يبقى فارغًا ولا يُلفَّق');

        $this->assertSame(2, (int) DB::table('order_items')->where('order_id', $order->id)->value('quantity'));
        $this->assertSame(18000, (int) $order->total_minor);

        // ٤ — بلا قاعدة شحنٍ تبقى الوحدة معلّقةً بسببٍ مكتوب لا بفشلٍ غامض.
        $order->update(['status' => 'PAID', 'paid_at' => now()]);
        app(FulfillmentOrchestrator::class)->fulfill($order->fresh('items'));

        $unit = FulfillmentUnit::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('PHYSICAL_SHIPMENT', $unit->strategy);
        $this->assertSame('PENDING', $unit->status);
        $this->assertStringContainsString('قاعدة شحن', (string) $unit->pending_reason);
        $this->assertNull($unit->last_error, 'الانتظار ليس فشلًا — لا يُسجَّل خطأ ولا يُزاد عدّاد المحاولات');

        // ٥ — وبها تُنشأ الشحنة فعلًا.
        $carrier = Carrier::create(['code' => 'c-'.$n, 'name' => 'ناقل']);
        $zone = ShippingZone::create(['code' => 'z-'.$n, 'name' => 'السعوديّة', 'country_codes' => ['SA']]);
        ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'carrier_id' => $carrier->id,
            'service_name' => 'قياسي', 'price_minor' => 2500, 'max_days' => 4,
        ]);

        app(FulfillmentOrchestrator::class)->fulfill($order->fresh('items'));
        $unit->refresh();

        $shipment = Shipment::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($shipment, 'العنوان موجود، فالشحنة تُنشأ ولا تنتظر');
        $this->assertSame($unit->id, (int) $shipment->fulfillment_unit_id);

        // ٦ — والطلب لا يُغلق قبل أن تصل الشحنة.
        $this->assertSame('PAID', $order->fresh()->status,
            'الشحنة أُنشئت ولم تصل — والطلب عملٌ مفتوح حتّى تصل');
    }

    public function test_a_digital_cart_is_never_asked_for_an_address(): void
    {
        $n = bin2hex(random_bytes(4));

        $product = Product::create([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => 'ملفّ',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => 5000, 'currency' => 'SAR']);
        $group = app(ProductGroupAuthoring::class)->create($product, 'DIGITAL');
        $group->update(['status' => 'published']);
        ProductVariant::query()->where('legacy_product_id', $product->id)->update(['status' => 'published']);

        $this->postJson('/api/v1/cart/items', ['productId' => $product->id])
            ->assertOk()
            ->assertJsonPath('requirements.requiresShippingAddress', false);

        $cart = Cart::query()->firstOrFail();

        /* §٣٢ — جمعُ ما لا يلزم مسألةُ خصوصيّة قبل أن يكون مسألة تحويل. */
        ['order' => $order] = app(CheckoutService::class)->createOrder($cart, [
            'name' => 'زبون', 'email' => "{$n}@test.local", 'phone' => '0500000000',
        ]);

        $this->assertSame(0, DB::table('order_addresses')->where('order_id', $order->id)->count());
    }

    public function test_the_snapshot_column_stays_a_unit_price_so_revenue_is_not_squared(): void
    {
        $n = bin2hex(random_bytes(4));

        $product = Product::create([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => 'كتاب',
            'status' => 'published', 'fulfillment_type' => 'physical',
        ]);
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => 9000, 'currency' => 'SAR']);
        $group = app(ProductGroupAuthoring::class)->create($product, 'PHYSICAL');
        $group->update(['status' => 'published']);
        ProductVariant::query()->where('legacy_product_id', $product->id)
            ->update(['status' => 'published', 'weight_grams' => 600]);
        $variant = ProductVariant::query()->where('legacy_product_id', $product->id)->firstOrFail();
        ProductOffer::query()->where('product_variant_id', $variant->id)
            ->update(['status' => 'active', 'regular_minor' => 9000]);

        $this->postJson('/api/v1/cart/items', ['productId' => $product->id, 'qty' => 3])->assertOk();

        ['order' => $order] = app(CheckoutService::class)->createOrder(Cart::query()->firstOrFail(), [
            'name' => 'زبون', 'email' => "{$n}@test.local", 'phone' => '0500000000',
            'shippingAddress' => ['city' => 'الرياض'],
        ]);

        $row = DB::table('order_items')->where('order_id', $order->id)->first();

        /*
         * `price_snapshot_minor` سعرُ وحدةٍ منذ أوّل ترحيل، و`quantity` بجانبه.
         * وتُقرأ اللوحة `sum(price * quantity)` — فلو حمل العمود قيمة السطر
         * لَصار الإيراد مضروبًا في الكميّة مرّتين: ثلاثُ نسخٍ بتسعين تُعلَن
         * ثمانمئةً وعشرًا وقد حُصّل مئتان وسبعون.
         */
        $this->assertSame(9000, (int) $row->price_snapshot_minor, 'العمود سعرُ وحدة لا قيمةُ سطر');
        $this->assertSame(3, (int) $row->quantity);
        $this->assertSame(27000, (int) $order->total_minor, 'والإجمالي قيمةُ السطر كاملةً');
        $this->assertSame(
            27000,
            (int) DB::table('order_items')->where('order_id', $order->id)
                ->selectRaw('sum(price_snapshot_minor * quantity) as r')->value('r'),
            'قراءةُ اللوحة تساوي ما حُصّل بالضبط',
        );
    }

    public function test_delivering_the_shipment_closes_the_unit_and_the_order(): void
    {
        $n = bin2hex(random_bytes(4));

        $product = Product::create([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => 'كتاب',
            'status' => 'published', 'fulfillment_type' => 'physical',
        ]);
        ProductPrice::create(['product_id' => $product->id, 'regular_minor' => 9000, 'currency' => 'SAR']);
        $group = app(ProductGroupAuthoring::class)->create($product, 'PHYSICAL');
        $group->update(['status' => 'published']);
        ProductVariant::query()->where('legacy_product_id', $product->id)
            ->update(['status' => 'published', 'weight_grams' => 600]);
        $variant = ProductVariant::query()->where('legacy_product_id', $product->id)->firstOrFail();
        ProductOffer::query()->where('product_variant_id', $variant->id)
            ->update(['status' => 'active', 'regular_minor' => 9000]);

        $carrier = Carrier::create(['code' => 'c-'.$n, 'name' => 'ناقل']);
        $zone = ShippingZone::create(['code' => 'z-'.$n, 'name' => 'السعوديّة', 'country_codes' => ['SA']]);
        ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'carrier_id' => $carrier->id,
            'service_name' => 'قياسي', 'price_minor' => 2500, 'max_days' => 4,
        ]);

        $this->postJson('/api/v1/cart/items', ['productId' => $product->id])->assertOk();
        ['order' => $order] = app(CheckoutService::class)->createOrder(Cart::query()->firstOrFail(), [
            'name' => 'زبون', 'email' => "{$n}@test.local", 'phone' => '0500000000',
            'shippingAddress' => ['city' => 'الرياض'],
        ]);

        $order->update(['status' => 'PAID', 'paid_at' => now()]);
        app(FulfillmentOrchestrator::class)->fulfill($order->fresh('items'));

        $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();

        /*
         * الشحنة أُنشئت والوحدة `FULFILLED` — ومع ذلك الطلب ليس مكتملًا:
         * «مكتمل» تعني **وصل** لا «سُلِّم للناقل». وبلا هذا التمييز كان كلّ
         * طلبٍ ماديّ يُعلَن مكتملًا لحظة تثبيت الدفع، ويموت مسارُ «التسليم
         * يُغلق الطلب» كلُّه.
         */
        $this->assertFalse(app(FulfillmentOrchestrator::class)->closeIfDelivered($order->fresh()));
        $this->assertSame('PAID', $order->fresh()->status);

        $transitions = app(ShipmentTransitionService::class);
        $actor = $this->makeAdmin(AdminRole::OrderManager);

        /* رقم التتبّع يُطلب عند المغادرة — الشحنة في الطريق يتابعها الزبون. */
        foreach (['LABEL_CREATED', 'PICKED_UP', 'IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED'] as $to) {
            $transitions->transition($shipment->id, $to, 'TRK-'.$n, $actor);
        }

        $this->assertSame('DELIVERED', $shipment->fresh()->status);
        $this->assertSame('COMPLETED', $order->fresh()->status,
            'وصولُ الطرد يُغلق وحدتَه ويُغلق الطلب — وإلّا بقي عملًا مفتوحًا إلى الأبد');
    }
}
