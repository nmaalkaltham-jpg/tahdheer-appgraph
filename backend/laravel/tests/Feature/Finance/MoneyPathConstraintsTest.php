<?php

namespace Tests\Feature\Finance;

use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * قيود مسار المال في المحرّك — ما يمنعه التطبيق يمنعه PostgreSQL أيضًا.
 */
class MoneyPathConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $status = 'PAID', int $refunded = 0): Order
    {
        return Order::create([
            'number' => 'TD-'.uniqid(), 'email' => 'c@t.local', 'status' => $status,
            'total_minor' => 1000, 'refunded_total_minor' => $refunded, 'paid_at' => now(),
        ]);
    }

    public function test_one_provider_charge_cannot_be_two_payment_rows(): void
    {
        $order = $this->order();
        DB::table('payments')->insert(['order_id' => $order->id, 'provider' => 'fake', 'intent_id' => 'chg_u', 'state' => 'CAPTURED', 'idempotency_key' => 'fake:chg_u', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(QueryException::class);
        DB::table('payments')->insert(['order_id' => $order->id, 'provider' => 'fake', 'intent_id' => 'chg_u', 'state' => 'CAPTURED', 'idempotency_key' => 'fake:chg_u:again', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_payment_state_outside_the_vocabulary_is_refused(): void
    {
        $order = $this->order();

        $this->expectException(QueryException::class);
        DB::table('payments')->insert(['order_id' => $order->id, 'provider' => 'fake', 'state' => 'BOGUS', 'idempotency_key' => 'fake:bogus', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_paid_order_cannot_carry_a_refunded_total(): void
    {
        $order = $this->order();

        // «مدفوع» بمبلغٍ مستردّ هو الصفّ الذي كان يُنتجه رسمٌ متأخّر بعد استرداد.
        $this->expectException(QueryException::class);
        DB::table('orders')->where('id', $order->id)->update(['refunded_total_minor' => 500]);
    }

    public function test_a_refunded_order_must_carry_its_refunded_total(): void
    {
        $this->expectException(QueryException::class);
        $this->order('REFUNDED', 0);
    }

    public function test_consistent_refund_rows_are_accepted(): void
    {
        $this->assertNotNull($this->order('PARTIALLY_REFUNDED', 400)->id);
        $this->assertNotNull($this->order('REFUNDED', 1000)->id);
    }
}
