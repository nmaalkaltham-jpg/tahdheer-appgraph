<?php

namespace App\Domain\Search;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Provider output. `items` are hydrated Product models (price eager-loaded),
 * already ordered by relevance for the requested page. `formatCounts` are
 * REAL counts by format over the filtered result set BEFORE the type tab is
 * applied — they feed the الكل/عروض/PDF/Word tabs; never fabricated.
 */
final class SearchResult
{
    /**
     * @param  Collection<int, Product>  $items
     * @param  array<string, int>  $formatCounts  e.g. ['PPTX' => 3, 'PDF' => 1]
     * @param  array<string, array<string, int>>  $facetCounts  توزيعات التصنيف
     *                                                          (stage/grade/subject/semester ⇒ القيمة كما فُهرست ⇒ العدد) محسوبة على
     *                                                          المجموعة المرشَّحة الحالية. أعداد حقيقية من المحرّك أو من عدٍّ مباشر —
     *                                                          لا أرقام ثابتة تُعرض بجانب المرشّحات.
     */
    public function __construct(
        public readonly Collection $items,
        public readonly int $total,
        public readonly int $totalUnfiltered,
        public readonly array $formatCounts = [],
        public readonly array $facetCounts = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }
}
