<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Assets\Exceptions\SourceNotConfigured;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Assets\SyncGovernor;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Operations\Jobs\RetryFulfillmentUnitJob;
use App\Domain\Operations\Jobs\VerifyAssetSourceJob;
use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Operations\OperationLedger;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\ShipmentTransitionService;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * تصليب أفعال العمليّات: التكرار والصلاحيات والحالات النهائيّة.
 *
 * كلّ اختبارٍ هنا يصف مسارًا **يقع فعلًا في الإنتاج**: نقرةٌ مزدوجة، وإعادةُ
 * إرسالٍ بعد انقطاع، وتسليمٌ مكرّر من الطابور، ودورٌ لا يملك الصلاحية.
 * نجاحُ المسار السعيد وحده لا يقول شيئًا عن أيٍّ منها.
 */
class OperationsHardeningTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    private function order(): Order
    {
        return Order::create([
            'number' => 'TD-'.uniqid(), 'email' => 'c@test.local',
            'status' => 'PAID', 'total_minor' => 5000,
        ]);
    }

    private function unit(string $status = 'PENDING'): FulfillmentUnit
    {
        $n = uniqid();
        $order = $this->order();
        $product = Product::create([
            'sku' => "S-{$n}", 'slug' => "p-{$n}", 'title' => 'م',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'title_snapshot' => 'م', 'price_snapshot_minor' => 5000,
        ]);

        return FulfillmentUnit::create([
            'order_id' => $order->id, 'order_item_id' => $item->id,
            'strategy' => 'DIGITAL_DOWNLOAD', 'status' => $status,
            'pending_reason' => $status === 'PENDING' ? 'بانتظار' : null,
            'fulfilled_at' => $status === 'FULFILLED' ? now() : null,
            'idempotency_key' => "item:{$item->id}:DIGITAL_DOWNLOAD",
        ]);
    }

    private function source(): AssetSource
    {
        $asset = Asset::create([
            'provider' => 'local', 'provider_ref' => 'obj/'.uniqid(),
            'original_name' => 'f.pdf', 'sha256' => str_pad(uniqid(), 64, 'a'),
            'size_bytes' => 10, 'mime' => 'application/pdf',
        ]);

        return AssetSource::create([
            'asset_id' => $asset->id, 'provider' => 'INTERNAL_OBJECT_STORAGE',
            'delivery_mode' => 'IMPORT_ONCE', 'external_id' => 'obj/'.uniqid(),
            'sync_state' => 'CURRENT',
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
        return InventoryLocation::create(['code' => 'L-'.uniqid(), 'name' => 'م', 'kind' => 'WAREHOUSE']);
    }

    // ── إعادة محاولة التنفيذ ─────────────────────────────────────────────

    public function test_retry_enqueues_and_does_not_execute_inline(): void
    {
        Queue::fake();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $unit = $this->unit();

        $this->postJson("/api/v1/admin/operations/fulfillment/{$unit->id}/retry")
            ->assertStatus(202)
            ->assertJsonPath('duplicate', false)
            ->assertJsonStructure(['operationId', 'correlationId']);

        /*
         * لا نداء مزوّدٍ داخل طلب HTTP: الفعل أُرسل إلى الطابور. ربطُ زمن
         * استجابة اللوحة بطرفٍ ثالث هو ما كان يُنتج النقرة المزدوجة أصلًا.
         */
        Queue::assertPushed(RetryFulfillmentUnitJob::class, 1);
    }

    public function test_a_duplicate_retry_reuses_the_operation_and_enqueues_nothing(): void
    {
        Queue::fake();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $unit = $this->unit();

        $first = $this->postJson("/api/v1/admin/operations/fulfillment/{$unit->id}/retry")->assertStatus(202);
        $second = $this->postJson("/api/v1/admin/operations/fulfillment/{$unit->id}/retry")->assertStatus(200);

        // المفتاح نفسه ⇦ العمليّة نفسها، ولا مهمّة ثانية.
        $this->assertSame($first->json('operationId'), $second->json('operationId'));
        $this->assertTrue($second->json('duplicate'));
        Queue::assertPushed(RetryFulfillmentUnitJob::class, 1);
        $this->assertSame(1, OperationRecord::query()->where('kind', 'fulfillment_retry')->count());
    }

    public function test_a_queue_redelivery_after_success_executes_nothing(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $unit = $this->unit();

        $record = OperationRecord::create([
            'idempotency_key' => 'fulfillment_retry:'.$unit->id.':0',
            'kind' => 'fulfillment_retry', 'subject_type' => 'fulfillment_unit', 'subject_id' => $unit->id,
            'status' => 'SUCCEEDED', 'result' => ['status' => 'FULFILLED'], 'completed_at' => now(),
        ]);

        // التسليم المكرّر أمرٌ عاديّ في الطوابير لا استثنائيّ.
        (new RetryFulfillmentUnitJob($record->id, $unit->id))->handle(
            $this->app->make(OperationLedger::class),
            $this->app->make(FulfillmentOrchestrator::class),
            $this->app->make(OutboxWriter::class),
        );

        $this->assertSame(0, (int) $record->fresh()->attempts);
        $this->assertSame('SUCCEEDED', $record->fresh()->status);
    }

    public function test_retry_on_a_terminal_unit_is_refused(): void
    {
        Queue::fake();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $unit = $this->unit('FULFILLED');

        $this->postJson("/api/v1/admin/operations/fulfillment/{$unit->id}/retry")->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_retry_on_a_missing_unit_is_404(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->postJson('/api/v1/admin/operations/fulfillment/999999/retry')->assertStatus(404);
    }

    public function test_retry_requires_operations_permission(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));
        $unit = $this->unit();

        $this->postJson("/api/v1/admin/operations/fulfillment/{$unit->id}/retry")->assertStatus(403);
    }

    // ── التحقّق من المصدر ────────────────────────────────────────────────

    public function test_verification_is_queued_not_executed_in_the_controller(): void
    {
        Queue::fake();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $source = $this->source();

        $this->postJson("/api/v1/admin/operations/asset-sources/{$source->id}/verify")
            ->assertStatus(202);

        Queue::assertPushed(VerifyAssetSourceJob::class, 1);
    }

    public function test_concurrent_verification_requests_collapse_into_one(): void
    {
        Queue::fake();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $source = $this->source();

        $this->postJson("/api/v1/admin/operations/asset-sources/{$source->id}/verify")->assertStatus(202);
        $this->postJson("/api/v1/admin/operations/asset-sources/{$source->id}/verify")->assertStatus(200);

        // تجميعٌ لا صفّ: تحقّقان متطابقان يُرهقان المزوّد ويستنزفان الحصّة.
        Queue::assertPushed(VerifyAssetSourceJob::class, 1);
    }

    public function test_verification_on_a_missing_source_is_404(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->postJson('/api/v1/admin/operations/asset-sources/999999/verify')->assertStatus(404);
    }

    public function test_an_unconfigured_provider_is_classified_as_authentication_not_missing(): void
    {
        $source = $this->source();
        $record = OperationRecord::create([
            'idempotency_key' => 'asset_verify:'.$source->id.':x',
            'kind' => 'asset_verify', 'subject_type' => 'asset_source', 'subject_id' => $source->id,
            'status' => 'RESERVED',
        ]);

        $governor = new class extends SyncGovernor
        {
            public function __construct() {}

            public function refresh(AssetSource $source, string $trigger = 'scheduled'): AssetSource
            {
                throw new SourceNotConfigured('GOOGLE_DRIVE', 'لا رمز وصول');
            }
        };

        (new VerifyAssetSourceJob($record->id, $source->id))->handle(
            $this->app->make(OperationLedger::class),
            $governor,
            $this->app->make(OutboxWriter::class),
        );

        /*
         * ٤٠١ لا تُخفَّض أبدًا إلى «ملفّ مفقود». الخلط كان سيطلب استبدال
         * مصادر سليمة لأنّ أحدًا لم يضبط رمزًا.
         */
        $this->assertSame('FAILED', $record->fresh()->status);
        $this->assertSame('AUTHENTICATION', $record->fresh()->failure_class);
        $this->assertSame('CURRENT', $source->fresh()->sync_state);
    }

    // ── المخزون ──────────────────────────────────────────────────────────

    public function test_the_same_inventory_key_twice_moves_stock_once(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $variant = $this->variant();
        $location = $this->location();
        $key = 'ops-'.uniqid();

        $payload = [
            'variantId' => $variant->id, 'locationId' => $location->id,
            'kind' => 'RECEIPT', 'quantity' => 10, 'reason' => 'شحنة', 'idempotencyKey' => $key,
        ];

        $this->postJson('/api/v1/admin/operations/inventory/movements', $payload)
            ->assertOk()->assertJsonPath('onHand', 10)->assertJsonPath('duplicate', false);

        $this->postJson('/api/v1/admin/operations/inventory/movements', $payload)
            ->assertOk()->assertJsonPath('onHand', 10)->assertJsonPath('duplicate', true);

        /*
         * جردٌ يتضاعف بإعادة إرسالٍ عارضة هو أسوأ ما يقع لمخزون: يُباع ما لا
         * يوجد ولا يعرف أحدٌ من أين جاء الفرق.
         */
        $this->assertSame(10, (int) DB::table('stock_levels')
            ->where('product_variant_id', $variant->id)->value('on_hand'));
        $this->assertSame(1, DB::table('stock_ledger')
            ->where('product_variant_id', $variant->id)->where('movement', 'RECEIPT')->count());
    }

    public function test_the_inventory_list_carries_the_identifiers_a_movement_needs(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $variant = $this->variant();
        $location = $this->location();

        $this->postJson('/api/v1/admin/operations/inventory/movements', [
            'variantId' => $variant->id, 'locationId' => $location->id,
            'kind' => 'RECEIPT', 'quantity' => 3, 'reason' => 'شحنة',
            'idempotencyKey' => 'ops-'.uniqid(),
        ])->assertOk();

        /*
         * القائمة كانت تعرض الاسم والرمز دون المعرّفين، فكانت اللوحة تُظهر
         * جردًا لا تستطيع تصحيحه. هذان الحقلان عقدٌ تعتمد عليه واجهة الحركة،
         * وحذفُهما لا يكسر شيئًا في الخادم — يكسر زرًّا في مكانٍ آخر صامتًا.
         */
        $this->getJson('/api/v1/admin/operations/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.variantId', $variant->id)
            ->assertJsonPath('data.0.locationId', $location->id);
    }

    public function test_an_inventory_movement_without_an_idempotency_key_is_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson('/api/v1/admin/operations/inventory/movements', [
            'variantId' => $this->variant()->id, 'locationId' => $this->location()->id,
            'kind' => 'RECEIPT', 'quantity' => 5, 'reason' => 'شحنة',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['idempotencyKey']]);
    }

    public function test_the_same_inventory_key_with_a_different_body_is_a_conflict(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $variant = $this->variant();
        $location = $this->location();
        $key = 'ops-'.uniqid();

        $base = [
            'variantId' => $variant->id, 'locationId' => $location->id,
            'kind' => 'RECEIPT', 'reason' => 'شحنة', 'idempotencyKey' => $key,
        ];

        $this->postJson('/api/v1/admin/operations/inventory/movements', $base + ['quantity' => 10])
            ->assertOk();

        /*
         * لولا البصمة لتلقّى هذا الطلب «تمّ» ونتيجةَ العشرة — أي أنّ من طلب
         * خمسةً يُقال له نُقلت، والذي نُقل عشرة قبل ثانية.
         */
        $this->postJson('/api/v1/admin/operations/inventory/movements', $base + ['quantity' => 5])
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame(10, (int) DB::table('stock_levels')
            ->where('product_variant_id', $variant->id)->value('on_hand'));
    }

    public function test_an_uncertain_operation_is_not_retried_by_the_queue(): void
    {
        $unit = $this->unit();
        $record = OperationRecord::create([
            'idempotency_key' => 'fulfillment_retry:'.$unit->id.':unc',
            'kind' => 'fulfillment_retry', 'subject_type' => 'fulfillment_unit',
            'subject_id' => $unit->id, 'status' => 'UNCERTAIN',
            'last_error' => 'انتهت المهلة',
        ]);

        $orchestrator = new class extends FulfillmentOrchestrator
        {
            public int $calls = 0;

            public function __construct() {}

            public function fulfill(Order $order): Collection
            {
                $this->calls++;

                return collect();
            }
        };

        (new RetryFulfillmentUnitJob($record->id, $unit->id))->handle(
            $this->app->make(OperationLedger::class),
            $orchestrator,
            $this->app->make(OutboxWriter::class),
        );

        /*
         * المجهولة تنتظر التوفيق لا الإعادة. تنفيذُها هنا هو بالضبط التنفيذ
         * المزدوج الذي وُجد سجلّ العمليّات ليمنعه.
         */
        $this->assertSame(0, $orchestrator->calls);
        $this->assertSame('UNCERTAIN', $record->fresh()->status);
    }

    public function test_a_second_worker_does_not_execute_while_the_lease_is_held(): void
    {
        $unit = $this->unit();
        $ledger = $this->app->make(OperationLedger::class);
        [$record] = $ledger->reserve(
            'fulfillment_retry:'.$unit->id.':lease', 'fulfillment_retry', 'fulfillment_unit', $unit->id,
        );
        $ledger->acquire($record, 'worker-a');

        $orchestrator = new class extends FulfillmentOrchestrator
        {
            public int $calls = 0;

            public function __construct() {}

            public function fulfill(Order $order): Collection
            {
                $this->calls++;

                return collect();
            }
        };

        (new RetryFulfillmentUnitJob($record->id, $unit->id))->handle(
            $ledger, $orchestrator, $this->app->make(OutboxWriter::class),
        );

        // تسليمٌ مكرّر بينما عاملٌ حيٌّ يعمل: يمضي ولا ينفّذ بجانبه.
        $this->assertSame(0, $orchestrator->calls);
    }

    public function test_an_inventory_movement_emits_an_availability_event(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $variant = $this->variant();

        $this->postJson('/api/v1/admin/operations/inventory/movements', [
            'variantId' => $variant->id, 'locationId' => $this->location()->id,
            'kind' => 'RECEIPT', 'quantity' => 4, 'reason' => 'شحنة',
            'idempotencyKey' => 'ops-'.uniqid(),
        ])->assertOk();

        /*
         * واجهة المتجر وفهرس البحث يشتركان في هذا الحدث. ربطُهما بذاكرة
         * وحدةِ تحكّم يعني أنّ مسارًا واحدًا يُنسى فيبقى منتجٌ معروضًا وقد نفد.
         */
        $this->assertSame(1, DB::table('outbox_events')
            ->where('event_type', 'inventory.availability.changed')
            ->where('aggregate_id', (string) $variant->id)->count());
    }

    // ── الشحن والإرجاع: ٤٠٤ والحالة النهائيّة ────────────────────────────

    public function test_a_shipment_transition_on_a_missing_shipment_is_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->patchJson('/api/v1/admin/operations/shipments/999999', ['status' => 'LABEL_CREATED'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['shipment']]);
    }

    public function test_a_terminal_shipment_refuses_further_transitions(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $shipment = Shipment::create([
            'order_id' => $this->order()->id, 'status' => 'DELIVERED',
            'tracking_number' => 'T1', 'delivered_at' => now(), 'idempotency_key' => uniqid(),
        ]);

        $this->patchJson("/api/v1/admin/operations/shipments/{$shipment->id}", ['status' => 'IN_TRANSIT'])
            ->assertStatus(422);
    }

    public function test_a_closed_return_refuses_further_transitions(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $return = ReturnRequest::create([
            'number' => 'RMA-'.uniqid(), 'order_id' => $this->order()->id,
            'status' => 'CLOSED', 'customer_reason' => 'x', 'closed_at' => now(),
        ]);

        $this->patchJson("/api/v1/admin/operations/returns/{$return->id}", ['status' => 'APPROVED'])
            ->assertStatus(422);
    }

    public function test_a_shipment_transition_response_carries_the_allowed_next_states(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $shipment = Shipment::create([
            'order_id' => $this->order()->id, 'status' => 'PENDING', 'idempotency_key' => uniqid(),
        ]);

        // الواجهة تقرأ المسموح من الخادم ولا تخترع خريطةً ثانية.
        // والبنية وحدها تمرّ على مصفوفةٍ فارغة — تُقارَن القيمة بخريطة النطاق.
        $response = $this->patchJson(
            "/api/v1/admin/operations/shipments/{$shipment->id}",
            ['status' => 'LABEL_CREATED'],
        )->assertOk();

        $this->assertSame(
            ShipmentTransitionService::allowedFrom('LABEL_CREATED'),
            $response->json('allowedNext'),
        );
        $this->assertNotEmpty($response->json('allowedNext'));
    }
}
