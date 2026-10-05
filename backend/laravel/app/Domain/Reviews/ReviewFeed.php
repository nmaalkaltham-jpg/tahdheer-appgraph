<?php

namespace App\Domain\Reviews;

use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Models\ReviewAggregate;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Read side of the public review feed. APPROVED rows only — `pending` and
 * `rejected` are unreachable from here, which is why the public endpoint has
 * no status parameter to pass.
 *
 * The `stars` and `q` filters narrow the LIST only. The aggregate and the
 * distribution bars always describe the whole approved set, so filtering to
 * 1-star never makes the product look like it averages 1 star.
 */
class ReviewFeed
{
    public const SORTS = ['helpful', 'newest', 'highest', 'lowest'];

    public const DEFAULT_SORT = 'helpful';

    public const PER_PAGE = 10;

    public const MAX_PER_PAGE = 50;

    /**
     * @param  array{sort?: ?string, stars?: ?int, q?: ?string, cursor?: ?string, perPage?: ?int}  $options
     */
    public function forProduct(int $productId, array $options = []): CursorPaginator
    {
        $sort = in_array($options['sort'] ?? null, self::SORTS, true)
            ? $options['sort']
            : self::DEFAULT_SORT;

        $query = Review::query()
            ->approved()
            ->where('product_id', $productId)
            ->with([
                'customer:id,name',
                'reply',
                'categoryScores',
                // Approved media only, carrying the asset columns the resource
                // needs to decide whether a real URL exists to publish.
                'media' => fn ($q) => $q
                    ->where('review_media.status', Review::APPROVED)
                    ->join('assets', 'assets.id', '=', 'review_media.asset_id')
                    ->select('review_media.*', 'assets.provider', 'assets.provider_ref', 'assets.visibility')
                    ->orderBy('review_media.position'),
            ]);

        $stars = $options['stars'] ?? null;
        if ($stars !== null && $stars >= 1 && $stars <= 5) {
            $query->where('rating', $stars);
        }

        $term = trim((string) ($options['q'] ?? ''));
        if ($term !== '') {
            $escaped = addcslashes($term, '%_\\');
            $query->where(fn ($q) => $q
                ->where('title', 'like', "%{$escaped}%")
                ->orWhere('body', 'like', "%{$escaped}%"));
        }

        // Every sort ends in `id` so the cursor is total-ordered and a review
        // can never be skipped or repeated across pages.
        match ($sort) {
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'highest' => $query->orderByDesc('rating')->orderByDesc('helpful_count')->orderByDesc('id'),
            'lowest' => $query->orderBy('rating')->orderByDesc('helpful_count')->orderByDesc('id'),
            default => $query->orderByDesc('helpful_count')->orderByDesc('id'),
        };

        $perPage = (int) ($options['perPage'] ?? self::PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        return $query->cursorPaginate($perPage, ['*'], 'cursor', $options['cursor'] ?? null);
    }

    /** Null until the product has at least one approved review — never zeros. */
    public function aggregate(int $productId): ?ReviewAggregate
    {
        return ReviewAggregate::query()->where('product_id', $productId)->first();
    }

    /**
     * Average per criterion across approved reviews that scored it. A criterion
     * nobody scored is ABSENT from the result, not zero.
     *
     * @return array<string, array{avg: float, count: int}>
     */
    public function categoryAverages(int $productId): array
    {
        $rows = Review::query()
            ->approved()
            ->where('reviews.product_id', $productId)
            ->join('review_category_scores', 'review_category_scores.review_id', '=', 'reviews.id')
            ->groupBy('review_category_scores.criterion')
            ->selectRaw('review_category_scores.criterion as criterion, avg(review_category_scores.score) as avg_score, count(*) as n')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->criterion] = ['avg' => round((float) $row->avg_score, 2), 'count' => (int) $row->n];
        }

        return $out;
    }
}
