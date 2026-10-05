<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\IntegrationController;
use App\Http\Controllers\Api\V1\Admin\PaymentLedgerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Payments
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('/payments/transactions', [PaymentLedgerController::class, 'transactions'])
    ->middleware(AdminPermission::OrdersRead->middleware());
Route::get('/payments/transactions/{id}', [PaymentLedgerController::class, 'transaction'])
    ->whereNumber('id')->middleware(AdminPermission::OrdersRead->middleware());
// ردُّ مالٍ لم يُموِّل طلبًا (تحصيلٌ مرفوض أو زائد) — صلاحية الاسترداد نفسها.
Route::post('/payments/transactions/{id}/refund', [PaymentLedgerController::class, 'refundCapture'])
    ->whereNumber('id')->middleware(AdminPermission::OrdersRefund->middleware());
Route::get('/payments/refunds', [PaymentLedgerController::class, 'refunds'])
    ->middleware(AdminPermission::OrdersRead->middleware());
/*
 * التسوية قراءةٌ ماليّة: تُكشف بصلاحية الطلبات لأنّ من يقرأ
 * الطلب يقرأ هل وصل مالُه — ولا تغيّر شيئًا فلا تحتاج أكثر.
 */
Route::get('/payments/reconciliation', [PaymentLedgerController::class, 'reconciliation'])
    ->middleware(AdminPermission::OrdersRead->middleware());
/*
 * حالة المزوّد بصلاحية إدارة الدفع لا القراءة: تكشف بادئة المفتاح
 * ووضع الحياة، وهي معلومات إعدادٍ لا معلومات طلب.
 */
/*
 * مفاتيح الربط — `integrations.manage` وهي للمالك وحده.
 *
 * ولا نقطةَ قراءةٍ للسرّ: الخزنة لا تُخرجه عبر الشبكة أصلًا،
 * فلا مسارَ هنا يحتاج أن يُحرَس ضدّ ذلك.
 */
Route::get('/integrations', [IntegrationController::class, 'index'])
    ->middleware(AdminPermission::IntegrationsManage->middleware());
Route::post('/integrations/credentials', [IntegrationController::class, 'store'])
    ->middleware(AdminPermission::IntegrationsManage->middleware());
Route::delete('/integrations/credentials', [IntegrationController::class, 'destroy'])
    ->middleware(AdminPermission::IntegrationsManage->middleware());

Route::get('/payments/provider', [PaymentLedgerController::class, 'provider'])
    ->middleware(AdminPermission::PaymentsManage->middleware());

/*
 * التحويلات: قرارٌ يمسّ كلّ زائرٍ يصل إلى مسارٍ قديم، وخطأٌ فيه
 * يُسقط ترتيبَ صفحةٍ في نتائج البحث. فصلاحيتُه صلاحية SEO —
 * والقراءة معها لأنّ الجدول نفسه هو ما يُحرَّر.
 */
