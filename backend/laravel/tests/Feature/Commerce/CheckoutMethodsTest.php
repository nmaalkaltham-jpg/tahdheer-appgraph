<?php

namespace Tests\Feature\Commerce;

use Tests\TestCase;

/** GET /api/v1/checkout/methods — ما يقبله الدفع فعلًا، لا ما يُرسم في التصميم. */
class CheckoutMethodsTest extends TestCase
{
    public function test_it_lists_the_resolvable_providers_and_default_marks(): void
    {
        config(['services.payments.provider' => null, 'services.tap.apple_pay' => false]);

        $this->getJson('/api/v1/checkout/methods')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertExactJson([
                'providers' => ['fake', 'tap'],
                'applePay' => false,
                'marks' => ['mada', 'VISA', 'MC'],
            ]);
    }

    public function test_apple_pay_needs_tap_as_default_and_the_flag(): void
    {
        config(['services.payments.provider' => 'fake', 'services.tap.apple_pay' => true]);
        $this->getJson('/api/v1/checkout/methods')->assertOk()->assertJsonPath('applePay', false);

        config(['services.payments.provider' => 'tap', 'services.tap.apple_pay' => false]);
        $this->getJson('/api/v1/checkout/methods')->assertOk()->assertJsonPath('applePay', false);

        config(['services.payments.provider' => 'tap', 'services.tap.apple_pay' => true]);
        $this->getJson('/api/v1/checkout/methods')->assertOk()
            ->assertJsonPath('applePay', true)
            ->assertJsonPath('marks', ['mada', 'VISA', 'MC', 'Apple Pay']);
    }
}
