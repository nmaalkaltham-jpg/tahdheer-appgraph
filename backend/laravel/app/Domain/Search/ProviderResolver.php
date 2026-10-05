<?php

namespace App\Domain\Search;

use App\Domain\Search\Contracts\SearchProvider;
use App\Domain\Search\Providers\MeilisearchProvider;
use App\Domain\Search\Providers\PgFallbackProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Provider selection with automatic degradation (MIP §6.7): configured
 * engine = meilisearch|pg (config('search.provider'), falling back to the
 * SEARCH_PROVIDER env, defaulting to pg). Meilisearch is used only when its
 * timeboxed health check answers; every fallback is logged, never silent
 * to operators — and never an error to the visitor.
 */
class ProviderResolver
{
    public function resolve(): SearchProvider
    {
        if ($this->configuredName() === 'meilisearch') {
            $meili = app(MeilisearchProvider::class);
            // جولةُ فحصٍ إضافيّة قبل كلّ بحث — تُذكَر عشر ثوانٍ لا تُعاد مع كلّ طلب.
            $healthy = Cache::remember('search.meili.healthy', now()->addSeconds(10), fn () => $meili->healthy());
            if ($healthy) {
                return $meili;
            }
            Log::warning('search.provider_fallback', ['from' => 'meilisearch', 'to' => 'pg', 'reason' => 'unhealthy']);
        }

        return app(PgFallbackProvider::class);
    }

    public function fallback(): SearchProvider
    {
        return app(PgFallbackProvider::class);
    }

    public function configuredName(): string
    {
        return (string) (config('search.provider') ?? env('SEARCH_PROVIDER', 'pg'));
    }
}
