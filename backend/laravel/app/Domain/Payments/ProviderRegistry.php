<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Contracts\PaymentProvider;

class ProviderRegistry
{
    /** Null when the provider is unknown or forbidden in this environment. */
    public function resolve(string $name): ?PaymentProvider
    {
        return match ($name) {
            'tap' => new TapProvider,
            'fake' => app()->environment(['local', 'testing']) ? new FakeProvider : null,
            default => null,
        };
    }

    /**
     * أسماء المزوّدين الذين يمكن للدفع أن يعيدهم في هذه البيئة.
     *
     * @return list<string>
     */
    public function available(): array
    {
        return array_values(array_filter(
            ['fake', 'tap'],
            fn (string $name) => $this->resolve($name) !== null,
        ));
    }

    /** Checkout default: Tap in production, deterministic fake elsewhere. */
    public function default(): PaymentProvider
    {
        $name = (string) (config('services.payments.provider')
            ?? (app()->environment('production') ? 'tap' : 'fake'));

        return $this->resolve($name) ?? new TapProvider;
    }
}
