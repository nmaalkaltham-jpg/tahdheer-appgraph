<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\AiController;
use App\Http\Controllers\Api\V1\Admin\AiSuggestionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Ai
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('/ai', [AiController::class, 'status'])
    ->middleware(AdminPermission::AuditRead->middleware());
/*
 * دفتر النداءات — المسار الذي كانت الواجهة تناديه منذ البداية.
 *
 * `ai-request-log.tsx` ينادي `/admin/ai/requests` وله نوعٌ مكتوب
 * (`AiRequestRow`)، وكان المسار غير موجودٍ فيردّ ٤٠٤ في وجه
 * المشغّل. وقد بُني الخادم باسم `runs` فاختلف الطرفان.
 *
 * والاسم المنشور هو `requests`: هو ما تعرفه الواجهة وما يسمّيه
 * المشغّل. ولا مسارَ ثانٍ بالاسم القديم — مسمّيان لموردٍ واحد
 * يتباعدان.
 */
Route::get('/ai/requests', [AiController::class, 'requests'])
    ->middleware(AdminPermission::AuditRead->middleware());
Route::get('/ai/requests/{id}', [AiController::class, 'showRequest'])
    ->whereNumber('id')->middleware(AdminPermission::AuditRead->middleware());

/*
 * الجدولة والإعادة والإلغاء أفعالٌ ذات أثر: تُنشئ عملًا يستهلك
 * معالجًا. تُحرس بـ`products.update` لأنّ ما تجدوله اليوم إثراءُ
 * منتج، ومن يملك تعديله يملك جدولة تحليله.
 */
Route::post('/ai/requests', [AiController::class, 'storeRequest'])
    ->middleware(AdminPermission::ProductsUpdate->middleware());
Route::post('/ai/requests/{id}/retry', [AiController::class, 'retryRequest'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsUpdate->middleware());
Route::post('/ai/requests/{id}/cancel', [AiController::class, 'cancelRequest'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsUpdate->middleware());
Route::post('/ai/probe', [AiController::class, 'probe'])
    ->middleware(AdminPermission::AuditRead->middleware());
Route::post('/ai/pause', [AiController::class, 'pause'])
    ->middleware(AdminPermission::SystemManage->middleware());
Route::post('/ai/resume', [AiController::class, 'resume'])
    ->middleware(AdminPermission::SystemManage->middleware());
Route::post('/ai/cache/clear', [AiController::class, 'clearCache'])
    ->middleware(AdminPermission::SystemManage->middleware());

/*
 * اقتراحات الذكاء.
 *
 * القراءة بـ`products.read`، والاعتماد والرفض بـ`products.update`:
 * الاعتماد يكتب في المنتج فعلًا، فهو تحريرُ منتجٍ بأداةٍ مختلفة لا
 * فعلُ «ذكاء». ولا صلاحيّة جديدة تُرحَّل على كلّ دور.
 */
Route::get('/ai/suggestions', [AiSuggestionController::class, 'index'])
    ->middleware(AdminPermission::ProductsRead->middleware());
Route::post('/ai/suggestions/{id}/approve', [AiSuggestionController::class, 'approve'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsUpdate->middleware());
Route::post('/ai/suggestions/{id}/reject', [AiSuggestionController::class, 'reject'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsUpdate->middleware());
Route::post('/products/{id}/ai/enrich', [AiSuggestionController::class, 'enrichProduct'])
    ->whereNumber('id')->middleware(AdminPermission::ProductsUpdate->middleware());

/*
 * طبقة التجميع الموحّدة — نطاقٌ واحدٌ في كلّ نداء.
 *
 * `analytics.read` كما `‎/dashboard‎` تمامًا: قراءةُ أرقامٍ مجمَّعة
 * لا فتحُ صفوفٍ بعينها. وكلّ رابط تحقيقٍ يخرج من هنا يهبط على
 * شاشةٍ لها حارسها، فمن لا يملك `orders.read` يرى الرقم ولا يفتح
 * صفوفه.
 *
 * والنطاق في المسار لا في وسيطة استعلام: الرابط يصير قابلًا
 * للمشاركة والتخزين، ويظهر النطاق في السجلّ عند التشخيص.
 */
