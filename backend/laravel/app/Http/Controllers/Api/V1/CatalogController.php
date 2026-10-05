<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Kind;
use App\Models\Product;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    private const DIMENSIONS = [
        'stages' => Stage::class,
        'grades' => Grade::class,
        'subjects' => Subject::class,
        'semesters' => Semester::class,
        'kinds' => Kind::class,
    ];

    /** Foreign key on `products` for each dimension, used for the live count. */
    private const PRODUCT_KEYS = [
        'stages' => 'stage_id',
        'grades' => 'grade_id',
        'subjects' => 'subject_id',
        'semesters' => 'semester_id',
    ];

    public function __invoke(string $dimension): JsonResponse
    {
        /** @var class-string<Model> $model */
        $model = self::DIMENSIONS[$dimension];

        $query = $model::query()->orderBy('position');
        if ($dimension === 'grades') {
            $query->with('stage:id,slug,name_ar');
        }

        $counts = $dimension === 'kinds' ? $this->kindCounts() : $this->publishedCounts($dimension);
        $ranges = $dimension === 'stages' ? $this->gradeRanges() : [];

        return response()->json([
            'data' => $query->get()->map(function ($term) use ($dimension, $counts, $ranges) {
                $row = [
                    'id' => $term->id,
                    'slug' => $term->slug,
                    'name' => $term->name_ar,
                    'position' => $term->position,
                ];

                // Live count of PUBLISHED products. The stage/subject tiles in the
                // design carry a file count; emitting it here keeps that number
                // derived from the catalog instead of typed into the storefront.
                // Absent (not zero) when the dimension has no product link at all.
                if (isset($counts[$term->id])) {
                    $row['productsCount'] = $counts[$term->id];
                } elseif (array_key_exists($dimension, self::PRODUCT_KEYS) || $dimension === 'kinds') {
                    $row['productsCount'] = 0;
                }

                // «الصف ١ – ٦» under a stage name: the real span of its grades,
                // never a hand-written label.
                if (isset($ranges[$term->id])) {
                    $row['subtitle'] = $ranges[$term->id];
                }

                if ($dimension === 'grades') {
                    $row['stage'] = [
                        'id' => $term->stage->id,
                        'slug' => $term->stage->slug,
                        'name' => $term->stage->name_ar,
                    ];
                }

                return $row;
            }),
        ]);
    }

    /**
     * Published-product counts keyed by term id.
     *
     * @return array<int,int>
     */
    private function publishedCounts(string $dimension): array
    {
        $key = self::PRODUCT_KEYS[$dimension] ?? null;
        if ($key === null) {
            return [];
        }

        return Product::query()
            ->published()
            ->whereNotNull($key)
            ->selectRaw("{$key} as term_id, COUNT(*) as total")
            ->groupBy($key)
            ->pluck('total', 'term_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * عدد المنتجات المنشورة لكلّ نوع. النوع لا مفتاحَ أجنبيًّا له: المنتج يحمل
     * `resource_type` نصًّا، ويُطابَق بمعرّف النوع أو باسمه العربيّ.
     *
     * @return array<int,int>
     */
    private function kindCounts(): array
    {
        $byType = Product::query()
            ->published()
            ->whereNotNull('resource_type')
            ->selectRaw('resource_type, COUNT(*) as total')
            ->groupBy('resource_type')
            ->pluck('total', 'resource_type');

        $counts = [];
        foreach (Kind::query()->get(['id', 'slug', 'name_ar']) as $kind) {
            $total = (int) ($byType[$kind->slug] ?? $byType[$kind->name_ar] ?? 0);
            if ($total > 0) {
                $counts[$kind->id] = $total;
            }
        }

        return $counts;
    }

    /**
     * Grade span per stage, e.g. «الصف الأول الابتدائي – الصف السادس الابتدائي».
     *
     * @return array<int,string>
     */
    private function gradeRanges(): array
    {
        $ranges = [];

        Grade::query()
            ->orderBy('position')
            ->get(['id', 'stage_id', 'name_ar'])
            ->groupBy('stage_id')
            ->each(function ($grades, $stageId) use (&$ranges) {
                $first = $grades->first();
                $last = $grades->last();
                if ($first === null) {
                    return;
                }
                $ranges[(int) $stageId] = $first->id === $last->id
                    ? $first->name_ar
                    : $first->name_ar.' – '.$last->name_ar;
            });

        return $ranges;
    }
}
