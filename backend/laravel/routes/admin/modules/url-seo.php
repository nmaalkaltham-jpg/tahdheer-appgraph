<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\RedirectAdminController;
use App\Http\Controllers\Api\V1\Admin\SitemapAuditController;
use App\Http\Controllers\Api\V1\Admin\UrlPlatformAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Url Seo
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('/redirects', [RedirectAdminController::class, 'index'])
    ->middleware(AdminPermission::RedirectsManage->middleware());
Route::post('/redirects', [RedirectAdminController::class, 'store'])
    ->middleware(AdminPermission::RedirectsManage->middleware());
Route::patch('/redirects/{id}', [RedirectAdminController::class, 'update'])
    ->whereNumber('id')->middleware(AdminPermission::RedirectsManage->middleware());
Route::delete('/redirects/{id}', [RedirectAdminController::class, 'destroy'])
    ->whereNumber('id')->middleware(AdminPermission::RedirectsManage->middleware());

/*
 * منصّة العناوين: السجلّ والتفتيش والصحّة بصلاحيّة القراءة، وتغيير
 * الروابط بصلاحيّة التحرير — والتجاوز يُفحص داخل الفعل بـ`slug.override`.
 */
Route::get('/url/health', [UrlPlatformAdminController::class, 'health'])
    ->middleware(AdminPermission::UrlRead->middleware());
Route::get('/url/inspect', [UrlPlatformAdminController::class, 'inspect'])
    ->middleware(AdminPermission::UrlRead->middleware());
Route::get('/url/registry', [UrlPlatformAdminController::class, 'index'])
    ->middleware(AdminPermission::UrlRead->middleware());
Route::get('/url/registry/{id}/history', [UrlPlatformAdminController::class, 'history'])
    ->whereNumber('id')->middleware(AdminPermission::UrlRead->middleware());
Route::post('/url/slug/suggest', [UrlPlatformAdminController::class, 'suggest'])
    ->middleware(AdminPermission::UrlRead->middleware());
Route::post('/url/registry/{id}/slug', [UrlPlatformAdminController::class, 'changeSlug'])
    ->whereNumber('id')->middleware(AdminPermission::UrlEdit->middleware());
Route::post('/url/registry/{id}/lock', [UrlPlatformAdminController::class, 'lock'])
    ->whereNumber('id')->middleware(AdminPermission::UrlEdit->middleware());
Route::post('/url/registry/{id}/unlock', [UrlPlatformAdminController::class, 'unlock'])
    ->whereNumber('id')->middleware(AdminPermission::UrlEdit->middleware());
Route::post('/url/registry/{id}/rollback', [UrlPlatformAdminController::class, 'rollback'])
    ->whereNumber('id')->middleware(AdminPermission::UrlEdit->middleware());
Route::get('/seo/not-found', [UrlPlatformAdminController::class, 'notFound'])
    ->middleware(AdminPermission::AnalyticsRead->middleware());
/*
 * تدقيق الخريطة: قراءةُ ما قاسه المجدوِل ليلًا.
 *
 * `analytics.read` كجارَتها: تقرير ظهورٍ لا فعل تحريرٍ على
 * العناوين، ومن يقرأ «الروابط المكسورة» هو من يقرأ هذا.
 */
Route::get('/seo/sitemap/audit', [SitemapAuditController::class, 'show'])
    ->middleware(AdminPermission::AnalyticsRead->middleware());

/*
 * التشغيل والطوابير: القراءة بصلاحية التدقيق لأنّها قراءة حالة،
 * والإحياء بصلاحية نظاميّة خاصّة — إعادةُ بثّ حدث نطاقٍ تلمس كلّ
 * مستهلكيه، وهي أوسع أثرًا من أيّ فعلٍ تجاريّ مفرد.
 */
