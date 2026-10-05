<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Demand\DemandService;
use App\Domain\Demand\EmptyDemandException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/material-requests — «اطلب ملفًا غير متوفّر».
 *
 * نقطة كتابة عامّة، فلها حدّ معدّل: نموذجٌ مفتوح بلا حدّ يصير قناة إغراق
 * تُفسد التقرير الذي بُني الجدول لأجله.
 *
 * تُعيد 201 لطلب جديد و200 لطلب مُدمَج — والفرق مقصود: المتجر يعرض «سُجّل»
 * في الحالتين، ولا يعرضه قبل أن يصله أحدهما.
 */
class MaterialRequestController extends Controller
{
    public function __construct(private readonly DemandService $demand) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stage' => 'nullable|string|max:120',
            'grade' => 'nullable|string|max:120',
            'subject' => 'nullable|string|max:120',
            'semester' => 'nullable|string|max:120',
            'kind' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:1000',
            'contact' => 'nullable|string|max:190',
        ]);

        try {
            $record = $this->demand->record($validated);
        } catch (EmptyDemandException $exception) {
            return response()->json([
                'error' => ['code' => 'EMPTY_REQUEST', 'message' => $exception->getMessage()],
            ], 422);
        }

        return response()->json([
            'recorded' => true,
            // عدد من طلب الشيء نفسه — يُطمئن الطالب أنه ليس وحده، وهو رقم حقيقي.
            'alsoRequested' => $record->hits - 1,
            'contactStored' => $record->contact !== null,
        ], $record->wasRecentlyCreated ? 201 : 200);
    }
}
