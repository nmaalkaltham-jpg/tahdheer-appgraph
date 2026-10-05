<?php

namespace App\Domain\Search\Contracts;

use App\Domain\Search\SearchRequest;
use App\Domain\Search\SearchResult;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Retrieval engine seam (MIP §6, §6.8). Storefront code consumes SearchService
 * DTOs only — never raw engine shapes — so Meilisearch can be swapped or a
 * future hybrid (vector fusion) provider added without interface changes.
 */
interface SearchProvider
{
    public function search(SearchRequest $request): SearchResult;

    /** @return Collection<int, Product> at most $limit products */
    public function suggest(string $normalizedQuery, int $limit): Collection;

    public function healthy(): bool;
}
