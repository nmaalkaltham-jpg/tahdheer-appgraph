<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\EntitlementController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Commerce
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('/orders', [OrderController::class, 'index'])
    ->middleware(AdminPermission::OrdersRead->middleware());
Route::get('/orders/{id}', [OrderController::class, 'show'])
    ->whereNumber('id')->middleware(AdminPermission::OrdersRead->middleware());
Route::post('/orders/{id}/refund', [OrderController::class, 'refund'])
    ->whereNumber('id')->middleware(AdminPermission::OrdersRefund->middleware());

Route::get('/customers', [CustomerController::class, 'index'])
    ->middleware(AdminPermission::CustomersRead->middleware());
Route::get('/customers/{id}', [CustomerController::class, 'show'])
    ->whereNumber('id')->middleware(AdminPermission::CustomersRead->middleware());

Route::post('/entitlements/{id}/revoke', [EntitlementController::class, 'revoke'])
    ->whereNumber('id')->middleware(AdminPermission::EntitlementsRevoke->middleware());

/*
 * منصّة العمليّات — ما بين «دُفع» و«وصل».
 *
 * لا صلاحيّة جديدة عمدًا: استحداث `operations.read` يوجب ترحيل
 * الأدوار القائمة ويحجب السطح عن كلّ مشغّلٍ حتّى تُمنح. القراءة
 * تتبع النطاق الأقرب — الطلبات للتنفيذ والشحن والإرجاع
 * والاشتراكات، والمنتجات للمخزون ومصادر الأصول.
 */
