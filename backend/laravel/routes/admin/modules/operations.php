<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\CaseController;
use App\Http\Controllers\Api\V1\Admin\CatalogQualityController;
use App\Http\Controllers\Api\V1\Admin\OperationsActionController;
use App\Http\Controllers\Api\V1\Admin\OperationsController;
use App\Http\Controllers\Api\V1\Admin\ProductIntelligenceController;
use App\Http\Middleware\AdminAuthenticate;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Operations
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('/operations/fulfillment', [OperationsController::class, 'fulfillmentUnits'])
    ->middleware(AdminPermission::OrdersRead->middleware());
Route::get('/operations/shipments', [OperationsController::class, 'shipments'])
    ->middleware(AdminPermission::OrdersRead->middleware());
Route::get('/operations/returns', [OperationsController::class, 'returns'])
    ->middleware(AdminPermission::OrdersRead->middleware());
Route::get('/operations/inventory', [OperationsController::class, 'inventory'])
    ->middleware(AdminPermission::ProductsRead->middleware());
Route::get('/operations/asset-sources', [OperationsController::class, 'assetSources'])
    ->middleware(AdminPermission::ProductsRead->middleware());
/* §١٠٥ — طابور مراجعة المخاطر. قراءةُ طلباتٍ فصلاحيتها صلاحيتها. */
/*
 * القضايا: احتيالٌ وحوادثُ ودعم في مسارٍ واحد بنوعٍ مُرشَّح.
 * مسارٌ لكلّ نوع كان سيمنع سؤالًا مشروعًا: «أرِني كلّ ما هو مفتوح».
 *
 * القراءة بصلاحية الطلبات، والفعل بـ`operations.manage`: من
 * ينقل شحنةً بين الحالات هو من ينقل قضيّةً بينها.
 */
Route::get('/cases', [CaseController::class, 'index'])
    ->middleware(AdminPermission::OrdersRead->middleware());
Route::get('/cases/{id}', [CaseController::class, 'show'])
    ->whereNumber('id')->middleware(AdminPermission::OrdersRead->middleware());
Route::patch('/cases/{id}', [CaseController::class, 'transition'])
    ->whereNumber('id')->middleware(AdminPermission::OperationsManage->middleware());
Route::patch('/cases/{id}/assignee', [CaseController::class, 'assign'])
    ->whereNumber('id')->middleware(AdminPermission::OperationsManage->middleware());
Route::post('/cases/{id}/notes', [CaseController::class, 'note'])
    ->whereNumber('id')->middleware(AdminPermission::OperationsManage->middleware());
/*
 * الإصلاح فعلٌ ذو أثرٍ خارجيّ: يُحرس بصلاحية التشغيل نفسها التي
 * تحرس إعادة المحاولة وإعادة التشغيل في شاشاتها.
 */
Route::post('/cases/{id}/remediations', [CaseController::class, 'remediate'])
    ->whereNumber('id')->middleware(AdminPermission::OperationsManage->middleware());

/*
 * العناقيد بصلاحية القراءة نفسها: تحليلٌ لا يغيّر شيئًا، ومن
 * يراجع تقييمًا مفردًا يحتاج أن يرى نمطه.
 */
Route::get('/operations/risk/clusters', [OperationsController::class, 'riskClusters'])
    ->middleware(AdminPermission::OrdersRead->middleware());
Route::get('/operations/risk', [OperationsController::class, 'riskReviews'])
    ->middleware(AdminPermission::OrdersRead->middleware());

/* §١٢٠ — اكتمال بيانات المنتج. قراءةُ كتالوجٍ فصلاحيتها صلاحيته. */
Route::get('/catalog/quality', [CatalogQualityController::class, 'index'])
    ->middleware(AdminPermission::ProductsRead->middleware());

/*
 * §١١٣ §١١٤ §١١٥ §١٨٢ — فاحص ذكاء المنتج: ما استُخرج وأين توقّف الخطّ.
 *
 * `products.read` كما اكتمالُ البيانات فوقه: قراءةُ كتالوج، ولا
 * صلاحيّة جديدة تُستحدث فتوجب ترحيل كلّ دورٍ قائم قبل أن يرى أحدٌ
 * الشاشة (القاعدة نفسها المكتوبة عند `/search/explain`).
 *
 * والأهمّ: النصّ المستخرَج محتوًى مدفوع بصيغةٍ أخرى (§٥٨ §١٢٦)،
 * فهو خلف `AdminAuthenticate` ثمّ `AdminAuthorize` مثلُه مثل بقيّة
 * هذه المجموعة — ولا مسار عامّ يعيده. لا تنقل هذين السطرين خارج
 * هذه المجموعة.
 */
Route::get('/catalog/intelligence', [ProductIntelligenceController::class, 'index'])
    ->middleware(AdminPermission::ProductsRead->middleware());
Route::get('/catalog/intelligence/{product}', [ProductIntelligenceController::class, 'show'])
    ->whereNumber('product')->middleware(AdminPermission::ProductsRead->middleware());

/*
 * أفعال العمليّات. صلاحيّتان لا واحدة: `operations.manage` لمن
 * يعالج التسليم والشحن والإرجاع (الدعم)، و`inventory.manage` لمن
 * يُدخل البضاعة ويصحّح الجرد (المستودع). دمجُهما كان يُعطي
 * الدعمَ سلطةَ تعديل المخزون — وهي أخطر ما في المتجر لأنّها
 * تقرّر ما يُباع.
 */
Route::post('/operations/fulfillment/{id}/retry', [OperationsActionController::class, 'retryUnit'])
    ->whereNumber('id')->middleware(AdminPermission::OperationsManage->middleware());
Route::patch('/operations/shipments/{id}', [OperationsActionController::class, 'updateShipment'])
    ->whereNumber('id')->middleware(AdminPermission::OperationsManage->middleware());
Route::patch('/operations/returns/{id}', [OperationsActionController::class, 'updateReturn'])
    ->whereNumber('id')->middleware(AdminPermission::OperationsManage->middleware());
Route::post('/operations/asset-sources/{id}/verify', [OperationsActionController::class, 'verifySource'])
    ->whereNumber('id')->middleware(AdminPermission::OperationsManage->middleware());
Route::post('/operations/inventory/movements', [OperationsActionController::class, 'stockMovement'])
    ->middleware(AdminPermission::InventoryManage->middleware());
Route::patch('/operations/risk/{id}', [OperationsActionController::class, 'reviewRisk'])
    ->whereNumber('id')->middleware(AdminPermission::OperationsManage->middleware());

/*
 * أحداث الدفع الواردة: القراءة بصلاحية الطلبات لأنّها جزءٌ من
 * سجلّ الطلب، وإعادةُ التشغيل بصلاحيةٍ خاصّة لأنّها فعلٌ ماليّ.
 */
/*
 * سجلّ المدفوعات: قراءةٌ محضة بصلاحية الطلبات — الدفعة جزءٌ من
 * الطلب لا كيانٌ منفصل، ومن يقرأ الطلب يقرأ كيف دُفع.
 */
