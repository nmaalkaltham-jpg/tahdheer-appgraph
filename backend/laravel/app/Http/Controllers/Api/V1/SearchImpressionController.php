<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Search\Analytics\SearchTracking;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ظهوراتُ نتائج البحث — دفعاتٌ من المتصفّح لما بلغ نصفُه مجالَ الرؤية.
 *
 * قياسٌ لا معاملة: الحمولة المُلفَّقة (رمزٌ خاطئ، بحثٌ قديم، منتجٌ ليس في نتائجه)
 * تُهمَل بصمت بردّ 204، فلا يتعلّم العابث ما يُقبل. والسياسة والتكرار في
 * `SearchTracking`.
 */
class SearchImpressionController extends Controller
{
    public function __invoke(Request $request, SearchTracking $tracking): Response
    {
        $data = $request->validate([
            'queryId' => 'required|integer|min:1',
            'token' => 'required|string|size:64',
            'productIds' => 'required|array|min:1|max:'.SearchTracking::MAX_BATCH,
            'productIds.*' => 'integer|min:1',
        ]);

        if (($query = $tracking->verified((int) $data['queryId'], $data['token'])) !== null) {
            $tracking->impressions($request, $query, array_map('intval', $data['productIds']));
        }

        return response()->noContent();
    }
}
