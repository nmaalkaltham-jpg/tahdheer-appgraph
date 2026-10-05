<?php

namespace App\Http\Resources;

use App\Domain\Reviews\AggregateRebuilder;
use App\Domain\Reviews\Models\ReviewAggregate;
use App\Domain\Reviews\Models\ReviewCategoryScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The product's rating summary. Emitted only when an aggregate row exists —
 * a product with no approved reviews returns `aggregate: null`, and the UI
 * shows the invitation to be first rather than «0.0 من 5».
 *
 * `schemaEligible` carries the §4.7 rule to the renderer: AggregateRating
 * structured data is permitted only at 3 or more real reviews.
 *
 * @mixin ReviewAggregate
 */
class ReviewAggregateResource extends JsonResource
{
    /**
     * @param  array<string, array{avg: float, count: int}>  $categoryAverages
     */
    public function __construct($resource, private readonly array $categoryAverages = [])
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $out = [
            'avgRating' => (float) $this->avg_rating,
            'count' => $this->count,
            'recommendPct' => $this->recommend_pct,
            'schemaEligible' => $this->count >= ReviewAggregate::SCHEMA_MIN_COUNT,
            'distribution' => self::bars($this->distribution),
        ];

        $categories = $this->categories();
        if ($categories !== []) {
            $out['categoryScores'] = $categories;
        }

        return $out;
    }

    /**
     * Always all five buckets, high to low, so the bar list never shifts as
     * ratings arrive. Percentages come from the stored largest-remainder
     * computation and sum to exactly 100.
     *
     * @param  array<int|string, array{count: int, pct: int}>|null  $stored
     * @return list<array{stars: int, count: int, pct: int}>
     */
    public static function bars(?array $stored): array
    {
        $bars = [];

        foreach (AggregateRebuilder::STARS as $star) {
            $row = $stored[$star] ?? null;
            $bars[] = [
                'stars' => $star,
                'count' => (int) ($row['count'] ?? 0),
                'pct' => (int) ($row['pct'] ?? 0),
            ];
        }

        return $bars;
    }

    /**
     * @return list<array{criterion: string, label: string, avg: float, count: int}>
     */
    private function categories(): array
    {
        $out = [];

        // Fixed criterion order (§4.7), skipping any nobody has scored.
        foreach (ReviewCategoryScore::CRITERIA as $criterion => $label) {
            if (! isset($this->categoryAverages[$criterion])) {
                continue;
            }
            $out[] = [
                'criterion' => $criterion,
                'label' => $label,
                'avg' => $this->categoryAverages[$criterion]['avg'],
                'count' => $this->categoryAverages[$criterion]['count'],
            ];
        }

        return $out;
    }
}
