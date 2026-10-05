<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Inventory\Models\InventoryReservation;
use App\Domain\Inventory\Models\StockLevel;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * تسوية الحجوزات تحت التزامن — على PostgreSQL حقيقيّ (انظر TransitionLockingTest).
 */
class InventorySettlementTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $variantIds = [];

    /** @var list<int> */
    private array $groupIds = [];

    /** @var list<int> */
    private array $locationIds = [];

    protected function beginDatabaseTransaction(): void
    {
        // عمدًا فارغة — التزامن يحتاج التزامًا حقيقيًّا.
    }

    protected function tearDown(): void
    {
        DB::statement('RESET lock_timeout');
        if ($this->variantIds !== []) {
            DB::table('inventory_reservations')->whereIn('product_variant_id', $this->variantIds)->delete();
            DB::table('stock_ledger')->whereIn('product_variant_id', $this->variantIds)->delete();
            DB::table('stock_levels')->whereIn('product_variant_id', $this->variantIds)->delete();
            DB::table('outbox_events')->where('event_type', 'inventory.availability.changed')->whereIn('aggregate_id', array_map('strval', $this->variantIds))->delete();
            DB::table('product_variants')->whereIn('id', $this->variantIds)->delete();
            $this->variantIds = [];
        }

        /*
         * المجموعة والموقع يُلتزمان أيضًا (التغليف معطَّل هنا)، وتركُهما
         * يُراكم صفوفًا ترثها اختباراتٌ تعدّ الجداول — وهو إخفاقٌ يعتمد
         * على ترتيب التشغيل، أسوأ أنواع الإخفاق.
         */
        if ($this->groupIds !== []) {
            DB::table('product_groups')->whereIn('id', $this->groupIds)->delete();
            $this->groupIds = [];
        }

        if ($this->locationIds !== []) {
            DB::table('inventory_locations')->whereIn('id', $this->locationIds)->delete();
            $this->locationIds = [];
        }
        parent::tearDown();
    }

    private function secondConnection(): Connection
    {
        config(['database.connections.concurrent' => config('database.connections.pgsql')]);
        DB::purge('concurrent');
        $second = DB::connection('concurrent');
        $second->statement("SET lock_timeout = '2s'");

        return $second;
    }

    /** @return array{0: int, 1: int} variant id, location id */
    private function stocked(int $onHand): array
    {
        $n = uniqid();
        $group = ProductGroup::create(['slug' => "g-{$n}", 'title' => 'م', 'product_nature' => 'PHYSICAL', 'status' => 'published']);
        $variant = ProductVariant::create(['product_group_id' => $group->id, 'sku' => "V-{$n}", 'slug' => "v-{$n}", 'status' => 'published']);
        $location = InventoryLocation::create(['code' => 'L-'.$n, 'name' => 'م', 'kind' => 'WAREHOUSE']);
        $this->variantIds[] = $variant->id;
        $this->groupIds[] = $group->id;
        $this->locationIds[] = $location->id;

        app(InventoryService::class)->receive($variant->id, $location->id, $onHand, 'افتتاح');

        return [$variant->id, $location->id];
    }

    private function level(int $variantId, int $locationId): StockLevel
    {
        return StockLevel::query()->where('product_variant_id', $variantId)->where('inventory_location_id', $locationId)->firstOrFail();
    }

    public function test_two_lines_of_one_order_on_the_same_variant_reserve_twice(): void
    {
        [$variantId, $locationId] = $this->stocked(5);
        $inventory = app(InventoryService::class);

        $inventory->reserve($variantId, $locationId, 1, 'order', 77, lineId: 1);
        $inventory->reserve($variantId, $locationId, 1, 'order', 77, lineId: 2);

        /*
         * كان المفتاح يُشتقّ من الطلب لا من البند: البند الثاني على الصيغة
         * نفسها يجد حجز الأوّل ويعود به — فيُباع اثنان ويُحجز واحد.
         */
        $this->assertSame(2, (int) $this->level($variantId, $locationId)->reserved);
        $this->assertSame(2, InventoryReservation::query()->where('holder_id', 77)->count());
    }

    public function test_a_second_line_cannot_oversell_what_the_first_took(): void
    {
        [$variantId, $locationId] = $this->stocked(1);
        $inventory = app(InventoryService::class);

        $inventory->reserve($variantId, $locationId, 1, 'order', 78, lineId: 1);

        $this->expectException(RuntimeException::class);
        $inventory->reserve($variantId, $locationId, 1, 'order', 78, lineId: 2);
    }

    public function test_the_reservation_row_is_locked_while_it_is_settled(): void
    {
        [$variantId, $locationId] = $this->stocked(3);
        $inventory = app(InventoryService::class);
        $reservation = $inventory->reserve($variantId, $locationId, 2, 'order', 79, lineId: 1);

        $second = $this->secondConnection();
        $second->beginTransaction();

        try {
            // عاملٌ آخر يمسك الحجز الآن (كما يفعل الاستهلاك أو الإفراج).
            $second->table('inventory_reservations')->where('id', $reservation->id)->lockForUpdate()->get();

            DB::statement("SET lock_timeout = '1s'");

            /*
             * كان الاستهلاك يقرأ الحالة بلا قفل ثمّ يقفل الرصيد. فالإفراج
             * والاستهلاك يمرّان معًا على حجزٍ واحد ويُنقص كلٌّ منهما المحجوز.
             * القفل على صفّ الحجز يجعل الثاني ينتظر — أو يُرمى هنا لثبوت ذلك.
             */
            $this->expectException(QueryException::class);
            $inventory->consume($reservation);
        } finally {
            $second->rollBack();
        }
    }

    public function test_a_settled_reservation_is_not_settled_again(): void
    {
        [$variantId, $locationId] = $this->stocked(3);
        $inventory = app(InventoryService::class);
        $reservation = $inventory->reserve($variantId, $locationId, 2, 'order', 80, lineId: 1);

        $inventory->consume($reservation);
        $inventory->release(InventoryReservation::query()->findOrFail($reservation->id));

        $level = $this->level($variantId, $locationId);
        $this->assertSame(0, (int) $level->reserved);
        $this->assertSame(1, (int) $level->on_hand);
    }

    public function test_keyless_movements_emit_distinct_availability_events(): void
    {
        [$variantId, $locationId] = $this->stocked(1);
        [$otherVariantId, $otherLocationId] = $this->stocked(1);

        $keys = DB::table('outbox_events')->where('event_type', 'inventory.availability.changed')
            ->whereIn('aggregate_id', [(string) $variantId, (string) $otherVariantId])
            ->pluck('idempotency_key');

        // كلّ حركةٍ حدثٌ بمفتاحها هي، لا مفتاحٌ مشتقٌّ من أكبر معرّفٍ قد يتكرّر.
        $this->assertSame($keys->count(), $keys->unique()->count());
        $this->assertGreaterThanOrEqual(2, $keys->count());
    }
}
