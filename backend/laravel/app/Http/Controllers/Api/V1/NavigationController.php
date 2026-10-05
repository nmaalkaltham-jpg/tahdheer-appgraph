<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Navigation\MenuTree;
use App\Domain\Navigation\NavigationCache;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * القائمة المنشورة لمكان في المتجر.
 *
 * الغياب إجابة أولى الدرجة: قائمة لم تُنشر تُعيد مصفوفة فارغة، والمتجر يعود
 * إلى قائمته المدمجة. لا يُعاد نصف قائمة ولا عناصر افتراضية مخترَعة.
 */
class NavigationController extends Controller
{
    public function show(string $location): JsonResponse
    {
        abort_unless(array_key_exists($location, MenuTree::LOCATIONS), 404);

        $envelope = NavigationCache::envelope($location);
        $inherited = false;

        /*
         * الوراثة: قائمة الجوّال غير المنشورة تتبع الترويسة. الفارغة تعني
         * «لم تُنشر قائمة» — والوراثة تُعلَن في `meta.inherited` لا تُخفى:
         * مشغّلٌ ينشر الجوّال ولا يرى فرقًا يحتاج أن يعرف أنّ ما يُخدَم هو
         * الترويسة، لا أن يظنّ نشره ضاع.
         */
        if ($envelope['nodes'] === [] && isset(MenuTree::FALLS_BACK_TO[$location])) {
            $envelope = NavigationCache::envelope(MenuTree::FALLS_BACK_TO[$location]);
            $inherited = $envelope['nodes'] !== [];
        }

        /*
         * `meta` هو ما يجعل النشر قابلًا للتحقّق.
         *
         * كانت الاستجابة مصفوفةً عاريةً: يقرؤها المتجر ولا يستطيع أن يقول
         * أيّ إصدارٍ يعرض، ولا يستطيع الناشر أن يُثبت أنّ ما نشره هو ما
         * يُخدَم. الرقم والبصمة هما طرفا المقارنة في كشف الانحراف.
         */
        return response()->json([
            'data' => $envelope['nodes'],
            'meta' => [
                'schema' => NavigationCache::SCHEMA,
                'location' => $location,
                'revision' => $envelope['revision'],
                'fingerprint' => $envelope['fingerprint'],
                'publishedAt' => $envelope['publishedAt'],
                'inherited' => $inherited,
            ],
        ]);
    }
}
