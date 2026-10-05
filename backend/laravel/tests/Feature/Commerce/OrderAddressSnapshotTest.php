<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * العنوان لقطةٌ على الطلب لا مرجع (§٣٢ §١٠١).
 *
 * الخطأ الشائع ربطُ الطلب بصفٍّ في دفتر عناوين الزبون. عندها يُعيد تعديلُ
 * العنوان — أو حذفُه — كتابةَ وجهةِ شحنةٍ سُلّمت قبل شهر، فيصير سجلّ
 * الطلبات كاذبًا بأثرٍ رجعيّ. §١٠١ تمنع هذا صراحةً.
 */
class OrderAddressSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create(['email' => uniqid().'@test.local', 'name' => 'زبون']);
    }

    private function order(Customer $customer): Order
    {
        return Order::create([
            'number' => 'TD-'.uniqid(), 'customer_id' => $customer->id,
            'email' => $customer->email, 'status' => 'PAID', 'total_minor' => 5000,
        ]);
    }

    private function addressRow(int $customerId, string $city = 'الرياض'): int
    {
        return DB::table('addresses')->insertGetId([
            'customer_id' => $customerId, 'recipient_name' => 'مستلم', 'phone' => '0500000000',
            'country_code' => 'SA', 'city' => $city, 'district' => 'النرجس',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function snapshot(int $orderId, int $sourceId, string $city): void
    {
        DB::table('order_addresses')->insert([
            'order_id' => $orderId, 'kind' => 'SHIPPING', 'source_address_id' => $sourceId,
            'recipient_name' => 'مستلم', 'phone' => '0500000000',
            'country_code' => 'SA', 'city' => $city, 'district' => 'النرجس',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_deleting_the_customer_address_does_not_rewrite_a_shipped_order(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);
        $addressId = $this->addressRow($customer->id);
        $this->snapshot($order->id, $addressId, 'الرياض');

        DB::table('addresses')->where('id', $addressId)->delete();

        // اللقطة تبقى كاملةً: لا مفتاح أجنبيّ يُفرّغها.
        $snapshot = DB::table('order_addresses')->where('order_id', $order->id)->first();
        $this->assertSame('الرياض', $snapshot->city);
        $this->assertSame('مستلم', $snapshot->recipient_name);
    }

    public function test_editing_the_customer_address_does_not_change_past_orders(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);
        $addressId = $this->addressRow($customer->id, 'الرياض');
        $this->snapshot($order->id, $addressId, 'الرياض');

        DB::table('addresses')->where('id', $addressId)->update(['city' => 'جدة']);

        $this->assertSame('الرياض', DB::table('order_addresses')->where('order_id', $order->id)->value('city'));
    }

    public function test_an_order_cannot_carry_two_shipping_addresses(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer);
        $addressId = $this->addressRow($customer->id);
        $this->snapshot($order->id, $addressId, 'الرياض');

        $this->expectException(QueryException::class);
        $this->snapshot($order->id, $addressId, 'جدة');
    }

    public function test_a_customer_has_at_most_one_default_address(): void
    {
        $customer = $this->customer();

        DB::table('addresses')->insert([
            'customer_id' => $customer->id, 'recipient_name' => 'أ', 'phone' => '05',
            'country_code' => 'SA', 'city' => 'الرياض', 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('addresses')->insert([
            'customer_id' => $customer->id, 'recipient_name' => 'ب', 'phone' => '05',
            'country_code' => 'SA', 'city' => 'جدة', 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_half_a_coordinate_is_refused(): void
    {
        $customer = $this->customer();

        // نقطةٌ بخطّ عرضٍ بلا طول ليست موقعًا — وقد تُقرأ لاحقًا كأنّها موقع.
        $this->expectException(QueryException::class);
        DB::table('addresses')->insert([
            'customer_id' => $customer->id, 'recipient_name' => 'أ', 'phone' => '05',
            'country_code' => 'SA', 'city' => 'الرياض', 'latitude' => 24.7136,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_blank_city_is_refused_because_it_cannot_be_delivered(): void
    {
        $customer = $this->customer();

        $this->expectException(QueryException::class);
        DB::table('addresses')->insert([
            'customer_id' => $customer->id, 'recipient_name' => 'أ', 'phone' => '05',
            'country_code' => 'SA', 'city' => '   ',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
