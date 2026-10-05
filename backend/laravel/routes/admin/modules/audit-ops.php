<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\AuditController;
use App\Http\Controllers\Api\V1\Admin\OperationsSummaryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Audit Ops
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('/ops/summary', OperationsSummaryController::class)
    ->middleware(AdminPermission::OrdersRead->middleware());

/*
 * سجلّ التدقيق — قراءة فقط، بصلاحية audit.read المحجوزة له في
 * الكتالوج لا analytics.read: السجلّ يكشف الاستردادات وسحب
 * الصلاحيات، وهذه ليست تحليلات يراها كل من يقرأ الأرقام.
 */
Route::get('/audit', [AuditController::class, 'index'])
    ->middleware(AdminPermission::AuditRead->middleware());
/*
 * التصدير قراءةٌ لا كتابة، فبالصلاحية نفسها. ومن يقرأ السجلّ
 * يحتاج أن يُسلّمه لمن لا حساب له — مراجعٌ أو محقّق.
 */
Route::get('/audit/export', [AuditController::class, 'export'])
    ->middleware(AdminPermission::AuditRead->middleware());

/*
 * §٦ — سجلّ أنواع المنتج. يسبق القائمة في الترتيب لأنّه يسبقها
 * في المنطق: اللوحة تقرؤه قبل أن تعرض نموذج الإنشاء، فما تعرضه
 * من طبائع واستراتيجيّات يأتي من الخادم لا من نسخةٍ في الواجهة.
 */
