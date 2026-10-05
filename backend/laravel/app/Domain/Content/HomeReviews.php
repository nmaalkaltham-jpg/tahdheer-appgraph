<?php

namespace App\Domain\Content;

use App\Domain\Reviews\Models\Review;
use App\Http\Resources\HomeReviewQuoteResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The storewide review band — the ONE homepage section with no editorial input
 * at all. Everything here is computed from the reviews domain:
 *
 *  · rating_avg / rating_count are the count-weighted roll-up of
 *    `review_aggregates` (the materialized table only AggregateRebuilder
 *    writes) across PUBLISHED products. A draft or retired product cannot lend
 *    the storefront its stars.
 *  · quotes are APPROVED reviews that carry a real `order_id` — the same
 *    condition that earns the verified badge on a product page. A pending, a
 *    rejected, or an unpurchased review never reaches the homepage.
 *  · they are ordered NEWEST FIRST, deliberately not by rating: picking the
 *    five-star ones would make the band a curated advert rather than a sample
 *    of what buyers said.
 *  · no `stats` tiles are emitted. Every figure that block could carry
 *    (visitors, downloads, «teachers served») has no truthful source in the
 *    schema today, and the storefront already renders the band without it.
 *
 * Null ⇒ not a single approved rating exists yet, and the section stays blank.
 */
final class HomeReviews
{
    public const QUOTE_LIMIT = 6;

    /**
     * @return array{rating_avg: float, rating_count: int, quotes: array<int, mixed>}|null
     */
    public static function payload(Request $request): ?array
    {
        $roll = DB::table('review_aggregates')
            ->join('products', 'products.id', '=', 'review_aggregates.product_id')
            ->where('products.status', 'published')
            ->selectRaw('sum(review_aggregates.count) as total_count')
            ->selectRaw('sum(review_aggregates.avg_rating * review_aggregates.count) as weighted_sum')
            ->first();

        $count = (int) ($roll->total_count ?? 0);

        // Never 0.0 stars out of 0 reviews: no aggregate ⇒ no section.
        if ($count < 1) {
            return null;
        }

        return [
            'rating_avg' => round(((float) $roll->weighted_sum) / $count, 2),
            'rating_count' => $count,
            'quotes' => HomeReviewQuoteResource::collection(self::quotes())->toArray($request),
        ];
    }

    /**
     * @return Collection<int, Review>
     */
    private static function quotes()
    {
        return Review::query()
            ->approved()
            ->whereNotNull('order_id')          // verified purchase, or no quote
            ->whereNotNull('body')
            ->where('body', '<>', '')
            ->whereHas('product', fn (Builder $q) => $q->where('status', 'published'))
            ->with(['product:id,slug,title', 'customer:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::QUOTE_LIMIT)
            ->get();
    }
}
