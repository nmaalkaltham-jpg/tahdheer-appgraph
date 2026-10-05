<?php

namespace App\Domain\Sitemaps\Providers;

use App\Domain\SEO\IndexabilityPolicy;
use App\Domain\SEO\RouteRegistry;
use App\Domain\SEO\TaxonomyPath;
use App\Domain\Sitemaps\SitemapEntry;
use App\Domain\Sitemaps\SitemapProvider;
use App\Models\Grade;
use App\Models\Kind;
use App\Models\Product;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Generator;
use Illuminate\Database\Eloquent\Model;

/**
 * أرشيفات المنهج: المراحل، الصفوف، المواد، الفصول، الأنواع.
 *
 * One class covers all five dimensions because the rule is identical: an
 * archive URL is eligible only when its real inventory clears the §7.2
 * threshold (≥3 products). A dimension whose storefront route is not built is
 * dormant with an explicit reason — it never emits a URL that would 404.
 */
final class CurriculumProvider implements SitemapProvider
{
    /**
     * dimension => [model, url prefix, product foreign key, Arabic label].
     *
     * `kinds` has no foreign key on products; the catalog stores the kind as
     * the `resource_type` string, so its count matches on that column.
     *
     * @var array<string, array{0: class-string<Model>, 1: string, 2: ?string, 3: string}>
     */
    private const DIMENSIONS = [
        'stages' => [Stage::class, '/stages/', 'stage_id', 'المراحل'],
        'grades' => [Grade::class, '/grades/', 'grade_id', 'الصفوف'],
        'subjects' => [Subject::class, '/subjects/', 'subject_id', 'المواد'],
        'semesters' => [Semester::class, '/semesters/', 'semester_id', 'الفصول الدراسية'],
        'kinds' => [Kind::class, '/kinds/', null, 'أنواع الموارد'],
    ];

    public function __construct(
        private readonly string $dimension,
        private readonly IndexabilityPolicy $policy,
        private readonly RouteRegistry $routes,
    ) {
        if (! isset(self::DIMENSIONS[$dimension])) {
            throw new \InvalidArgumentException('بُعد منهج غير معروف: '.$dimension);
        }
    }

    /** @return list<string> */
    public static function dimensions(): array
    {
        return array_keys(self::DIMENSIONS);
    }

    public function name(): string
    {
        return $this->dimension;
    }

    public function label(): string
    {
        return self::DIMENSIONS[$this->dimension][3];
    }

    public function isEnabled(): bool
    {
        return $this->routes->isLive($this->dimension);
    }

    public function dormantReason(): ?string
    {
        return $this->routes->dormantReason($this->dimension);
    }

    public function entries(): Generator
    {
        if (! $this->isEnabled()) {
            return;
        }

        [$model, $prefix, $foreignKey] = self::DIMENSIONS[$this->dimension];

        $counts = $this->productCounts($foreignKey);

        foreach ($model::query()->orderBy('id')->lazyById(500) as $term) {
            $key = $foreignKey === null ? (string) $term->name_ar : (string) $term->id;
            $count = $counts[$key] ?? 0;
            $path = TaxonomyPath::join($prefix, (string) $term->slug);

            // Thin archives are `monitor`, not `eligible` — watched, not shipped.
            if (! $this->policy->forCollection($path, $count)->isEligible()) {
                continue;
            }

            yield new SitemapEntry(
                loc: $path,
                // The inventory count is part of the content: an archive whose
                // product list changed genuinely changed.
                contentHash: SitemapEntry::hash($term->name_ar, $term->slug, $count),
            );
        }
    }

    /**
     * Published product counts per term, in ONE grouped query.
     *
     * @return array<string, int>
     */
    private function productCounts(?string $foreignKey): array
    {
        $column = $foreignKey ?? 'resource_type';

        return Product::query()
            ->published()
            ->whereNotNull($column)
            ->groupBy($column)
            ->selectRaw($column.' as term_key, count(*) as aggregate')
            ->pluck('aggregate', 'term_key')
            ->map(static fn ($count) => (int) $count)
            ->all();
    }
}
