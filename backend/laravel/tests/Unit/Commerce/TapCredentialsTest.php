<?php

namespace Tests\Unit\Commerce;

use App\Domain\Payments\InvalidWebhookSignature;
use App\Domain\Payments\TapCredentials;
use App\Domain\Payments\TapProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

/** عزل البيئات: وضعٌ واحد ومفاتيحُه، والخلط يُسقط الإقلاع. */
class TapCredentialsTest extends TestCase
{
    private function tap(array $overrides): void
    {
        config()->set('services.tap', array_replace_recursive([
            'mode' => null, 'merchant_id' => '68052232',
            'test' => ['secret_key' => null, 'public_key' => null],
            'live' => ['secret_key' => null, 'public_key' => null],
            'secret_key' => null, 'webhook_secret' => null,
            'allow_live_outside_production' => true,
        ], $overrides));
    }

    public function test_test_mode_with_a_test_key_is_consistent(): void
    {
        $this->tap(['mode' => 'test', 'test' => ['secret_key' => 'sk_test_abc123', 'public_key' => 'pk_test_abc123']]);

        $c = TapCredentials::fromConfig();

        $this->assertTrue($c->configured());
        $this->assertSame('test', $c->mode);
        $this->assertSame('68052232', $c->merchantId);
        $this->assertSame('sk_test_abc123', $c->webhookSecret, 'الافتراض: المفتاح السرّيّ يوقّع الخطّافات');
    }

    public function test_test_mode_with_a_live_key_is_a_startup_failure(): void
    {
        $this->tap(['mode' => 'test', 'test' => ['secret_key' => 'sk_live_abc123']]);

        $this->assertFalse(TapCredentials::fromConfig()->isConsistent());
        $this->expectException(\RuntimeException::class);
        TapCredentials::assertConsistentAtBoot();
    }

    public function test_live_mode_with_a_test_key_is_a_startup_failure(): void
    {
        $this->tap(['mode' => 'live', 'live' => ['secret_key' => 'sk_test_abc123']]);

        $this->expectException(\RuntimeException::class);
        TapCredentials::assertConsistentAtBoot();
    }

    public function test_live_mode_never_falls_back_to_the_test_key(): void
    {
        $this->tap(['mode' => 'live', 'test' => ['secret_key' => 'sk_test_abc123']]);

        $c = TapCredentials::fromConfig();

        $this->assertTrue($c->isConsistent());
        $this->assertSame('', $c->secretKey);
        $this->assertFalse($c->configured());
    }

    public function test_the_legacy_single_key_is_accepted_only_when_it_matches_the_mode(): void
    {
        $this->tap(['mode' => 'test', 'secret_key' => 'sk_test_legacy']);
        $this->assertSame('sk_test_legacy', TapCredentials::fromConfig()->secretKey);

        $this->tap(['mode' => 'live', 'secret_key' => 'sk_test_legacy']);
        $c = TapCredentials::fromConfig();
        $this->assertSame('', $c->secretKey);
        $this->assertFalse($c->isConsistent());
    }

    public function test_a_public_key_from_the_other_environment_is_rejected(): void
    {
        $this->tap(['mode' => 'test', 'test' => ['secret_key' => 'sk_test_abc123', 'public_key' => 'pk_live_abc123']]);

        $this->assertFalse(TapCredentials::fromConfig()->isConsistent());
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $this->tap(['mode' => 'sandbox', 'test' => ['secret_key' => 'sk_test_abc123']]);

        $this->assertFalse(TapCredentials::fromConfig()->isConsistent());
    }

    public function test_no_keys_at_all_means_nothing_to_validate(): void
    {
        $this->tap([]);

        $this->assertFalse(TapCredentials::anyConfigured());
        TapCredentials::assertConsistentAtBoot();
        $this->assertTrue(true);
    }

    public function test_a_provider_with_inconsistent_keys_refuses_to_sign_or_verify(): void
    {
        $this->tap(['mode' => 'test', 'test' => ['secret_key' => 'sk_live_abc123']]);

        $request = Request::create('/api/v1/payments/webhook/tap', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_HASHSTRING' => 'anything',
        ], content: '{"id":"chg_1","status":"CAPTURED"}');

