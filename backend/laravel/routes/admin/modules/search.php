<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\SearchController;
use App\Http\Controllers\Api\V1\Admin\SearchExplainController;
use App\Http\Controllers\Api\V1\Admin\SearchIndexController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Search
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('/search/queries', [SearchController::class, 'queries'])
    ->middleware(AdminPermission::AnalyticsRead->middleware());
Route::get('/search/synonyms', [SearchController::class, 'synonyms'])
    ->middleware(AdminPermission::SearchSynonymsManage->middleware());
Route::post('/search/synonyms', [SearchController::class, 'storeSynonym'])
    ->middleware(AdminPermission::SearchSynonymsManage->middleware());
Route::patch('/search/synonyms/{id}', [SearchController::class, 'updateSynonym'])
    ->whereNumber('id')->middleware(AdminPermission::SearchSynonymsManage->middleware());

// فهرس البحث: الحالة للقراءة العامّة إداريًّا، والبناء بصلاحيته.
Route::get('/search/index', [SearchIndexController::class, 'show'])
    ->middleware(AdminPermission::AnalyticsRead->middleware());
Route::post('/search/index/reindex', [SearchIndexController::class, 'reindex'])
    ->middleware(AdminPermission::SearchIndexManage->middleware());
Route::post('/search/index/rollback', [SearchIndexController::class, 'rollback'])
    ->middleware(AdminPermission::SearchIndexManage->middleware());

/*
 * مصحّح الصلة: قراءةٌ تشخيصيّة محضة، فصلاحيتها صلاحية قراءة حالة
 * الفهرس أعلاه — `analytics.read`. ولم تُفرد لها صلاحيةٌ جديدة:
 * صلاحيةٌ لا يملكها دورٌ قائم تعني شاشةً يراها الجميع ٤٠٣.
 */
Route::get('/search/explain', [SearchExplainController::class, 'show'])
    ->middleware(AdminPermission::AnalyticsRead->middleware());

/*
 * لغات المتجر. القراءة تحليلات، والتفعيل والترتيب إعداد متجر
 * كالمظهر. is_routable وis_default حقيقتا خادم — إرسالهما 422.
 */
