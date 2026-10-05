<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\NavigationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Navigation
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::middleware(AdminPermission::AppearanceManage->middleware())->group(function () {
    Route::get('navigation', [NavigationController::class, 'index']);
    Route::get('navigation/pages', [NavigationController::class, 'pages']);
    Route::post('navigation/link-check', [NavigationController::class, 'linkCheck'])
        ->middleware('throttle:link-check');
    Route::get('navigation/{location}', [NavigationController::class, 'show'])
        ->where(['location' => '[a-z]+']);
    Route::patch('navigation/{location}', [NavigationController::class, 'update'])
        ->where(['location' => '[a-z]+']);
    Route::post('navigation/{location}/publish', [NavigationController::class, 'publish'])
        ->where(['location' => '[a-z]+']);
    Route::post('navigation/{location}/rollback', [NavigationController::class, 'rollback'])
        ->where(['location' => '[a-z]+']);
    Route::get('navigation/{location}/operations/{operationId}', [NavigationController::class, 'operation'])
        ->where(['location' => '[a-z]+']);
});
