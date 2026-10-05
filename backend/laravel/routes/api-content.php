<?php

// مسارات نطاق المحتوى (أقسام الصفحة الرئيسية) — تُضمَّن من api.php.
// Homepage content routes (MIP §3.3). Public, cacheable reads with the same
// budget as the rest of the catalog; every one of them may honestly answer
// «no content» (204 / []) instead of inventing a section.

use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\NavigationController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:read')->prefix('home')->group(function () {
    Route::get('/hero', [HomeController::class, 'hero']);
    Route::get('/promo', [HomeController::class, 'promo']);
    Route::get('/why', [HomeController::class, 'why']);
    Route::get('/benefits', [HomeController::class, 'benefits']);
    Route::get('/reviews', [HomeController::class, 'reviews']);
    Route::get('/faq', [HomeController::class, 'faq']);
    Route::get('/cta', [HomeController::class, 'cta']);
    Route::get('/announcements', [HomeController::class, 'announcements']);
    Route::get('/identity', [HomeController::class, 'identity']);
    Route::get('/layout', [HomeController::class, 'layout']);
});

/*
 * القائمة المنشورة لمكان في المتجر. الفارغة تعني «لم تُنشر قائمة»، والمتجر
 * يعود عندها إلى قائمته المدمجة — لا ترويسة بلا روابط.
 */
Route::middleware('throttle:read')
    ->get('navigation/{location}', [NavigationController::class, 'show'])
    ->where(['location' => '[a-z]+']);
