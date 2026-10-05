<?php

namespace App\Domain\Sitemaps\Providers;

use App\Domain\Curriculum\CurriculumHubs;
use App\Domain\SEO\IndexabilityPolicy;
use App\Domain\SEO\RouteRegistry;
use App\Domain\Sitemaps\SitemapEntry;
use App\Domain\Sitemaps\SitemapProvider;
use App\Models\Grade;
use App\Models\Product;
use App\Models\Subject;
use Generator;

/**
 * صفحات المنهج المجمّعة `/curriculum/{type}/{slug}`.
 *
 * MIP §7.2: a hub exists only where the intersection is genuinely populated,
 * and is indexable only at ≥3 products («conditional; 404 below threshold»).
 * The hub set is COMPUTED from real product rows — there is no cartesian
 * expansion of every possible subject×grade pair, which is exactly how a
 * filter explosion starts.
 */
final class CurriculumHubProvider implements SitemapProvider
{
    public function __construct(
        private readonly IndexabilityPolicy $policy,
        private readonly RouteRegistry $routes,
    ) {}

    public function name(): string
    {
        return 'curriculum';
    }

    public function label(): string
    {
        return 'صفحات المنهج';
    }

    public function isEnabled(): bool
    {
        return $this->routes->isLive('curriculum');
    }

    public function dormantReason(): ?string
    {
        return $this->routes->dormantReason('curriculum');
    }

    public function entries(): Generator
    {
        if (! $this->isEnabled()) {
            return;
        }

        $subjects = Subject::query()->pluck('slug', 'id');
        $grades = Grade::query()->pluck('slug', 'id');

        // Only intersections that real published products actually occupy.
        $rows = Product::query()
            ->published()
            ->whereNotNull('subject_id')
            ->whereNotNull('grade_id')
            ->groupBy('subject_id', 'grade_id')
            ->selectRaw('subject_id, grade_id, count(*) as aggregate')
            ->get();

        foreach ($rows as $row) {
            $subjectSlug = $subjects[$row->subject_id] ?? null;
            $gradeSlug = $grades[$row->grade_id] ?? null;

            if ($subjectSlug === null || $gradeSlug === null) {
                continue;
            }

            $count = (int) $row->aggregate;
            // نفس بنّاء المعرّف الذي تطابق عليه الصفحة — وإلّا أعلنت الخريطة
            // عنوانًا لا يجد المتجر عنقودًا له.
            $path = app(CurriculumHubs::class)->pathFor(
                'subject-grade',
                app(CurriculumHubs::class)->slugFor($subjectSlug, $gradeSlug),
            );

            if (! $this->policy->forCollection($path, $count)->isEligible()) {
                continue;
            }

            yield new SitemapEntry(
                loc: $path,
                contentHash: SitemapEntry::hash($subjectSlug, $gradeSlug, $count),
            );
        }
    }
}
