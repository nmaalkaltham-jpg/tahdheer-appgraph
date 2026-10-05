<?php

namespace Tests\Feature\Commerce;

use App\Domain\Cart\CartService;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Cart\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    public function test_validation_rules_return_arabic_field_errors(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-CO-001', 40000)]);

        $response = $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/checkout/orders', [
                'name' => 'نورة',
                'email' => 'not-an-email',
                'phone' => '0512345678',                       // must be 5XXXXXXXX
                'taxProfile' => ['orgName' => 'مدارس', 'vatNumber' => '123'], // must be 3…3, 15 digits
                'policyConsent' => false,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'phone', 'taxProfile.vatNumber', 'policyConsent']);

        $this->assertSame(
            'رقم الجوال يجب أن يكون بصيغة 5XXXXXXXX (بدون 966+)',
            $response->json('errors.phone.0'),
        );
        $this->assertSame(
            'الرقم الضريبي يجب أن يتكون من 15 رقمًا ويبدأ وينتهي بالرقم 3',
            $response->json('errors')['taxProfile.vatNumber'][0],
        );
        $this->assertSame(
            'يجب الموافقة على السياسات لإتمام الطلب',
            $response->json('errors.policyConsent.0'),
        );
        $this->assertSame(0, Order::query()->count());
    }

    public function test_checkout_without_cart_is_422(): void
    {
        $this->postJson('/api/v1/checkout/orders', [
            'name' => 'نورة', 'email' => 'noura@example.com', 'phone' => '512345678',
            'policyConsent' => true,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'السلة فارغة — لا يمكن إنشاء طلب بدون منتجات');
    }

    public function test_successful_checkout_snapshots_totals_and_clears_cart(): void
    {
        $plain = $this->makeProduct('TD-CO-002', 40000);
        $onSale = $this->makeProduct('TD-CO-003', 30000, 25000);
        Coupon::query()->create(['code' => 'TEACHER15', 'type' => 'percent', 'value' => 15]);

        $cookie = $this->cartWith([$plain, $onSale]);
        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/cart/coupon', ['code' => 'TEACHER15'])->assertOk();

        $result = $this->checkoutOrder($cookie, [
            'taxProfile' => ['orgName' => 'مدارس النور الأهلية', 'vatNumber' => '310123456789013'],
        ]);
        $order = $result['order'];

        $this->assertMatchesRegularExpression('/^TD-\d+$/', $order->number);
        $this->assertSame('AWAITING_PAYMENT', $order->status);
        $this->assertSame(70000, (int) $order->totals_gross_minor);
        $this->assertSame(5000, (int) $order->discount_minor);
        $this->assertSame(9750, (int) $order->coupon_minor);
        $this->assertSame(55250, (int) $order->total_minor);
        $this->assertSame(7207, (int) $order->vat_minor);
        $this->assertSame('310123456789013', $order->tax_vat_number);
        $this->assertSame(hash('sha256', $result['token']), $order->order_key_hash);
        $this->assertSame(2, $order->items()->count());

        // Cart cleared only after the order exists.
        $this->assertSame(0, CartItem::query()->count());

        // Coupon consumption is re-validated + counted at order creation.
        $this->assertSame(1, (int) Coupon::query()->firstOrFail()->usage_count);
    }

    public function test_order_token_flow(): void
    {
        $cookie = $this->cartWith([$this->makeProduct('TD-CO-004', 40000)]);
        $result = $this->checkoutOrder($cookie);

        // Right token → full payload, masked email, locked files pre-payment.
        $this->getJson('/api/v1/orders/'.$result['token'])
            ->assertOk()
            ->assertJsonPath('orderNumber', $result['number'])
            ->assertJsonPath('status', 'AWAITING_PAYMENT')
            ->assertJsonPath('emailMasked', 'n***a@example.com')
            ->assertJsonPath('items.0.priceMinor', 40000)
            ->assertJsonPath('totals.totalMinor', 40000)
            ->assertJsonPath('files.0.locked', true)
            ->assertJsonPath('files.0.entitlementId', null)
            ->assertJsonMissingPath('paidAt');

        // Wrong token → 404. Tampered token → 404.
        $this->getJson('/api/v1/orders/completely-wrong')->assertNotFound();
        $tampered = substr($result['token'], 0, -2).'zz';
        $this->getJson('/api/v1/orders/'.$tampered)->assertNotFound();
    }

    public function test_coupon_expired_between_apply_and_checkout_is_revalidated(): void
    {
        $coupon = Coupon::query()->create(['code' => 'SOON', 'type' => 'percent', 'value' => 10]);
        $cookie = $this->cartWith([$this->makeProduct('TD-CO-005', 40000)]);
        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/cart/coupon', ['code' => 'SOON'])->assertOk();

        $coupon->update(['expires_at' => now()->subMinute()]);

        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/checkout/orders', [
                'name' => 'نورة', 'email' => 'noura@example.com', 'phone' => '512345678',
                'policyConsent' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'رمز الخصم غير صالح أو منتهي');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_usage_limit_exhausted_coupon_rejected_at_checkout(): void
    {
        Coupon::query()->create([
            'code' => 'LIMIT1', 'type' => 'percent', 'value' => 10,
            'usage_limit' => 1, 'usage_count' => 0,
        ]);

        $cookieA = $this->cartWith([$this->makeProduct('TD-CO-006', 40000)]);
        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookieA)
            ->postJson('/api/v1/cart/coupon', ['code' => 'LIMIT1'])->assertOk();
        $this->checkoutOrder($cookieA, ['email' => 'first@example.com']);

        $cookieB = $this->cartWith([$this->makeProduct('TD-CO-007', 40000)]);
        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookieB)
            ->postJson('/api/v1/cart/coupon', ['code' => 'LIMIT1'])
            ->assertStatus(422); // usage_count now at limit
    }

    public function test_first_order_coupon_rejected_for_returning_customer(): void
    {
        Coupon::query()->create([
            'code' => 'WELCOME', 'type' => 'percent', 'value' => 10, 'first_order_per_account' => true,
        ]);
        $customer = Customer::query()->create(['email' => 'returning@example.com', 'name' => 'عائدة']);
        Order::query()->create([
            'number' => 'TD-900', 'customer_id' => $customer->id,
            'email' => $customer->email, 'status' => 'PAID', 'total_minor' => 1000,
        ]);

        $cookie = $this->cartWith([$this->makeProduct('TD-CO-008', 40000)]);
        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/cart/coupon', ['code' => 'WELCOME'])->assertOk();

        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookie)
            ->postJson('/api/v1/checkout/orders', [
                'name' => 'عائدة', 'email' => 'returning@example.com', 'phone' => '512345678',
                'policyConsent' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'رمز الخصم غير صالح أو منتهي');

        // A genuinely-first buyer may use it.
        $cookieNew = $this->cartWith([$this->makeProduct('TD-CO-009', 40000)]);
        $this->withUnencryptedCookie(CartService::COOKIE_NAME, $cookieNew)
            ->postJson('/api/v1/cart/coupon', ['code' => 'WELCOME'])->assertOk();
        $result = $this->checkoutOrder($cookieNew, ['email' => 'brandnew@example.com']);
        $this->assertSame(36000, (int) $result['order']->total_minor);
    }
}
