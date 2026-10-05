<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Theme\ThemeRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * الثيم المُترجَم للواجهة العامّة.
 *
 * يُطلب في كل تصيير للمتجر، فالردّ مُعَدّ للتخزين المؤقّت: ETag من بصمة CSS
 * نفسه، فأي تعديل في اللوحة يبدّل البصمة ويُبطل النسخ المخزَّنة دون انتظار
 * انتهاء مدّة. لا شيء هنا خاصّ بمستخدم، فالتخزين العامّ آمن.
 */
final class ThemeController
{
    public function __invoke(Request $request): JsonResponse
    {
        $compiled = ThemeRepository::compiled();
        $etag = '"'.$compiled['etag'].'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->json(null, 304)->setEtag($compiled['etag']);
        }

        return response()
            ->json([
                'data' => [
                    'css' => $compiled['css'],
                    'fontsUrl' => $compiled['fontsUrl'],
                    'etag' => $compiled['etag'],
                ],
            ])
            ->setEtag($compiled['etag'])
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=600');
    }
}
