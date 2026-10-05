<?php

namespace Tests\Feature\Commerce;

use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Inventory\InventoryService;
use App\Domain\Orders\OrderFinalizer;
use App\Domain\Orders\RefundService;
use App\Domain\Payments\Models\Payment;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use Tests\TestCase;

/**
 * تثبيت الدفع تحت الخطأ والتكرار.
 *
 * كلّ اختبارٍ هنا يقابل طريقةً كان بها المال يُقبض والطلب لا يُموَّل، أو
 * يُموَّل مرّتين، أو يعود إلى الحياة بعد أن استُرد.
 */
class OrderFinalizerHardeningTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    private function awaiting(string $sku): Order
    {
        $cookie = $this->cartWith([$this->makeProduct($sku, 40000)]);

        return $this->checkoutOrder($cookie)['order'];
    }

    public function test_a_unique_violation_from_another_table_is_not_swallowed_as_a_duplicate(): void
    {
        $order = $this->awaiting('TD-F-001');

        $inventory = $this->createMock(InventoryService::class);
        $inventory->method('settleFor')->willThrowException(new QueryException(
            'pgsql', 'insert into "stock_ledger"', [],
            new PDOException('SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint "stock_ledger_idempotency_key_unique"', 23505),
        ));
        $this->app->instance(InventoryService::class, $inventory);

        /*
         * كان كلّ انتهاك تفرّدٍ يُقرأ «تسليمًا مكرّرًا» فيُردّ للبوّابة ٢٠٠
         * وتتوقّف عن المحاولة — والمال مقبوضٌ والطلب لم يُموَّل. الدليل على
         * التكرار هو وجود مفتاح الدفعة نفسه، لا رمزُ الخطأ.
         */
        $this->expectException(QueryException::class);

        try {
            app(OrderFinalizer::class)->finalize($order, 'fake', 'chg_foreign');
        } finally {
            $this->assertDatabaseMissing('payments', ['idempotency_key' => 'fake:chg_foreign']);
            $this->assertSame('AWAITING_PAYMENT', $order->fresh()->status);
        }
    }

    public function test_a_second_distinct_charge_is_a_second_payment_row_not_an_overwrite(): void
    {
        $order = $this->awaiting('TD-F-002');
        $finalizer = app(OrderFinalizer::class);

        $this->assertTrue($finalizer->finalize($order, 'fake', 'chg_1', [], 40000, 'SAR'));
        $firstPaidAt = $order->fresh()->paid_at;

        $this->assertTrue($finalizer->finalize($order->fresh(), 'fake', 'chg_2', [], 40000, 'SAR'));

        $rows = Payment::query()->where('order_id', $order->id)->orderBy('id')->get();

        // رسمان مختلفان عند المزوّد رسمان في الدفتر — وإلّا ضاع أحدهما من التسوية.
        $this->assertCount(2, $rows);
        $this->assertSame(['fake:chg_1', 'fake:chg_2'], $rows->pluck('idempotency_key')->all());
        $this->assertSame(['CAPTURED', 'CAPTURED'], $rows->pluck('state')->all());

        // ومفتاح الرسم الأوّل ما زال يمنع إعادةَ تشغيله.
        $this->assertFalse($finalizer->finalize($order->fresh(), 'fake', 'chg_1'));

        // وطابع الدفع يُكتب مرّةً واحدة.
        $this->assertEquals($firstPaidAt, $order->fresh()->paid_at);
    }

    public function test_a_late_charge_does_not_resurrect_a_refunded_order(): void
    {
        $order = $this->awaiting('TD-F-003');
        $finalizer = app(OrderFinalizer::class);
        $finalizer->finalize($order, 'fake', 'chg_a', [], 40000, 'SAR');

        app(RefundService::class)->refund($order->fresh(), 40000, 'اختبار', RevocationPolicy::Immediate);
        $this->assertSame('REFUNDED', $order->fresh()->status);

        $finalizer->finalize($order->fresh(), 'fake', 'chg_b', [], 40000, 'SAR');

        $fresh = $order->fresh();
        // المال رُدّ؛ رسمٌ متأخّر لا يُعيد الطلب «مدفوعًا» ولا يُعيد الاستحقاق.
        $this->assertSame('REFUNDED', $fresh->status);
        $this->assertSame(40000, (int) $fresh->refunded_total_minor);
        $this->assertSame('REVOKED', Entitlement::query()->where('order_id', $order->id)->firstOrFail()->status);
        // لكنّ الرسم نفسه يُسجَّل: مالٌ وصل ولا يُخفى.
        $this->assertDatabaseHas('payments', ['idempotency_key' => 'fake:chg_b', 'order_id' => $order->id]);
    }
}
