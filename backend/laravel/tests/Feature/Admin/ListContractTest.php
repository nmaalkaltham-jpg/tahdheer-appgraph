<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Shipping\Models\Shipment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * عقد القوائم المشترك — بعد توحيد الجداول على `DataTable`.
 *
 * ─── ما تُثبته هذه الحزمة ───
 *
 * أربع قوائم صارت تقرأ عقدًا واحدًا: بحثٌ وفرزٌ بقائمةٍ بيضاء وتصفّحٌ
 * بإجماليٍّ معلَن. والاختبارات هنا تحرس ثلاثة أشياء تحديدًا:
 *
 *  ١ — عمودُ فرزٍ خارج القائمة البيضاء يُرفض. تمريرُ الاسم كما ورد يفتح
 *      الباب لعمودٍ لا وجود له (خطأ خادمٍ يُعرض للمستعمل) ولعمودٍ لا يجوز
 *      الفرز به.
 *
 *  ٢ — لا صفَّ يتكرّر بين صفحتين. الفرز بعمودٍ متكرّر القيم بلا قاطعٍ ثانويّ
 *      ترتيبٌ **غير محدَّد** في PostgreSQL: يظهر الصفّ نفسه مرّتين ويختفي
 *      غيره — ولا يُكتشف ذلك إلّا بالعدّ.
 *
 *  ٣ — الإجماليّ يُعلَن. القوائم القديمة كانت تقطع عند ٢٠٠ أو ٣٠٠ صفٍّ بلا
 *      أيّ إشارة، فيُقرأ الجدول على أنّه الكلّ.
 */
class ListContractTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function product(string $sku, string $title, int $regularMinor): Product
    {
        $product = Product::create([
            'sku' => $sku,
            'slug' => strtolower($sku),
            'title' => $title,
            'status' => 'draft',
        ]);

        DB::table('product_prices')->insert([
            'product_id' => $product->id,
            'regular_minor' => $regularMinor,
            'currency' => 'SAR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $product;
    }

    private function stockRow(int $onHand, int $reserved, ?int $reorderPoint = null): StockLevel
    {
        $n = uniqid();
        $group = ProductGroup::create([
            'slug' => "g-{$n}", 'title' => "مجموعة {$n}", 'product_nature' => 'PHYSICAL', 'status' => 'published',
        ]);
        $variant = ProductVariant::create([
            'product_group_id' => $group->id, 'sku' => "V-{$n}", 'slug' => "v-{$n}", 'status' => 'published',
        ]);
        $location = InventoryLocation::create(['code' => "L-{$n}", 'name' => 'مستودع', 'kind' => 'WAREHOUSE']);

        return StockLevel::create([
            'product_variant_id' => $variant->id,
            'inventory_location_id' => $location->id,
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'reorder_point' => $reorderPoint,
        ]);
    }

    private function shipment(string $status, ?int $costMinor = null): Shipment
    {
        $order = Order::create([
            'number' => 'TD-'.uniqid(), 'email' => 'c@test.local',
            'status' => 'PAID', 'total_minor' => 5000,
        ]);

        return Shipment::create([
            'order_id' => $order->id,
            'status' => $status,
            'tracking_number' => 'TRK-'.uniqid(),
            'shipping_cost_minor' => $costMinor,
            'idempotency_key' => uniqid(),
        ]);
    }

    // ── المنتجات ─────────────────────────────────────────────────────────

    public function test_products_sort_by_price_without_duplicating_rows(): void
    {
        $this->product('P-A', 'ألف', 10000);
        $this->product('P-B', 'باء', 90000);
        $this->product('P-C', 'جيم', 50000);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $body = $this->getJson('/api/v1/admin/products?sort=price&dir=desc')->assertOk()->json();

        /*
         * السعر في جدولٍ مرتبط. الوصلة كانت ستُضاعف الصفّ إن وُجد له أكثر من
         * سعر، فيظهر المنتج مرّتين — ولذلك يُفرَز باستعلامٍ مترابط.
         */
        $this->assertSame('P-B', $body['data'][0]['sku']);
        $this->assertSame('P-A', $body['data'][2]['sku']);
        $this->assertSame(3, $body['meta']['total']);
        $this->assertCount(3, $body['data']);
    }

    public function test_products_refuse_an_unknown_sort_column(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/products?sort=order_key_hash')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['sort']]);
    }

    public function test_product_status_counts_ignore_the_status_filter(): void
    {
        $this->product('P-D1', 'مسوّدة', 1000);
        $published = $this->product('P-P1', 'منشور', 1000);
        $published->update(['status' => 'published']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/products?status=published')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('statusCounts.published', 1)
            ->assertJsonPath('statusCounts.draft', 1);
    }

    // ── العملاء ──────────────────────────────────────────────────────────

    /**
     * الفرز بعدد الطلبات — وهو عمودٌ مشتقّ لا حقيقيّ.
     *
     * يُفرَز بالاسم المستعار للاستعلام الفرعيّ، فلا وصلةَ تُضاعف الصفوف.
     */
    public function test_customers_sort_by_order_count(): void
    {
        $busy = Customer::create(['email' => 'busy@test.local', 'name' => 'كثيرة الطلبات']);
        $quiet = Customer::create(['email' => 'quiet@test.local', 'name' => 'بلا طلبات']);

        foreach (range(1, 3) as $i) {
            Order::create([
                'number' => "TD-C{$i}-".uniqid(), 'email' => $busy->email, 'customer_id' => $busy->id,
                'status' => 'PAID', 'total_minor' => 1000,
            ]);
        }

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $body = $this->getJson('/api/v1/admin/customers?sort=orders&dir=desc')->assertOk()->json();

        $this->assertSame($busy->id, $body['data'][0]['id']);
        $this->assertSame(3, $body['data'][0]['ordersCount']);
        $this->assertSame($quiet->id, $body['data'][1]['id']);
    }

    public function test_customers_refuse_an_unknown_sort_column(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/customers?sort=password')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['sort']]);
    }

    // ── الشحنات ──────────────────────────────────────────────────────────

    /**
     * الإجماليّ يُعلَن — لا قطعٌ صامت عند مئتين.
     *
     * كان `limit(200)` بلا إجماليّ: يُقرأ الجدول على أنّه كلّ الشحنات، فيبحث
     * المشغّل عن شحنةٍ موجودة ولا يجدها ويستنتج أنّها لم تُنشأ — استنتاجٌ
     * خاطئ يقود إلى إنشاءٍ مكرّر.
     */
    public function test_shipments_are_paginated_with_a_declared_total(): void
    {
        foreach (range(1, 5) as $i) {
            $this->shipment('IN_TRANSIT', $i * 1000);
        }

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/operations/shipments?perPage=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.lastPage', 3);
    }

    public function test_shipments_sorting_does_not_repeat_a_row_across_pages(): void
    {
        foreach (range(1, 6) as $i) {
            /* الحالة نفسها للجميع: عمودٌ متكرّر القيم — أسوأ حالةٍ للفرز. */
            $this->shipment('IN_TRANSIT');
        }

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $ids = [];
        foreach ([1, 2, 3] as $page) {
            $body = $this->getJson("/api/v1/admin/operations/shipments?sort=status&dir=asc&perPage=2&page={$page}")->json();
            $ids = array_merge($ids, array_column($body['data'], 'id'));
        }

        $this->assertCount(6, $ids);
        $this->assertSame(6, count(array_unique($ids)), 'صفٌّ تكرّر بين صفحتين.');
    }

    public function test_shipments_refuse_an_unknown_sort_column(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/operations/shipments?sort=idempotency_key')
            ->assertStatus(422);
    }

    // ── المخزون ──────────────────────────────────────────────────────────

    /**
     * «يحتاج إعادة طلب» يُعدّ على المجموعة كلّها لا على الصفحة.
     *
     * كان يُحسب في الواجهة من الصفوف المحمّلة فيقول «١» وفي المخزن ثلاثة —
     * وهو الرقم الذي يُبنى عليه أمر شراء.
     */
    public function test_inventory_reorder_count_spans_the_whole_set_not_the_page(): void
    {
        $this->stockRow(onHand: 1, reserved: 0, reorderPoint: 5);
        $this->stockRow(onHand: 2, reserved: 0, reorderPoint: 5);
        $this->stockRow(onHand: 3, reserved: 0, reorderPoint: 5);
        $this->stockRow(onHand: 900, reserved: 0, reorderPoint: 5);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/operations/inventory?perPage=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('needsReorder', 3);
    }

    /** الافتراض يبقى «ما يقترب من النفاد أوّلًا». */
    public function test_inventory_defaults_to_lowest_available_first(): void
    {
        $this->stockRow(onHand: 100, reserved: 0);
        $this->stockRow(onHand: 2, reserved: 0);
        $this->stockRow(onHand: 50, reserved: 0);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $body = $this->getJson('/api/v1/admin/operations/inventory')->assertOk()->json();

        $this->assertSame(2, $body['data'][0]['available']);
        $this->assertSame(100, $body['data'][2]['available']);
    }

    public function test_inventory_sorts_by_an_allowed_column(): void
    {
        $this->stockRow(onHand: 10, reserved: 0);
        $this->stockRow(onHand: 80, reserved: 0);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $body = $this->getJson('/api/v1/admin/operations/inventory?sort=onHand&dir=desc')->assertOk()->json();

        $this->assertSame(80, $body['data'][0]['onHand']);
    }

    public function test_inventory_refuses_an_unknown_sort_column(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/operations/inventory?sort=id')
            ->assertStatus(422);
    }
}
