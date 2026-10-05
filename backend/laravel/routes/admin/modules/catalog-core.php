<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\ProductBundleController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductDriveController;
use App\Http\Controllers\Api\V1\Admin\ProductTypeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Catalog Core
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('/product-types', [ProductTypeController::class, 'index'])
    ->middleware(AdminPermission::ProductsRead->middleware());

/*
 * §١٣ — ربط ملفّات Google Drive بالمنتج.
 *
 * القراءة والصحّة بـ`products.read`، والربط والاستبدال بـ
 * `products.update`: ربطُ ملفٍّ يُغيّر ما يُسلَّم للمشتري، فهو
 * تحريرُ منتجٍ لا اطّلاع.
 */
/*
 * §٤٣ — مكوّنات الحزمة. القراءة بـ`products.read` والتأليف
 * بـ`products.update`: إضافةُ مكوّنٍ تُغيّر ما يُسلَّم للمشتري.
 */
Route::get('/products/{id}/bundle', [ProductBundleController::class, 'index'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsRead->middleware());
Route::post('/products/{id}/bundle', [ProductBundleController::class, 'store'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsUpdate->middleware());
Route::delete('/products/{id}/bundle/{componentId}', [ProductBundleController::class, 'destroy'])
    ->whereNumber('id')->whereNumber('componentId')
    ->middleware(AdminPermission::ProductsUpdate->middleware());

Route::get('/drive/health', [ProductDriveController::class, 'health'])
    ->middleware(AdminPermission::ProductsRead->middleware());
Route::get('/products/{id}/drive', [ProductDriveController::class, 'index'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsRead->middleware());
Route::post('/products/{id}/drive', [ProductDriveController::class, 'store'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsUpdate->middleware());
Route::patch('/products/{id}/drive/{sourceId}', [ProductDriveController::class, 'replace'])
    ->whereNumber('id')->whereNumber('sourceId')
    ->middleware(AdminPermission::ProductsUpdate->middleware());
Route::get('/products', [ProductController::class, 'index'])
    ->middleware(AdminPermission::ProductsRead->middleware());
Route::post('/products', [ProductController::class, 'store'])
    ->middleware(AdminPermission::ProductsCreate->middleware());
Route::get('/products/{id}', [ProductController::class, 'show'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsRead->middleware());
Route::patch('/products/{id}', [ProductController::class, 'update'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsUpdate->middleware());
/*
 * السحب: `retired` أو `draft` فقط. النشر يبقى على المسار التالي
 * خلف بوابة الجاهزية، فلا باب خلفيّ إليه من هنا.
 */
Route::patch('/products/{id}/withdraw', [ProductController::class, 'withdraw'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsPublish->middleware());
Route::patch('/products/{id}/publish', [ProductController::class, 'publish'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsPublish->middleware());
