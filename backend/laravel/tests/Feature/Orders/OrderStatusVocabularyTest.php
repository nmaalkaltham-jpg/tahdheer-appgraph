<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Orders\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مفردات حالة الطلب — مرآةٌ للمحرّك، لا قائمةٌ تُصان باليد.
 *
 * السؤالان الماليّان كانا مكتوبين ثلاثَ عشرة مرّة ويتّفقان بالمصادفة. وهذا
 * الاختبار يقرأ القيم من **قاعدة البيانات نفسها** لا من نسخةٍ ثانية: إعادةُ
 * كتابتها هنا كانت ستُنتج نسخةً رابعة عشرة تحرس نفسها.
 */
class OrderStatusVocabularyTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> قيم القيد على `orders.status` كما يراها المحرّك. */
    private function engineStatuses(): array
    {
        $definition = (string) DB::selectOne(
            "select pg_get_constraintdef(oid) as d
             from pg_constraint
             where conrelid = 'orders'::regclass
               and contype = 'c'
               and pg_get_constraintdef(oid) like '%PENDING%'"
        )?->d;

        preg_match_all("/'([A-Z_]+)'/", $definition, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function test_the_declared_vocabulary_matches_the_engine(): void
    {
        $engine = $this->engineStatuses();

        $this->assertNotEmpty($engine, 'لا قيدَ على حالة الطلب في المحرّك');
        $this->assertSame(
            [],
            array_values(array_diff(OrderStatus::ALL, $engine)),
            'حالةٌ يقبلها التطبيق ويرفضها المحرّك ⇒ ٥٠٠ بلا اسم حقل',
        );
        $this->assertSame(
            [],
            array_values(array_diff($engine, OrderStatus::ALL)),
            'حالةٌ في المحرّك لا يعرفها التطبيق ⇒ صفوفٌ لا يعدّها أحد',
        );
    }

    public function test_collected_is_a_strict_subset_of_ever_collected(): void
    {
        $this->assertSame([], array_values(array_diff(OrderStatus::COLLECTED, OrderStatus::EVER_COLLECTED)));
        $this->assertNotSame(OrderStatus::COLLECTED, OrderStatus::EVER_COLLECTED,
            'السؤالان مختلفان: «المال في اليد» ليس «وصل المال يومًا»');
    }

    public function test_a_refunded_order_never_counts_as_money_in_hand(): void
    {
        foreach (['REFUNDED', 'PARTIALLY_REFUNDED'] as $status) {
            $this->assertFalse(OrderStatus::isCollected($status), "{$status} ماله خرج");
            $this->assertTrue(OrderStatus::wasEverCollected($status), "{$status} وصل ماله يومًا");
        }
    }

    public function test_an_unpaid_order_is_neither(): void
    {
        foreach (['PENDING', 'AWAITING_PAYMENT', 'FAILED', 'CANCELLED'] as $status) {
            $this->assertFalse(OrderStatus::isCollected($status));
            $this->assertFalse(OrderStatus::wasEverCollected($status));
        }
    }

    public function test_a_fully_refunded_order_has_no_refundable_balance(): void
    {
        $order = new Order([
            'status' => 'REFUNDED', 'total_minor' => 5000, 'refunded_total_minor' => 5000,
        ]);

        /* وصل ماله — ولم يبقَ منه شيء. والقائمةُ الرابعة كانت تخلط الشرطين. */
        $this->assertTrue(OrderStatus::wasEverCollected($order->status));
        $this->assertFalse(OrderStatus::hasRefundableBalance($order));

        $order->refunded_total_minor = 2000;
        $this->assertTrue(OrderStatus::hasRefundableBalance($order));
    }
}
