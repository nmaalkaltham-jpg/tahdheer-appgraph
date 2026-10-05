<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Licensing\Models\LicenseKey;
use App\Domain\Shipping\Models\Carrier;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShippingRate;
use App\Domain\Shipping\Models\ShippingZone;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المنفّذون: حين يقع فعلٌ حقيقيّ وحين ينتظر (§٩ §٢٠ §٢٦).
 *
 * الخيط الجامع بين هذه الاختبارات قاعدةٌ واحدة: **لا حالة نهائيّة بلا فعل
 * وقع**. شحنةٌ بلا عنوان ومفتاحٌ نفد مخزونه حالتا انتظارٍ مشروعتان، وتعليمُ
 * أيٍّ منهما «نُفّذت» يُقفل الطلب ولا يصل الزبون شيء.
 */
class FulfillmentExecutorsTest extends TestCase
{
    use RefreshDatabase;

    private function sellable(array $strategies, array $variantAttrs = []): array
    {
        $n = uniqid();

        $product = Product::create([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => 'منتج',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        $group = ProductGroup::create([
            'slug' => "g-{$n}", 'title' => 'مجموعة', 'product_nature' => 'PHYSICAL', 'status' => 'published',
            'legacy_product_id' => $product->id,
        ]);
        $variant = ProductVariant::create($variantAttrs + [
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

    private function order(Product $product, ProductVariant $variant, ProductOffer $offer): Order
    {
        $customer = Customer::create(['email' => uniqid().'@test.local', 'name' => 'زبون']);
        $order = Order::create([
            'number' => 'TD-'.uniqid(), 'customer_id' => $customer->id, 'email' => $customer->email,
            'status' => 'PAID', 'total_minor' => 5000,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_variant_id' => $variant->id, 'product_offer_id' => $offer->id,
            'title_snapshot' => 'منتج', 'price_snapshot_minor' => 5000, 'quantity' => 1,
        ]);

        return $order->fresh('items');
    }

    private function shippingAddress(Order $order, string $city = 'الرياض'): void
    {
        DB::table('order_addresses')->insert([
            'order_id' => $order->id, 'kind' => 'SHIPPING',
            'recipient_name' => 'مستلم', 'phone' => '0500000000',
            'country_code' => 'SA', 'city' => $city, 'region' => 'الرياض',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function shippingRate(int $price = 2500): void
    {
        $carrier = Carrier::create([
            'code' => 'c-'.uniqid(), 'name' => 'ناقل',
            'tracking_url_template' => 'https://t.example/{tracking}',
        ]);
        $zone = ShippingZone::create([
            'code' => 'z-'.uniqid(), 'name' => 'السعوديّة', 'country_codes' => ['SA'],
        ]);
        ShippingRate::create([
            'shipping_zone_id' => $zone->id, 'carrier_id' => $carrier->id,
            'service_name' => 'قياسي', 'price_minor' => $price, 'max_days' => 4,
        ]);
        InventoryLocation::create([
            'code' => 'L-'.uniqid(), 'name' => 'مستودع', 'kind' => 'WAREHOUSE',
        ]);
    }

    private function dispatch(Order $order)
    {
        return DB::transaction(fn () => $this->app->make(FulfillmentOrchestrator::class)->fulfill($order));
    }

    public function test_a_shipment_is_created_when_address_weight_and_rate_all_exist(): void
    {
        [$p, $v, $o] = $this->sellable(['PHYSICAL_SHIPMENT'], ['weight_grams' => 800]);
        $order = $this->order($p, $v, $o);
        $this->shippingAddress($order);
        $this->shippingRate(2500);

        $units = $this->dispatch($order);

        $this->assertSame('FULFILLED', $units[0]->status);
        $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();
        // «نُفّذت» هنا تعني «سُلّمت إلى مسار الشحن»، لا «وصلت الزبون».
        $this->assertSame('PENDING', $shipment->status);
        $this->assertSame(2500, $shipment->shipping_cost_minor);
        $this->assertSame(800, $shipment->weight_grams);
        $this->assertSame(1, $shipment->items()->count());
    }

    public function test_a_missing_weight_waits_instead_of_guessing_one(): void
    {
        [$p, $v, $o] = $this->sellable(['PHYSICAL_SHIPMENT']); // بلا وزن
        $order = $this->order($p, $v, $o);
        $this->shippingAddress($order);
        $this->shippingRate();

        $units = $this->dispatch($order);

        /*
         * تقديرُ الوزن يُنتج سعرًا خاطئًا يُحصَّل من الزبون أو يُخسر على
         * المتجر — وكلاهما خطأٌ صامت لا يظهر في أيّ تنبيه (§١٥٨).
         */
        $this->assertSame('PENDING', $units[0]->status);
        $this->assertStringContainsString('الوزن يُقاس ولا يُقدَّر', (string) $units[0]->pending_reason);
        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_an_uncovered_destination_waits_and_names_the_city(): void
    {
        [$p, $v, $o] = $this->sellable(['PHYSICAL_SHIPMENT'], ['weight_grams' => 800]);
        $order = $this->order($p, $v, $o);
        $this->shippingAddress($order, 'جازان');
        // لا قاعدة سعرٍ مُعرَّفة إطلاقًا.

        $units = $this->dispatch($order);

        $this->assertSame('PENDING', $units[0]->status);
        $this->assertStringContainsString('جازان', (string) $units[0]->pending_reason);
    }

    public function test_waiting_does_not_count_as_a_failed_attempt(): void
    {
        [$p, $v, $o] = $this->sellable(['PHYSICAL_SHIPMENT'], ['weight_grams' => 800]);
        $order = $this->order($p, $v, $o);

        $units = $this->dispatch($order);

        /*
         * الانتظار ليس فشلًا. عدُّه فشلًا يملأ لوحة التنبيهات بما لا يحتاج
         * إصلاحًا، فيُهمَل ما يحتاجه فعلًا.
         */
        $this->assertSame(0, $units[0]->attempts);
        $this->assertNull($units[0]->last_error);
        $this->assertNotNull($units[0]->pending_reason);
    }

    public function test_re_running_does_not_create_a_second_shipment(): void
    {
        [$p, $v, $o] = $this->sellable(['PHYSICAL_SHIPMENT'], ['weight_grams' => 800]);
        $order = $this->order($p, $v, $o);
        $this->shippingAddress($order);
        $this->shippingRate();

        $this->dispatch($order);
        $this->dispatch($order); // إعادة نداء webhook

        $this->assertSame(1, Shipment::query()->where('order_id', $order->id)->count());
    }

    public function test_a_license_key_is_assigned_from_stock(): void
    {
        [$p, $v, $o] = $this->sellable(['LICENSE_KEY']);
        LicenseKey::create([
            'product_variant_id' => $v->id, 'key_value' => 'KEY-ABC',
            'key_fingerprint' => str_pad(uniqid(), 64, 'k'),
            'source' => 'PRE_GENERATED', 'status' => 'AVAILABLE',
        ]);
        $order = $this->order($p, $v, $o);

        $units = $this->dispatch($order);

        $this->assertSame('FULFILLED', $units[0]->status);
        $key = LicenseKey::query()->firstOrFail();
        $this->assertSame('ASSIGNED', $key->status);
        $this->assertNotNull($key->granted_license_id);
        $this->assertNotNull($key->assigned_at);
    }

    public function test_running_out_of_keys_waits_rather_than_failing(): void
    {
        [$p, $v, $o] = $this->sellable(['LICENSE_KEY']); // لا مفاتيح في المخزون
        $order = $this->order($p, $v, $o);

        $units = $this->dispatch($order);

        // المفاتيح تُحمَّل دفعاتٍ؛ النفاد حالةٌ يحلّها المشغّل لا عطلٌ يُصلَح.
        $this->assertSame('PENDING', $units[0]->status);
        /*
         * السببُ صار يقول العددين — المطلوب والمتاح — لأنّ الكميّة صارت
         * حقيقيّة. والاختبار يثبت الجزء الدائم: أنّ الانتظار مُفسَّر وأنّ
         * المخرج مكتوب.
         */
        $this->assertStringContainsString('المتاح 0', (string) $units[0]->pending_reason);
        $this->assertStringContainsString('ارفع دفعةً جديدة', (string) $units[0]->pending_reason);
        $this->assertSame(0, $units[0]->attempts);
    }

    public function test_two_orders_never_receive_the_same_key(): void
    {
        [$p, $v, $o] = $this->sellable(['LICENSE_KEY']);
        foreach (['K1', 'K2'] as $i => $value) {
            LicenseKey::create([
                'product_variant_id' => $v->id, 'key_value' => $value,
                'key_fingerprint' => str_pad((string) $i.uniqid(), 64, 'm'),
                'source' => 'PRE_GENERATED', 'status' => 'AVAILABLE',
            ]);
        }

        $this->dispatch($this->order($p, $v, $o));
        $this->dispatch($this->order($p, $v, $o));

        $assigned = LicenseKey::query()->where('status', 'ASSIGNED')->get();
        $this->assertCount(2, $assigned);
        // مفتاحان مختلفان لطلبين مختلفين — لا ازدواج.
        $this->assertCount(2, $assigned->pluck('granted_license_id')->unique());
    }

    public function test_a_hybrid_order_delivers_the_digital_half_while_the_physical_half_waits(): void
    {
        [$p, $v, $o] = $this->sellable(['DIGITAL_DOWNLOAD', 'PHYSICAL_SHIPMENT'], ['weight_grams' => 500]);
        $order = $this->order($p, $v, $o);
        // لا عنوان: النصف الماديّ ينتظر.

        $units = $this->dispatch($order)->keyBy('strategy');

        $this->assertSame('FULFILLED', $units['DIGITAL_DOWNLOAD']->status);
        $this->assertNotNull($units['DIGITAL_DOWNLOAD']->entitlement_id);
        $this->assertSame('PENDING', $units['PHYSICAL_SHIPMENT']->status);
        // ولا يُقفل الطلب ما دامت وحدةٌ مفتوحة.
        $this->assertFalse($this->app->make(FulfillmentOrchestrator::class)->isFullyFulfilled($order));
    }

    public function test_a_strategy_with_no_executor_still_waits_with_a_reason(): void
    {
        [$p, $v, $o] = $this->sellable(['APPOINTMENT']);
        $order = $this->order($p, $v, $o);

        $units = $this->dispatch($order);

        // غيابُ منفّذٍ لا يُسقط طلبًا نصفُه سليم — يبقى معلّقًا بسببٍ مكتوب.
        $this->assertSame('PENDING', $units[0]->status);
        $this->assertStringContainsString('موعدًا', (string) $units[0]->pending_reason);
    }
}
