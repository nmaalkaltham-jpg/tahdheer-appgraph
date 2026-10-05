<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Users
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::prefix('users')
    ->middleware(AdminPermission::UsersManage->middleware())
    ->group(function () {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::post('/', [AdminUserController::class, 'store']);
        Route::patch('/{id}', [AdminUserController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/password', [AdminUserController::class, 'resetPassword'])
            ->whereNumber('id');
        Route::delete('/{id}', [AdminUserController::class, 'destroy'])->whereNumber('id');
    });

/*
 * الملاحظات. الكتابة خلف `notes.write` بوسيط، والقراءة تُحرَس في
 * المتحكّم نفسه: صلاحيتها تتبع `entityType` في الطلب (طلبٌ أم
 * عميل)، ووسيطٌ واحد لا يستطيع التعبير عن صلاحيتَين.
 */
