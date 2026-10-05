<?php

namespace Tests\Feature\Commerce;

use App\Domain\Cart\Models\Cart;
use App\Domain\Checkout\CheckoutService;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CheckoutAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_checkout_leaves_no_customer_behind(): void
    {
        $cart = Cart::create(['token' => uniqid()]);

        try {
            app(CheckoutService::class)->createOrder($cart, [
                'name' => 'نورة', 'email' => 'orphan@example.com', 'phone' => '512345678',
                'policyConsent' => true, 'marketingOptIn' => true,
            ]);
            $this->fail('expected a validation failure on an empty cart');
        } catch (ValidationException) {
            // متوقّع
        }

        // كان الزبون يُلتزم قبل المعاملة، فيبقى بموافقةٍ تسويقيّة عن شراءٍ لم يقع.
        $this->assertSame(0, Customer::query()->where('email', 'orphan@example.com')->count());
    }
}
