<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\ReviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Reviews
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::prefix('reviews')
    ->middleware(AdminPermission::ReviewsModerate->middleware())
    ->group(function () {
        Route::get('/', [ReviewController::class, 'index']);
        Route::post('/{reviewId}/approve', [ReviewController::class, 'approve'])
            ->whereNumber('reviewId');
        Route::post('/{reviewId}/reject', [ReviewController::class, 'reject'])
            ->whereNumber('reviewId');
        Route::post('/{reviewId}/reply', [ReviewController::class, 'reply'])
            ->whereNumber('reviewId');
    });
