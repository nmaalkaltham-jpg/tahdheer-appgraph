<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Curriculum\CurriculumHubs;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * الصفحات المحورية للمنهج — القائمة والعنقود الواحد.
 *
 * العنقود دون العتبة ليس «صفحة فارغة» بل غير موجود: يُردّ 404 صريحًا كي لا
 * يبني المتجر صفحةً تعلن أنها تجمع ملفات ولا تجمع منها ثلاثة.
 */
class CurriculumController extends Controller
{
    public function __construct(private readonly CurriculumHubs $hubs) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->hubs->eligible()]);
    }

    public function show(string $type, string $slug): JsonResponse
    {
        $hub = $this->hubs->find($type, rawurldecode($slug));

        if ($hub === null) {
            return response()->json([
                'error' => [
                    'code' => 'HUB_NOT_ELIGIBLE',
                    'message' => 'لا توجد صفحة محورية لهذا التقاطع — العنقود دون ثلاثة منتجات منشورة.',
                ],
            ], 404);
        }

        return response()->json(['data' => $hub]);
    }
}
