<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\InsightsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Insights
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('/insights', [InsightsController::class, 'index'])
    ->middleware(AdminPermission::AnalyticsRead->middleware());
Route::get('/insights/{domain}', [InsightsController::class, 'show'])
    ->middleware(AdminPermission::AnalyticsRead->middleware());

/*
 * عدّادات التشغيل — شارات الشريط ولوحة الانتباه من مصدرٍ واحد.
 *
 * `orders.read` لا `analytics.read`: هذه ملاحةُ نوبةٍ لا تقرير
 * أرقام، ومن يعالج الطلبات يجب أن يرى ما ينتظره.
 */
