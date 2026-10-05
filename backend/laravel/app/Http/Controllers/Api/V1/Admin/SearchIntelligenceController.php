<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminValidation;
use App\Domain\Search\Analytics\SearchIntelligence;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ذكاءُ البحث للإدارة — قمعٌ ومحاسبةٌ من أحداثٍ حقيقيّة (`SearchIntelligence`).
 *
 * النافذة بالأيّام التقويميّة شاملةً طرفيها. ولا عيّنات: قاعدةٌ بلا أحداث تُعيد
 * أصفارًا ونِسَبًا فارغة (null)، والواجهة تقول «لا بيانات» لا ٠٪.
 */
class SearchIntelligenceController extends Controller
{
    public function __invoke(Request $request, SearchIntelligence $intelligence): JsonResponse
    {
        $data = $request->validate([
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => 'sometimes|date_format:Y-m-d|after_or_equal:from',
            'source' => 'sometimes|nullable|string|in:search,browse,autocomplete',
            'device' => 'sometimes|nullable|string|in:desktop,mobile,tablet',
            'provider' => 'sometimes|nullable|string|in:pg,meilisearch',
        ], AdminValidation::MESSAGES);

        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : CarbonImmutable::now()->endOfDay();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : $to->subDays(29)->startOfDay();

        return response()->json(['data' => $intelligence->report($from, $to->addSecond(), [
            'source' => $data['source'] ?? null,
            'device' => $data['device'] ?? null,
            'provider' => $data['provider'] ?? null,
        ])]);
    }
}
