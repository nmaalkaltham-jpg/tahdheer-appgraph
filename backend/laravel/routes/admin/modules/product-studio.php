<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\ProductFileController;
use App\Http\Controllers\Api\V1\Admin\ProductLayoutController;
use App\Http\Controllers\Api\V1\Admin\ProductMediaController;
use App\Http\Controllers\Api\V1\Admin\ProductSeoController;
use App\Http\Controllers\Api\V1\Admin\ProductTranslationController;
use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Product Studio
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::prefix('products/{id}')->whereNumber('id')->group(function () {
    Route::middleware(AdminPermission::ProductsRead->middleware())->group(function () {
        Route::get('/files', [ProductFileController::class, 'index']);
        /*
         * صيغ المنتج — القراءة مع بقيّة القراءات، والكتابة أدناه
         * مع بقيّة الكتابات. لا صلاحية جديدة: التأليف تحريرُ
         * منتجٍ لا بابٌ ثانٍ للتفويض.
         */
        Route::get('/variants', [ProductVariantController::class, 'index']);
        Route::get('/media', [ProductMediaController::class, 'index']);
        Route::get('/seo', [ProductSeoController::class, 'show']);
        Route::get('/layout', [ProductLayoutController::class, 'show']);
        Route::get('/coverage', [ProductLayoutController::class, 'coverage']);
        Route::get('/translations', [ProductTranslationController::class, 'index']);
        Route::get('/language-eligibility', [ProductTranslationController::class, 'eligibility']);
    });

    Route::middleware(AdminPermission::ProductsUpdate->middleware())->group(function () {
        Route::post('/variants', [ProductVariantController::class, 'store']);
        Route::patch('/variants/{variantId}', [ProductVariantController::class, 'update'])
            ->whereNumber('variantId');
        Route::delete('/variants/{variantId}', [ProductVariantController::class, 'destroy'])
            ->whereNumber('variantId');

        Route::post('/files', [ProductFileController::class, 'store']);
        Route::patch('/files/{fileId}', [ProductFileController::class, 'update'])->whereNumber('fileId');
        Route::delete('/files/{fileId}', [ProductFileController::class, 'destroy'])->whereNumber('fileId');
        Route::post('/files/reorder', [ProductFileController::class, 'reorder']);

        Route::post('/media', [ProductMediaController::class, 'store']);
        Route::patch('/media/{mediaId}', [ProductMediaController::class, 'update'])->whereNumber('mediaId');
        Route::delete('/media/{mediaId}', [ProductMediaController::class, 'destroy'])->whereNumber('mediaId');
        Route::post('/media/reorder', [ProductMediaController::class, 'reorder']);

        Route::patch('/seo', [ProductSeoController::class, 'update']);
        Route::patch('/layout', [ProductLayoutController::class, 'update']);
        Route::post('/writing/suggest', [ProductLayoutController::class, 'suggest']);
        Route::patch('/translations/{locale}', [ProductTranslationController::class, 'update'])
            ->where('locale', '[a-z]{2}(-[A-Za-z0-9]+)?');
    });

    // النشر صلاحية مستقلّة: من يحرّر ليس بالضرورة من ينشر.
    Route::post('/layout/publish', [ProductLayoutController::class, 'publish'])
        ->middleware(AdminPermission::ProductsPublish->middleware());

    Route::post('/translations/{locale}/publish', [ProductTranslationController::class, 'publish'])
        ->where('locale', '[a-z]{2}(-[A-Za-z0-9]+)?')
        ->middleware(AdminPermission::ProductsPublish->middleware());
});

/*
 * إعدادات المكوّنات. المفتاح يحمل نقطة («collection.0101»)
 * فيلزم whereIn مخفَّف بدل القيد الافتراضي الذي يقطع عندها.
 */
/*
 * الأبطال. المفتاح مقطع مسار بسيط، والنشر تحت صلاحية النشر
 * نفسها التي تحكم المكوّنات.
 */
/*
 * التنقّل. `link-check` يجلب عناوين خارجية، فله حدّ معدّل
 * مستقلّ: حلقة فحص من اللوحة تجعل خادمنا مصدر طلبات لغيرنا.
 */
/*
 * رسم الروابط. القراءة بصلاحية التحليلات، والتشغيل بصلاحية
 * إدارة الفهرس — الزحف عملٌ ثقيل لا قراءة.
 */
/*
 * الطلبات الفائتة. القراءة بصلاحية التحليلات — فهي تقرير طلب،
 * وتغيير الحالة قرار إنتاج فيتطلّب صلاحية إنشاء المنتجات.
 */
/*
 * رموز الخصم. صلاحية واحدة للقراءة والإصدار: عرضُ الرموز بلا
 * قدرة على إصدارها ليس دورًا، والخصم قرار تجاري لا تقرير.
 */