        $this->expectException(InvalidWebhookSignature::class);
        (new TapProvider)->verifyWebhook($request);
    }

    public function test_a_missing_merchant_id_is_a_startup_failure(): void
    {
        $this->tap(['mode' => 'test', 'merchant_id' => null, 'test' => ['secret_key' => 'sk_test_abc123']]);

        $this->assertFalse(TapCredentials::fromConfig()->isConsistent());
        $this->expectException(\RuntimeException::class);
        TapCredentials::assertConsistentAtBoot();
    }

    public function test_a_non_numeric_merchant_id_is_rejected(): void
    {
        $this->tap(['mode' => 'test', 'merchant_id' => 'merchant-x', 'test' => ['secret_key' => 'sk_test_abc123']]);

        $this->assertFalse(TapCredentials::fromConfig()->isConsistent());
    }

    public function test_malformed_keys_are_rejected(): void
    {
        foreach (['sk_test_', 'sk_test_ab cd12', 'sk_test_abc$123', 'test_abc12345'] as $bad) {
            $this->tap(['mode' => 'test', 'test' => ['secret_key' => $bad]]);
            $this->assertFalse(TapCredentials::fromConfig()->isConsistent(), $bad);
        }

        $this->tap(['mode' => 'test', 'test' => ['secret_key' => 'sk_test_abc123', 'public_key' => 'pk_test_']]);
        $this->assertFalse(TapCredentials::fromConfig()->isConsistent());
    }

    public function test_live_mode_outside_production_needs_an_explicit_permission(): void
    {
        $this->tap(['mode' => 'live', 'allow_live_outside_production' => false, 'live' => ['secret_key' => 'sk_live_abc123']]);

        $this->assertFalse(TapCredentials::fromConfig()->isConsistent());
        $this->expectException(\RuntimeException::class);
        TapCredentials::assertConsistentAtBoot();
    }

    public function test_production_refuses_test_mode(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->tap(['mode' => 'test', 'test' => ['secret_key' => 'sk_test_abc123']]);
            $this->assertFalse(TapCredentials::fromConfig()->isConsistent());

            $this->tap(['mode' => 'live', 'allow_live_outside_production' => false, 'live' => ['secret_key' => 'sk_live_abc123']]);
            $this->assertTrue(TapCredentials::fromConfig()->isConsistent(), 'live in production needs no extra permission');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_the_provided_public_keys_have_the_expected_shape(): void
    {
        // المفاتيح العلنيّة من المالك تُفحص شكلًا فقط؛ لا تُخزَّن في الشيفرة.
        $this->tap(['mode' => 'test', 'test' => ['secret_key' => 'sk_test_abc123', 'public_key' => 'pk_test_'.str_repeat('a', 25)]]);
        $this->assertTrue(TapCredentials::fromConfig()->isConsistent());
    }

    public function test_production_with_tap_as_provider_and_no_key_refuses_to_boot(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->tap([]);
            config(['services.payments.provider' => 'tap']);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('no Tap secret key');
            TapCredentials::assertConsistentAtBoot();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_a_contradictory_mode_and_key_pair_refuses_to_boot_in_every_direction(): void
    {
        foreach ([
            ['test', ['live' => ['secret_key' => 'sk_test_abc123']], 'test mode, test key in live slot'],
            ['live', ['live' => ['secret_key' => 'sk_test_abc123']], 'live mode, test key'],
            ['test', ['test' => ['secret_key' => 'sk_live_abc123']], 'test mode, live key'],
        ] as [$mode, $keys, $why]) {
            $this->tap(['mode' => $mode] + $keys);
            try {
                TapCredentials::assertConsistentAtBoot();
                $this->fail("booted: {$why}");
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_production_refuses_a_test_secret_even_when_live_is_active(): void
    {
        $this->app['env'] = 'production';
        try {
            foreach ([
                ['test' => ['secret_key' => 'sk_test_abc123']],   // خانة التجربة
                ['secret_key' => 'sk_test_abc123'],               // الخانة القديمة
            ] as $extra) {
                $this->tap(['mode' => 'live', 'allow_live_outside_production' => false, 'live' => ['secret_key' => 'sk_live_abc123']] + $extra);
                $this->assertFalse(TapCredentials::fromConfig()->isConsistent(), json_encode(array_keys($extra)));
            }

            // والحيّ وحده في الإنتاج سليم.
            $this->tap(['mode' => 'live', 'allow_live_outside_production' => false, 'live' => ['secret_key' => 'sk_live_abc123']]);
            $this->assertTrue(TapCredentials::fromConfig()->isConsistent());
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
