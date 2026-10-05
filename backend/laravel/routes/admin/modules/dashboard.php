<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\DashboardSummaryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Dashboard
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('/dashboard', DashboardController::class)
    ->middleware(AdminPermission::AnalyticsRead->middleware());

/*
 * ملخّص لوحة التشغيل على مدًى — مؤشّراتٌ ومقارنةٌ وسلسلةٌ يوميّة.
 *
 * منفصلٌ عن `/dashboard` القديم عمدًا: ذاك عدّاداتٌ كلّيّة بلا مدًى
 * ويقرؤه تبويب التحليلات، وتغييرُ عقده كان سيكسره لأجل سطحٍ آخر.
 */
Route::get('/dashboard/summary', DashboardSummaryController::class)
    ->middleware(AdminPermission::AnalyticsRead->middleware());

/*
 * مركز تحكّم الذكاء.
 *
 * القراءة بـ`audit.read` كما حال النظام: تكشف بنية التشغيل.
 * والأفعال بـ`system.manage` — الصلاحية نفسها التي تحرس إحياء
 * صندوق الصادر، فلا صلاحيّة جديدة تُرحَّل على كلّ دور.
 *
 * ولا مسارَ هنا يستدلّ: الاستدلال يمرّ بالبوّابة من داخل الميزات،
 * ونقطةٌ إداريّة تستدلّ كانت بابًا خلفيًّا يتجاوز الرايات والحدود.
 */
