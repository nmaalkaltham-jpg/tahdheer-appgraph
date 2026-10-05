<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\PaymentOperationsController;
use App\Http\Controllers\Api\V1\Admin\ShippingController;
use App\Http\Controllers\Api\V1\Admin\SystemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Platform
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('/system', [SystemController::class, 'health'])
    ->middleware(AdminPermission::AuditRead->middleware());
Route::get('/system/outbox/dead', [SystemController::class, 'deadEvents'])
    ->middleware(AdminPermission::AuditRead->middleware());
/*
 * `whereUuid` لا `whereNumber`: مفتاح صندوق الصادر UUID. والقيد
 * الخاطئ لا يُنتج خطأً مفهومًا — يُنتج ٤٠٤ على معرّفٍ صحيح.
 */
Route::post('/system/outbox/{id}/replay', [SystemController::class, 'replayOutboxEvent'])
    ->whereUuid('id')->middleware(AdminPermission::SystemManage->middleware());

/*
 * الشحن: القراءة والمعاينة بصلاحية الطلبات لأنّهما قراءتان،
 * والكتابة بصلاحيةٍ خاصّة — تغييرُ سعر شحنٍ قرارٌ تجاريّ.
 */
Route::get('/shipping', [ShippingController::class, 'index'])
    ->middleware(AdminPermission::OrdersRead->middleware());
Route::post('/shipping/preview', [ShippingController::class, 'preview'])
    ->middleware(AdminPermission::OrdersRead->middleware());
Route::post('/shipping/zones', [ShippingController::class, 'storeZone'])
    ->middleware(AdminPermission::ShippingManage->middleware());
Route::patch('/shipping/zones/{id}', [ShippingController::class, 'updateZone'])
    ->whereNumber('id')->middleware(AdminPermission::ShippingManage->middleware());
Route::post('/shipping/rates', [ShippingController::class, 'storeRate'])
    ->middleware(AdminPermission::ShippingManage->middleware());
Route::patch('/shipping/rates/{id}', [ShippingController::class, 'updateRate'])
    ->whereNumber('id')->middleware(AdminPermission::ShippingManage->middleware());

Route::get('/payments/webhooks', [PaymentOperationsController::class, 'webhooks'])
    ->middleware(AdminPermission::OrdersRead->middleware());
Route::post('/payments/webhooks/{id}/replay', [PaymentOperationsController::class, 'replayWebhook'])
    ->whereNumber('id')->middleware(AdminPermission::PaymentsManage->middleware());
