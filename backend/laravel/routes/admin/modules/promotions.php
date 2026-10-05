<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Promotions
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::prefix('coupons')
    ->middleware(AdminPermission::PromotionsManage->middleware())
    ->group(function () {
        Route::get('/', [CouponController::class, 'index']);
        Route::post('/', [CouponController::class, 'store']);
        Route::patch('/{coupon}', [CouponController::class, 'update']);
        Route::post('/{coupon}/expire', [CouponController::class, 'expire']);
        Route::delete('/{coupon}', [CouponController::class, 'destroy']);
    });

/*
 * مستخدمو اللوحة. صلاحية `users.manage` يملكها المالك وحده —
 * والقراءة تحتها أيضًا: قائمةُ من يملك الوصول إلى المتجر وبأيّ
 * دور هي بذاتها معلومةٌ حسّاسة، تُقرأ لتُخطَّط بها هجمة اجتماعية
 * قبل أن تُقرأ لتُدار. من لا يستطيع تغييرها لا يحتاج رؤيتها.
 */
