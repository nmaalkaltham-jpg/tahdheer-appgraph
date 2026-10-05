<?php

declare(strict_types=1);

use App\Domain\Admin\AdminPermission;
use App\Http\Controllers\Api\V1\Admin\HomeContentController;
use App\Http\Controllers\Api\V1\Admin\I18nController;
use App\Http\Controllers\Api\V1\Admin\ThemeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin · Appearance
|--------------------------------------------------------------------------
|
| This module owns one contiguous section of the Admin API.
|
| Authentication + throttling are inherited from api-admin.php.
| Authorization remains explicit through AdminPermission.
| No generic Review or Approval gate exists here.
|
*/

Route::get('/i18n/overview', [I18nController::class, 'overview'])
    ->middleware(AdminPermission::AnalyticsRead->middleware());
Route::get('/i18n/hreflang-audit', [I18nController::class, 'hreflangAudit'])
    ->middleware(AdminPermission::AnalyticsRead->middleware());
Route::patch('/i18n/locales/{code}', [I18nController::class, 'updateLocale'])
    ->where('code', '[a-z]{2}(-[A-Za-z0-9]+)?')
    ->middleware(AdminPermission::AppearanceManage->middleware());

// المظهر والتيبوغرافيا. القراءة والكتابة بالصلاحية نفسها: من يرى
// الوثيقة يرى كل رموز التصميم، وهي إعداد تشغيلي لا بيانات عرض.
Route::middleware(AdminPermission::AppearanceManage->middleware())->group(function () {
    Route::get('/theme', [ThemeController::class, 'show']);
    Route::put('/theme', [ThemeController::class, 'update']);
    Route::delete('/theme', [ThemeController::class, 'destroy']);
    // ترجمة مسوّدة بلا حفظ — تُغذّي المعاينة الحيّة من المُترجِم نفسه.
    Route::post('/theme/preview', [ThemeController::class, 'preview']);
    // لقطات الثيم (C27): تراجع حقيقي لتحرير الرموز — لا نظام نشر ثانٍ.
    Route::get('/theme/versions', [ThemeController::class, 'versions']);
    Route::post('/theme/restore', [ThemeController::class, 'restore']);
});

/*
 * محتوى الصفحة الرئيسة — مجموعةٌ مستقلّة بصلاحيّتها.
 *
 * ولا تُضمّ إلى `appearance.manage` أعلاه: تحذير هذا الملفّ نفسه
 * (أدناه) يقول إنّ تلك المجموعة «امتدّت إلى ما لا يخصّها» فعطّلت
 * ثلاثة أدوار بصمت. من يضبط رموز التصميم مصمّم، ومن يكتب عنوان
 * الهيرو محرّر — ودمجُهما يُعطي المحرّر تصميمَ المتجر كلّه.
 *
 * وكان `HomeContent` يُقرأ ولا يُكتب: محتوى الرئيسة كان يتغيّر
 * بتحرير البذّار وإعادة البذر، أي بنشرةٍ من مطوّر لا بقرارٍ من مالك.
 */
Route::middleware(AdminPermission::ContentManage->middleware())->group(function () {
    Route::get('/home-content', [HomeContentController::class, 'index']);
    Route::put('/home-content/{block}', [HomeContentController::class, 'update'])
        ->where('block', '[a-z_]+');
});

/*
 * ⚠ هنا كانت مجموعة `appearance.manage` تمتدّ إلى ما لا يخصّها.
 *
 * فُتحت للثيم والتيبوغرافيا — كما يقول تعليقها — ثمّ أُضيف تحتها كل
 * ما جاء بعده بالخطأ: استوديو المنتج والقسائم والمراجعات والطلب
 * والزحف. والنتيجة أنّ **ثلاثة أدوار كانت معطّلة بصمت**: مدير
 * الطلبات يملك `promotions.manage` ولا يصل إلى القسائم، ومدير
 * المنتجات يملك `reviews.moderate` ولا يصل إلى المراجعات، ودور
 * التحليلات يملك `analytics.read` ولا يصل إلى «الطلب» ولا «الزحف».
 *
 * ولم يكشفه اختبار: الاختبارات كانت تتحقّق أنّ دورًا **غير** مخوَّل
 * يُرفض بـ٤٠٣ — وقد كان يُرفض فعلًا، لكن للسبب الخطأ. فالفحص
 * الإيجابي («المخوَّل يصل») هو ما كان ناقصًا، وهو الآن في
 * RoutePermissionScopeTest.
 */

/*
 * استوديو المنتج — الفجوات المؤكَّدة.
 *
 * القراءة بـ products.read والكتابة بـ products.update: نفس
 * الصلاحيات القائمة، لا نظام تفويض ثانٍ.
 */
