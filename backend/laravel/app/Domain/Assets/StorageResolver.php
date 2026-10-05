<?php

namespace App\Domain\Assets;

use App\Domain\Assets\Contracts\StorageProvider;
use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Assets\Providers\GarageS3Provider;
use App\Domain\Assets\Providers\LocalSignedProvider;
use Illuminate\Support\Facades\Log;

/**
 * محدِّد مزوّد التخزين — config-driven garage|local with an honest fallback.
 *
 * When garage is requested but unavailable the resolver degrades to the local
 * disk and logs the reason ONCE per process (a per-request warning would drown
 * the log). The fallback is a real, working state — not a stub.
 */
class StorageResolver
{
    /** @var array<string, true> reasons already logged this process */
    private static array $loggedFallbacks = [];

    private ?StorageProvider $resolved = null;

    public function provider(): StorageProvider
    {
        return $this->resolved ??= $this->resolve();
    }

    /** The configured provider name, whatever the outcome of resolution. */
    public function configuredName(): string
    {
        return (string) config('filesystems.assets.provider', 'local');
    }

    /** True when the configured provider is the one actually serving. */
    public function isDegraded(): bool
    {
        return $this->configuredName() !== $this->provider()->name();
    }

    private function resolve(): StorageProvider
    {
        if ($this->configuredName() === 'garage') {
            try {
                return new GarageS3Provider((string) config('filesystems.assets.garage_disk', 'garage'));
            } catch (StorageUnavailable $e) {
                $this->logFallbackOnce($e->reason);
            }
        }

        return new LocalSignedProvider((string) config('filesystems.assets.local_disk', 'assets'));
    }

    private function logFallbackOnce(string $reason): void
    {
        if (isset(self::$loggedFallbacks[$reason])) {
            return;
        }

        self::$loggedFallbacks[$reason] = true;

        Log::warning('assets.storage.fallback', [
            'from' => 'garage',
            'to' => 'local',
            'reason' => $reason,
        ]);
    }

    /** Test seam: lets a test observe the once-only logging honestly. */
    public static function forgetFallbackLog(): void
    {
        self::$loggedFallbacks = [];
    }
}
