<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\CrawlController;
use App\Http\Controllers\Api\V1\Admin\DemandController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Demand Crawl
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('demand', [DemandController::class, 'index'])
    ->middleware(AdminPermission::AnalyticsRead->middleware());
Route::patch('demand/{materialRequest}', [DemandController::class, 'update'])
    ->middleware(AdminPermission::ProductsCreate->middleware());

Route::get('crawl', [CrawlController::class, 'show'])
    ->middleware(AdminPermission::AnalyticsRead->middleware());
// عدسة مسار واحد من آخر جولة — الصفحة السليمة غير مرئية في القوائم المسقوفة.
Route::get('crawl/page', [CrawlController::class, 'page'])
    ->middleware(AdminPermission::AnalyticsRead->middleware());
Route::post('crawl/run', [CrawlController::class, 'run'])
    ->middleware(AdminPermission::SearchIndexManage->middleware());

/*
 * ومن هنا تعود `appearance.manage`: القوائم ورموز التصميم وصفحات
 * الاستوديو ووسائطه وقوالبه — كلّها تحرير مظهرٍ بلا صلاحية خاصّة بها.
 */
