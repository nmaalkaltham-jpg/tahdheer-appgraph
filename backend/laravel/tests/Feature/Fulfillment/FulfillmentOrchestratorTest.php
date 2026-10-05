<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * منسّق التنفيذ (§٩ §١٠ §٣٤).
 *
 * قبل هذا الصنف كان كلّ بندٍ يُمنح استحقاق تنزيل بلا شرط. الاختبارات هنا
 * تُثبت الفارق: ما يُشحن لا يُمنح تنزيلًا، وما لا نظام له لا يُعلَن منفَّذًا.
 */
class FulfillmentOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private function orchestrator(): FulfillmentOrchestrator
    {
        return $this->app->make(FulfillmentOrchestrator::class);
    }

    /** منتجٌ قديم موصولٌ بعرضٍ يحمل الاستراتيجيّات المطلوبة. */
    private function product(array $strategies, string $nature = 'DIGITAL'): Product
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

        return $product;
    }

    private function paidOrder(Product ...$products): Order
    {
        $customer = Customer::create(['email' => uniqid().'@test.local', 'name' => 'زبون']);

        $order = Order::create([
            'number' => 'TD-'.uniqid(), 'customer_id' => $customer->id,
            'email' => $customer->email, 'status' => 'PAID', 'total_minor' => 5000,
        ]);

        foreach ($products as $p) {
            OrderItem::create([
                'order_id' => $order->id, 'product_id' => $p->id,
                'title_snapshot' => $p->title, 'price_snapshot_minor' => 5000,
            ]);
        }

        return $order->fresh('items');
    }

    private function dispatch(Order $order)
    {
        return DB::transaction(fn () => $this->orchestrator()->fulfill($order));
    }

    public function test_a_digital_item_is_fulfilled_and_carries_an_entitlement(): void
    {
        $order = $this->paidOrder($this->product(['DIGITAL_DOWNLOAD']));

        $units = $this->dispatch($order);

        $this->assertCount(1, $units);
        $this->assertSame('FULFILLED', $units[0]->status);
        $this->assertNotNull($units[0]->entitlement_id);
        $this->assertNotNull($units[0]->fulfilled_at);
        $this->assertSame(1, Entitlement::query()->count());
    }

    public function test_a_shipped_item_is_never_given_download_access(): void
    {
        $order = $this->paidOrder($this->product(['PHYSICAL_SHIPMENT'], 'PHYSICAL'));

        $units = $this->dispatch($order);

        /*
         * الخطأ الذي يمنعه هذا الاختبار: منحُ استحقاقِ تنزيلٍ لصندوقٍ يُشحن.
         * الزبون كان يأخذ رابطًا، والطلب يُقفل، والصندوق لا يُرسل.
         */
        $this->assertSame('PENDING', $units[0]->status);
        $this->assertNull($units[0]->entitlement_id);
        $this->assertSame(0, Entitlement::query()->count());
        $this->assertStringContainsString('لا عنوان شحنٍ', (string) $units[0]->pending_reason);
    }

    public function test_a_hybrid_item_splits_into_two_independent_units(): void
    {
        $order = $this->paidOrder($this->product(['DIGITAL_DOWNLOAD', 'PHYSICAL_SHIPMENT'], 'HYBRID'));

        $units = $this->dispatch($order);

        $this->assertCount(2, $units);
        $byStrategy = $units->keyBy('strategy');
        // النصف الرقميّ يُسلَّم فورًا، والنصف الماديّ ينتظر — ولا ينتظران معًا.
        $this->assertSame('FULFILLED', $byStrategy['DIGITAL_DOWNLOAD']->status);
        $this->assertSame('PENDING', $byStrategy['PHYSICAL_SHIPMENT']->status);
        $this->assertFalse($this->orchestrator()->isFullyFulfilled($order));
    }

    public function test_an_order_is_complete_only_when_every_unit_is_closed(): void
    {
        $order = $this->paidOrder($this->product(['DIGITAL_DOWNLOAD']));
        $this->dispatch($order);

        // «مكتمل» خلاصةٌ تُحسب من الوحدات لا حالةٌ تُعلن على الطلب.
        $this->assertTrue($this->orchestrator()->isFullyFulfilled($order));
    }

    public function test_re_running_never_duplicates_a_unit_or_an_entitlement(): void
    {
        $order = $this->paidOrder($this->product(['DIGITAL_DOWNLOAD']));

        $this->dispatch($order);
        $this->dispatch($order); // إعادة نداء webhook

        // §٢١ — التنفيذ خامل التكرار.
        $this->assertSame(1, FulfillmentUnit::query()->count());
        $this->assertSame(1, Entitlement::query()->count());
    }

    public function test_a_blocked_asset_source_fails_the_unit_instead_of_granting_access(): void
    {
        $product = $this->product(['DIGITAL_DOWNLOAD']);

        $asset = Asset::create([
            'provider' => 'local', 'provider_ref' => 'obj/'.uniqid(),
            'original_name' => 'f.pdf', 'sha256' => str_pad(uniqid(), 64, 'b'),
            'size_bytes' => 10, 'mime' => 'application/pdf',
        ]);
        ProductFile::create(['product_id' => $product->id, 'asset_id' => $asset->id, 'role' => 'main']);
        AssetSource::create([
            'asset_id' => $asset->id, 'provider' => 'GOOGLE_DRIVE', 'delivery_mode' => 'IMPORT_ONCE',
            'external_id' => 'drive-'.uniqid(), 'sync_state' => 'SOURCE_MISSING',
            'last_error' => 'حُذف من Drive',
        ]);

        $units = $this->dispatch($this->paidOrder($product));

        /*
         * الفشل هنا مرئيٌّ في اللوحة. لو مُنح الاستحقاق لَظهر الفشل عند
         * الزبون بعد الشراء، وهو أغلى مكانٍ يُكتشف فيه.
         */
        $this->assertSame('FAILED', $units[0]->status);
        $this->assertSame(0, Entitlement::query()->count());
        $this->assertStringContainsString('SOURCE_MISSING', (string) $units[0]->last_error);
    }

    public function test_an_unresolvable_item_stops_the_order_instead_of_assuming_digital(): void
    {
        $orphan = Product::create([
            'sku' => 'ORPH-'.uniqid(), 'slug' => 'orph-'.uniqid(), 'title' => 'بلا عرض',
            'status' => 'published', 'fulfillment_type' => 'unknown-kind',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/لا تسليم بالظنّ/u');
        $this->dispatch($this->paidOrder($orphan));
    }

    public function test_a_legacy_item_resolves_through_the_bridge_and_records_that(): void
    {
        $product = $this->product(['DIGITAL_DOWNLOAD']);
        $order = $this->paidOrder($product);

        $plan = $this->orchestrator()->plan($order->items->first());

        // البنود الـ١٥٣ القائمة تسبق النموذج الكونيّ؛ المصدر يُسجَّل لأنّ
        // ثقته أقلّ من عرضٍ مربوطٍ باللقطة.
        $this->assertSame('legacy_bridge.offer', $plan->source);
        $this->assertSame(['DIGITAL_DOWNLOAD'], $plan->strategies);
        $this->assertFalse($plan->isMixed());
    }

    public function test_an_order_without_a_customer_fails_the_unit_with_a_reason(): void
    {
        $product = $this->product(['DIGITAL_DOWNLOAD']);
        $order = Order::create([
            'number' => 'TD-'.uniqid(), 'email' => 'guest@test.local',
            'status' => 'PAID', 'total_minor' => 5000,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'title_snapshot' => 'x', 'price_snapshot_minor' => 5000,
        ]);

        $units = $this->dispatch($order->fresh('items'));

        $this->assertSame('FAILED', $units[0]->status);
        $this->assertStringContainsString('لا زبون', (string) $units[0]->last_error);
    }
}
