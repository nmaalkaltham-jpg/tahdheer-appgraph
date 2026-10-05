<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Search\Analytics\SearchTracking;
use App\Domain\Search\ArabicNormalizer;
use App\Domain\Search\QueryPipeline;
use App\Http\Controllers\Controller;
use App\Models\SearchQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * اختيارُ اقتراحٍ من الإكمال التلقائيّ — بحثٌ من مصدر `autocomplete` ونقرتُه.
 *
 * ضغطاتُ المفاتيح لا تُسجَّل عمدًا (`QueryPipeline::suggest`): أنصافُ الكلمات
 * تُغرق «الأكثر بحثًا». الاختيارُ وحده حدثٌ له معنى، فيُسجَّل بحثًا واحدًا
 * ونقرةً عليه، فيُسند إليه ما يتبعه من سلّةٍ وشراء في الجلسة نفسها.
 *
 * والتحقّق من الخادم: الاقتراحُ يُعاد حسابُه للنصّ المُرسَل، ولا يُقبل منتجٌ لم
 * يكن بين المقترَح — فلا يُصنع إسنادٌ لمنتجٍ لم يُعرض. الردّ 204 دائمًا: القياس
 * لا يُعطّل تصفّحًا ولا يكشف شيئًا.
 */
class SearchSuggestSelectController extends Controller
{
    public function __invoke(Request $request, QueryPipeline $pipeline, SearchTracking $tracking): Response
    {
        $data = $request->validate([
            'q' => 'required|string|max:200',
            'productId' => 'required|integer|min:1',
        ]);

        $t0 = hrtime(true);
        $ids = $pipeline->suggest($data['q'], 3)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $position = array_search((int) $data['productId'], $ids, true);
        if ($position === false) {
            return response()->noContent();
        }

        $query = SearchQuery::query()->create([
            'raw_query' => mb_substr($data['q'], 0, 500),
            'normalized_query' => mb_substr(ArabicNormalizer::normalize($data['q']), 0, 500),
            'results_count' => count($ids),
            'zero_result' => false,
            'provider' => 'suggest',
            'degraded' => false,
            'latency_ms' => (int) round((hrtime(true) - $t0) / 1e6),
            'filters' => ['page' => 1, 'perPage' => 3],
            'result_ids' => $ids,
            'source' => 'autocomplete',
            'created_at' => now(),
        ]);
        $tracking->click($request, $query, (int) $data['productId'], $position + 1, 'pdp');

        return response()->noContent();
    }
}
