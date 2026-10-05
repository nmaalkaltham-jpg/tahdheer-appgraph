<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryLocation;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * المخزون دفترٌ لا عمود (§٢٨ §٢٩ §٣٠).
 *
 * الاختبارات تُثبت الخاصّيّة التي تجعل الدفتر مفيدًا: الرصيد **مُشتقّ**،
 * فإن اختلف عن مجموع الحركات فهو الخاطئ ويُصحَّح منها. عمودٌ يُنقَص ويُزاد
 * وحده لا يمكن التحقّق منه أصلًا.
 */
class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;

    private ProductVariant $variant;

    private InventoryLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = $this->app->make(InventoryService::class);

        $n = uniqid();
        $group = ProductGroup::create([
            'slug' => "g-{$n}", 'title' => 'مجموعة', 'product_nature' => 'PHYSICAL', 'status' => 'published',
        ]);
        $this->variant = ProductVariant::create([
            'product_group_id' => $group->id, 'sku' => "V-{$n}", 'slug' => "v-{$n}", 'status' => 'published',
        ]);
        $this->location = InventoryLocation::create([
            'code' => "LOC-{$n}", 'name' => 'المستودع الرئيس', 'kind' => 'WAREHOUSE',
        ]);
    }

    private function level(): StockLevel
    {
        return StockLevel::query()
            ->where('product_variant_id', $this->variant->id)
            ->where('inventory_location_id', $this->location->id)
            ->firstOrFail();
    }

    private function reserve(int $qty, int $holderId = 1)
    {
        return $this->inventory->reserve($this->variant->id, $this->location->id, $qty, 'cart', $holderId);
    }

    public function test_receiving_stock_writes_a_ledger_row_not_just_a_number(): void
    {
        $this->inventory->receive($this->variant->id, $this->location->id, 10, 'شحنة افتتاحيّة');

        $this->assertSame(10, $this->level()->on_hand);
        $movement = StockMovement::query()->firstOrFail();
        $this->assertSame('RECEIPT', $movement->movement);
        $this->assertSame(10, $movement->quantity_delta);
        // الرصيد بعد الحركة مخزَّن، فمراجعة نقطةٍ لا تحتاج جمع الدفتر كلّه.
        $this->assertSame(10, $movement->balance_after);
        $this->assertSame('شحنة افتتاحيّة', $movement->reason);
    }

    public function test_a_reservation_reduces_what_is_available_without_moving_what_is_on_hand(): void
    {
        $this->inventory->receive($this->variant->id, $this->location->id, 10, 'شحنة');
        $this->reserve(3);

        $level = $this->level();
        $this->assertSame(10, $level->on_hand);   // لم يخرج شيءٌ بعد
        $this->assertSame(3, $level->reserved);
        $this->assertSame(7, $level->available());
    }

    public function test_insufficient_stock_fails_loudly_and_reserves_nothing(): void
    {
        $this->inventory->receive($this->variant->id, $this->location->id, 2, 'شحنة');

        try {
            $this->reserve(5);
            $this->fail('كان يجب أن يفشل.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('لا يكفي المخزون', $e->getMessage());
        }

        // حجزٌ جزئيٌّ صامت أسوأ من رفضٍ صريح: الزبون يدفع ثمن خمسةٍ ويصله اثنان.
        $this->assertSame(0, $this->level()->reserved);
    }

    public function test_releasing_returns_the_stock_to_available(): void
    {
        $this->inventory->receive($this->variant->id, $this->location->id, 10, 'شحنة');
        $reservation = $this->reserve(4);

        $this->inventory->release($reservation);

        $this->assertSame(10, $this->level()->available());
        $this->assertSame('RELEASED', $reservation->fresh()->status);
    }

    public function test_consuming_lowers_both_reserved_and_on_hand(): void
    {
        $this->inventory->receive($this->variant->id, $this->location->id, 10, 'شحنة');
        $reservation = $this->reserve(4);

        $this->inventory->consume($reservation);

        $level = $this->level();
        /*
         * الخطأ الذي يمنعه هذا: إنقاص `on_hand` وحده يترك `reserved` مرفوعًا
         * إلى الأبد، فيختفي مخزونٌ موجودٌ من المتاح بلا أثرٍ يُقرأ.
         */
        $this->assertSame(6, $level->on_hand);
        $this->assertSame(0, $level->reserved);
        $this->assertSame(6, $level->available());
    }

    public function test_an_expired_hold_frees_the_stock_it_was_freezing(): void
    {
        $this->inventory->receive($this->variant->id, $this->location->id, 5, 'شحنة');
        $reservation = $this->reserve(5);
        $reservation->update(['expires_at' => now()->subMinute()]);

        $released = $this->inventory->releaseExpired();

        // بلا هذا، سلّةٌ مهجورة تُنفد المتجر بطلباتٍ لم تُدفع.
        $this->assertSame(1, $released);
        $this->assertSame('EXPIRED', $reservation->fresh()->status);
        $this->assertSame(5, $this->level()->available());
    }

    public function test_the_level_can_always_be_rebuilt_from_the_ledger(): void
    {
        $this->inventory->receive($this->variant->id, $this->location->id, 10, 'شحنة');
        $this->inventory->adjust($this->variant->id, $this->location->id, -2, 'تلفٌ في الجرد');
        $this->reserve(3);

        // نُفسد المُشتقّ عمدًا، كما يفسد بكتابةٍ مباشرة أو بعطل.
        $this->level()->update(['on_hand' => 999, 'reserved' => 999]);

        $rebuilt = $this->inventory->rebuildLevel($this->variant->id, $this->location->id);

        // الدفتر هو الحقيقة، والمُشتقّ يُصحَّح منه — لا العكس.
        $this->assertSame(8, $rebuilt->on_hand);
        $this->assertSame(3, $rebuilt->reserved);
    }

    public function test_reserving_twice_for_the_same_holder_holds_once(): void
    {
        $this->inventory->receive($this->variant->id, $this->location->id, 10, 'شحنة');

        $a = $this->reserve(3, holderId: 77);
        $b = $this->reserve(3, holderId: 77);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(3, $this->level()->reserved);
    }

    public function test_an_adjustment_without_a_written_reason_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/سببًا مكتوبًا/u');

        $this->inventory->adjust($this->variant->id, $this->location->id, -1, '   ');
    }

    public function test_on_hand_may_go_negative_because_preorder_is_a_supported_strategy(): void
    {
        // §٢٨ يذكر PREORDER وBACKORDER صراحةً؛ منعُ السالب كان سيمنعهما.
        $this->inventory->adjust($this->variant->id, $this->location->id, -3, 'طلبٌ مسبق قبل الوصول');

        $this->assertSame(-3, $this->level()->on_hand);
    }

    public function test_stock_in_a_non_sellable_location_is_not_offered(): void
    {
        $this->inventory->receive($this->variant->id, $this->location->id, 10, 'شحنة');
        $this->assertSame(10, $this->inventory->availableFor($this->variant->id));

        $this->location->update(['sellable' => false]);

        // مخزون المورّد موجودٌ ولا يُباع — §٣٠ يفرّق بين الموقعين.
        $this->assertSame(0, $this->inventory->availableFor($this->variant->id));
    }
}
