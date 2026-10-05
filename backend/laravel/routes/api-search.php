<?php

// مسارات نطاق البحث — يملكها بنّاء البحث حصريًا. تُضمَّن من api.php.
// Search domain routes — owned exclusively by the search builder.

use App\Http\Controllers\Api\V1\Admin\SearchIntelligenceController;
use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\SearchClickController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SearchImpressionController;
use App\Http\Controllers\Api\V1\SearchSuggestController;
use App\Http\Controllers\Api\V1\SearchSuggestSelectController;
use App\Http\Middleware\AdminAuthenticate;
use App\Http\Middleware\AdminAuthorize;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/*
 * مساعد المتجر — بأشدّ مُحدِّدٍ في المشروع (٢٠/دقيقة).
 *
 * كلّ طلبٍ هنا ثانيتان من معالجٍ على خادمٍ بخمسة أنوية، فالإغراق يُسقط المتجر
 * لا المساعد. وهو مع البحث لأنّ استرجاعه يمرّ بالبحث نفسه: لا فهرسَ ثانٍ ولا
 * مصدرَ حقيقةٍ ثانٍ.
 */
Route::middleware('throttle:expensive')->group(function () {
    Route::post('/assistant/ask', [AssistantController::class, 'ask']);
});

// كل مسارات /search مُعرَّفة هنا حصريًّا — لا تسجيل ثانٍ في api.php.
// البحث يستدعي خدمة خارجية ⇒ حدّ أضيق من القراءة العادية.
Route::middleware('throttle:search')->group(function () {
    Route::get('/search', SearchController::class);
    Route::get('/search/suggest', SearchSuggestController::class);
});

// قياس لا معاملة: حدّ telemetry الأضيق — فيضانه يلوّث التحليلات لا القاعدة.
Route::middleware('throttle:telemetry')->post('/search/click', SearchClickController::class);
Route::middleware('throttle:telemetry')->post('/search/impressions', SearchImpressionController::class);
Route::middleware('throttle:telemetry')->post('/search/suggest/select', SearchSuggestSelectController::class);

/*
 * ذكاءُ البحث للإدارة — هنا لا في api-admin.php (ملفُّ نطاقٍ آخر): السلسلة
 * نفسها حرفًا بحرف (جلسة ← مصادقة المشرف ← حدّ admin ← صلاحية analytics.read)،
 * فيحرسها RoutePermissionScopeTest كما يحرس بقيّة مسارات /admin.
 */
Route::prefix('admin')
    ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class])
    ->group(function () {
        Route::middleware([AdminAuthenticate::class, 'throttle:admin'])->group(function () {
            Route::get('/search/intelligence', SearchIntelligenceController::class)
                ->middleware(AdminAuthorize::class.':analytics.read');
        });
    });
