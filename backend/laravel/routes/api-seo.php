<?php

// مسارات نطاق seo — يملكها بنّاء seo حصريًا. تُضمَّن من api.php.
// SEO domain routes — owned exclusively by the SEO builder.

use App\Http\Controllers\Api\V1\LlmsController;
use App\Http\Controllers\Api\V1\RedirectController;
use App\Http\Controllers\Api\V1\RobotsController;
use App\Http\Controllers\Api\V1\SeoController;
use App\Http\Controllers\Api\V1\SitemapController;
use App\Http\Controllers\Api\V1\UrlResolveController;
use Illuminate\Support\Facades\Route;

// خرائط الموقع — JSON، والمتجر يحوّلها إلى XML.
// خرائط الموقع وllms-data تبني ردودًا كبيرة ⇒ حدّ باهظ.
Route::middleware('throttle:expensive')->group(function () {
    Route::get('/sitemap/index', [SitemapController::class, 'index']);
    Route::get('/sitemap/{provider}', [SitemapController::class, 'show'])
        ->where('provider', '[a-z-]+');

    // فهرس الكتالوج للنماذج اللغوية (llms.txt).
    Route::get('/llms-data', LlmsController::class);
});

// robots ومفتّش قابلية الفهرسة — من السياسة نفسها التي تحكم الخرائط.
Route::middleware('throttle:read')->group(function () {
    Route::get('/robots', [RobotsController::class, 'policy']);
    Route::get('/seo/indexability', [RobotsController::class, 'inspect']);

    // وسوم صفحة المنتج + المحتوى المرئي + الرسم البياني في استجابة واحدة.
    Route::get('/seo/products/{slug}', [SeoController::class, 'product']);

    // التحويلات: تُستشار قبل عرض 404.
    Route::get('/redirects/resolve', [RedirectController::class, 'resolve']);
    Route::get('/redirects/rules', [RedirectController::class, 'rules']);

    // منصّة العناوين: حلٌّ مشروح للمسار — كيان أو تحويل أو ٤١٠ أو ٤٠٤ باقتراحات.
    Route::get('/url/resolve', [UrlResolveController::class, 'resolve']);
});

// تبليغ 404 — مُقيَّد المعدل: نقطة كتابة عامة، فلا تُترك مفتوحة للإغراق.
Route::post('/not-found', [RedirectController::class, 'reportNotFound'])
    ->middleware('throttle:telemetry');
