<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\ReturnTransitionService;
use App\Domain\Shipping\Models\Carrier;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\ShipmentTransitionService;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * أفعال منصّة العمليّات (§١٥٥ §٢٦ §٣٣).
 *
 * الاختبارات هنا عن **ما لا يجوز**: القفز في دورة الحياة، وتعديل المخزون
 * بلا سبب، وإعطاء الدعم سلطةَ المستودع. كلّ واحدةٍ منها تمرّ صامتةً لو لم
 * تُمنع، ثمّ تظهر بعد أسابيع بوصفها رقمًا لا يُفسَّر.
 */
class OperationsActionsTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    private function order(): Order
    {
        return Order::create([
            'number' => 'TD-'.uniqid(), 'email' => 'c@test.local',
            'status' => 'PAID', 'total_minor' => 5000,
        ]);
    }

    private function shipment(string $status = 'PENDING'): Shipment
    {
        $carrier = Carrier::create(['code' => 'c-'.uniqid(), 'name' => 'ناقل']);

        return Shipment::create([
            'order_id' => $this->order()->id, 'carrier_id' => $carrier->id,
            'status' => $status, 'idempotency_key' => uniqid(),
            'tracking_number' => in_array($status, ['IN_TRANSIT', 'OUT_FOR_DELIVERY'], true) ? 'TRK1' : null,
        ]);
    }

    private function returnRequest(string $status = 'REQUESTED'): ReturnRequest
    {
        return ReturnRequest::create([
            'number' => 'RMA-'.uniqid(), 'order_id' => $this->order()->id,
            'status' => $status, 'customer_reason' => 'لم يناسبني',
        ]);
    }

    private function variant(): ProductVariant
    {
        $n = uniqid();
        $group = ProductGroup::create([
            'slug' => "g-{$n}", 'title' => 'م', 'product_nature' => 'PHYSICAL', 'status' => 'published',
        ]);

        return ProductVariant::create([
            'product_group_id' => $group->id, 'sku' => "V-{$n}", 'slug' => "v-{$n}", 'status' => 'published',
        ]);
    }

    private function location(): InventoryLocation
    {
        return InventoryLocation::create([
            'code' => 'L-'.uniqid(), 'name' => 'مستودع', 'kind' => 'WAREHOUSE',
        ]);
    }

    // ── ما تبثّه القائمة كي لا تخترع الواجهة خريطةً ثانية ────────────

    /**
     * القائمة تبثّ `allowedNext` لكلّ صفّ.
     *
     * الخريطة تعيش في خدمة النطاق وحدها (قاعدة ٩). وكانت القائمة لا تبثّها،
     * ومسار التحديث وحده يبثّها **بعد** الانتقال — فلم يكن أمام الشاشة إلّا
     * أن تنسخ `TRANSITIONS` إليها لترسم أزرارها. والنسختان تتباعدان عند أوّل
     * تعديل، فيَعِد زرٌّ بانتقالٍ يرفضه الخادم.
     */
    public function test_the_shipment_list_publishes_the_transitions_the_domain_allows(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->shipment('PENDING');

        $row = $this->getJson('/api/v1/admin/operations/shipments')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(
            ShipmentTransitionService::allowedFrom('PENDING'),
            $row['allowedNext'],
            'القائمة يجب أن تنقل قرار النطاق حرفيًّا لا أن تحسبه من جديد.',
        );
        $this->assertNotContains('DELIVERED', $row['allowedNext']);
    }

    /** نهايةٌ بلغت نهايتها لا انتقال منها — وقائمةٌ فارغة تعني «لا زرّ». */
    public function test_a_delivered_shipment_publishes_no_next_transition(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        /*
         * `delivered_at` صراحةً: قيدٌ في القاعدة يرفض «سُلّمت» بلا وقت تسليم
         * (`shipments_delivered_has_time`) — ومُعِدُّ التثبيتة المشترك لا
         * يضبطه لأنّ بقيّة الاختبارات لا تبلغ هذه الحالة.
         */
        Shipment::create([
            'order_id' => $this->order()->id,
            'carrier_id' => Carrier::create(['code' => 'c-'.uniqid(), 'name' => 'ناقل'])->id,
            'status' => 'DELIVERED', 'idempotency_key' => uniqid(),
            'tracking_number' => 'TRK-D', 'delivered_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/operations/shipments')
            ->assertOk()
            ->assertJsonPath('data.0.allowedNext', []);
    }

    public function test_the_returns_list_publishes_the_transitions_the_domain_allows(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->returnRequest('REQUESTED');

        $row = $this->getJson('/api/v1/admin/operations/returns')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(ReturnTransitionService::allowedFrom('REQUESTED'), $row['allowedNext']);
        $this->assertNotContains('REFUNDED', $row['allowedNext']);
    }

    // ── دورة حياة الشحنة (§٢٦) ───────────────────────────────────────

    public function test_a_shipment_cannot_jump_straight_to_delivered(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $shipment = $this->shipment('PENDING');

        /*
         * «سُلّمت» لشحنةٍ لم تخرج تكذب على الزبون وعلى كلّ تقريرٍ يقيس زمن
         * التسليم — وهو رقمٌ تُبنى عليه وعودٌ لاحقة.
         */
        $this->patchJson("/api/v1/admin/operations/shipments/{$shipment->id}", ['status' => 'DELIVERED'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'لا انتقال من «PENDING» إلى «DELIVERED».');
    }

    public function test_a_legal_shipment_transition_is_recorded_with_a_timestamp(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $shipment = $this->shipment('PENDING');

        $this->patchJson("/api/v1/admin/operations/shipments/{$shipment->id}", [
            'status' => 'PICKED_UP', 'trackingNumber' => 'TRK-99',
        ])->assertOk()->assertJsonPath('status', 'PICKED_UP');

        $fresh = $shipment->fresh();
        $this->assertNotNull($fresh->shipped_at);
        $this->assertSame('TRK-99', $fresh->tracking_number);
    }

    public function test_moving_a_shipment_in_transit_without_tracking_is_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $shipment = $this->shipment('PICKED_UP');

        // قيدٌ في المحرّك يفرضه، والرسالة تُترجم ولا تُخفى.
        $this->patchJson("/api/v1/admin/operations/shipments/{$shipment->id}", ['status' => 'IN_TRANSIT'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['trackingNumber']]);
    }

    // ── دورة حياة الإرجاع (§٣٣) ──────────────────────────────────────

    public function test_a_return_cannot_be_refunded_before_the_goods_are_inspected(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $return = $this->returnRequest('REQUESTED');

        /*
         * الفحص يسبق ردّ المال في الخريطة لا في الإرشادات: بغير ذلك يُردّ
         * ثمنُ صندوقٍ لم يصل.
         */
        $this->patchJson("/api/v1/admin/operations/returns/{$return->id}", ['status' => 'RECEIVED'])
            ->assertStatus(422);
    }

    public function test_rejecting_a_return_demands_a_written_reason(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $return = $this->returnRequest('REQUESTED');

        $this->patchJson("/api/v1/admin/operations/returns/{$return->id}", ['status' => 'REJECTED'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['note']]);

        $this->patchJson("/api/v1/admin/operations/returns/{$return->id}", [
            'status' => 'REJECTED', 'note' => 'المنتج مستعمل',
        ])->assertOk();

        $this->assertSame('المنتج مستعمل', $return->fresh()->rejection_reason);
    }

    // ── المخزون (§٢٩) ────────────────────────────────────────────────

    public function test_a_stock_movement_without_a_reason_is_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // الدفتر يُقرأ ليُفهم لا ليُعدّ؛ حركةٌ بلا سبب سطرٌ لا يُفسَّر بعد شهر.
        $this->postJson('/api/v1/admin/operations/inventory/movements', [
            'variantId' => $this->variant()->id,
            'locationId' => $this->location()->id,
            'kind' => 'ADJUSTMENT',
            'quantity' => -3,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
    }

    public function test_a_receipt_moves_stock_and_reports_the_new_level(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/operations/inventory/movements', [
            'variantId' => $this->variant()->id,
            'locationId' => $this->location()->id,
            'kind' => 'RECEIPT',
            'quantity' => 12,
            'reason' => 'شحنة المورّد رقم ٤', 'idempotencyKey' => 'test-'.uniqid(),
        ])->assertOk()
            ->assertJsonPath('onHand', 12)
            ->assertJsonPath('available', 12);
    }

    public function test_a_zero_movement_is_refused_because_it_is_not_a_movement(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/operations/inventory/movements', [
            'variantId' => $this->variant()->id,
            'locationId' => $this->location()->id,
            'kind' => 'ADJUSTMENT',
            'quantity' => 0,
            'reason' => 'لا شيء', 'idempotencyKey' => 'test-'.uniqid(),
        ])->assertStatus(422);
    }

    // ── فصل الصلاحيات ────────────────────────────────────────────────

    public function test_support_may_progress_a_shipment_but_not_touch_stock(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));
        $shipment = $this->shipment('PENDING');

        $this->patchJson("/api/v1/admin/operations/shipments/{$shipment->id}", ['status' => 'LABEL_CREATED'])
            ->assertOk();

        /*
         * هذا هو سبب فصل الصلاحيّتين: تعديل المخزون يقرّر ما يُباع، وإعطاؤه
         * لموظّف الدعم يجعل تصحيح جردٍ خاطئًا بابًا لبيع ما لا يوجد.
         */
        $this->postJson('/api/v1/admin/operations/inventory/movements', [
            'variantId' => $this->variant()->id,
            'locationId' => $this->location()->id,
            'kind' => 'RECEIPT', 'quantity' => 5, 'reason' => 'محاولة', 'idempotencyKey' => 'test-'.uniqid(),
        ])->assertStatus(403);
    }

    public function test_the_product_manager_owns_stock_but_not_shipments(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));

        $this->postJson('/api/v1/admin/operations/inventory/movements', [
            'variantId' => $this->variant()->id,
            'locationId' => $this->location()->id,
            'kind' => 'RECEIPT', 'quantity' => 5, 'reason' => 'جرد افتتاحيّ', 'idempotencyKey' => 'test-'.uniqid(),
        ])->assertOk();

        $shipment = $this->shipment('PENDING');
        $this->patchJson("/api/v1/admin/operations/shipments/{$shipment->id}", ['status' => 'LABEL_CREATED'])
            ->assertStatus(403);
    }

    public function test_every_action_lands_in_the_audit_log(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/operations/inventory/movements', [
            'variantId' => $this->variant()->id,
            'locationId' => $this->location()->id,
            'kind' => 'RECEIPT', 'quantity' => 7, 'reason' => 'شحنة افتتاحيّة', 'idempotencyKey' => 'test-'.uniqid(),
        ])->assertOk();

        // §١٥٥ — بلا أثر، الفرق بين الرفّ والنظام لا يُفسَّر بعد أسبوع.
        $this->assertDatabaseHas('admin_audit_log', ['action' => 'inventory.receipt']);
    }
}
