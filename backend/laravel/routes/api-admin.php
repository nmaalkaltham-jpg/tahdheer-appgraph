<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AdminPreferencesController;
use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Middleware\AdminAuthenticate;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin API · Composition Root
|--------------------------------------------------------------------------
|
| مسؤولية هذا الملف محدودة عمدًا:
|
|   Session
|      ↓
|   Authentication
|      ↓
|   Rate limiting
|      ↓
|   Domain route modules
|
| كل Domain module يملك Routes وصلاحياته التفصيلية.
|
| لا توجد بوابة Review أو Approval عامة.
| التفويض الحقيقي يبقى في AdminPermission وفي الخادم.
|
*/

Route::prefix('admin')
    ->middleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
    ])
    ->group(function (): void {
        /*
        |--------------------------------------------------------------------------
        | Authentication
        |--------------------------------------------------------------------------
        */

        Route::post(
            '/auth/login',
            [AuthController::class, 'login'],
        )->middleware('throttle:auth');

        /*
        |--------------------------------------------------------------------------
        | Authenticated administration
        |--------------------------------------------------------------------------
        */

        Route::middleware([
            AdminAuthenticate::class,
            'throttle:admin',
        ])->group(function (): void {
            Route::post(
                '/auth/logout',
                [AuthController::class, 'logout'],
            );

            Route::get(
                '/me',
                [AuthController::class, 'me'],
            );

            /*
             * Operator-owned preferences.
             *
             * Authentication is sufficient because the resource is the
             * authenticated operator's own workspace configuration.
             */
            Route::get(
                '/me/preferences',
                [AdminPreferencesController::class, 'show'],
            );

            Route::put(
                '/me/preferences',
                [AdminPreferencesController::class, 'update'],
            );

            /*
            |--------------------------------------------------------------------------
            | Domain modules
            |--------------------------------------------------------------------------
            |
            | Registration order is explicit in routes/admin/modules.php.
            |
            */

            $modules = require __DIR__.'/admin/modules.php';

            foreach ($modules as $module) {
                require __DIR__.'/admin/modules/'.$module.'.php';
            }
        });
    });
