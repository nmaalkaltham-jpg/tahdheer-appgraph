<?php

use App\Http\Controllers\Api\V1\BundleController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ConsentController;
use App\Http\Controllers\Api\V1\CurriculumController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MaterialRequestController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PublicMediaController;
use App\Http\Controllers\Api\V1\SampleController;
use App\Http\Controllers\Api\V1\SampleFileController;
use App\Http\Controllers\Api\V1\ThemeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    /*
     * الجاهزية بلا حدّ: المُنسّق يستفتيها كل خمس عشرة ثانية، وخنقها يجعله
     * يظنّ الحاوية ميّتة فيعيد تشغيلها — الحدّ نفسه يصير سبب الانقطاع.
     */
    Route::get('/health', HealthController::class);

    /*
     * استقبال أحداث القياس — عامّ، بحجمٍ عالٍ، ومحدَّد بـ`throttle:telemetry`
     * (ثلاثون في الدقيقة) كتقارير الروابط المكسورة.
     *
     * ولا مصادقة: الزائر المجهول هو موضوع القياس نفسه. والحماية في ثلاث طبقات —
     * حدُّ المعدّل، وسقف الدفعة، وقائمة الأنواع المغلقة.
     */
    Route::middleware('throttle:telemetry')->post('/events', [EventController::class, 'store']);

    /*
     * قرار الموافقة على القياس — قراءةٌ رخيصة وكتابةٌ نادرة.
     *
     * على حدّ القياس نفسه لا على حدٍّ خاصّ: النقرة على «أوافق» تقع مرّة كلّ
     * سنة، والقراءة تقع مرّة لكلّ زائرٍ لم يُسأل بعد — وكلاهما دون سقف الثلاثين
     * بمراحل. وحدٌّ ثالثٌ يعني دلوًا ثالثًا يُضبط وينحرف.
     */
    Route::middleware('throttle:telemetry')->group(function () {
        Route::get('/consent', [ConsentController::class, 'show']);
        Route::post('/consent', [ConsentController::class, 'store']);
    });

    // قراءات عامّة رخيصة — الحدّ سخيّ عمدًا: التصفّح السريع ليس هجومًا.
    Route::middleware('throttle:read')->group(function () {
        // الثيم المُترجَم — يُطلب في كل تصيير للمتجر، ولذلك مُخزَّن ب ETag.
        Route::get('/theme', ThemeController::class);

        /*
         * الصفحات المحورية للمنهج. القائمة تخدم الخريطة والروابط الداخلية،
         * والعنقود الواحد يخدم الصفحة نفسها.
         */
        Route::get('/curriculum', [CurriculumController::class, 'index']);
        Route::get('/curriculum/{type}/{slug}', [CurriculumController::class, 'show'])
            ->where(['type' => '[a-z-]+', 'slug' => '.*']);

        Route::get('/catalog/{dimension}', CatalogController::class)
            ->whereIn('dimension', ['stages', 'grades', 'subjects', 'semesters', 'kinds']);

        Route::get('/products', [ProductController::class, 'index']);

        /*
         * العيّنات المجّانيّة. سطحٌ عامّ بحت: لا يُصدر إلّا ملفّات مرئيّتها
         * `public_sample`، ويمرّ بالمسار نفسه الذي تمرّ به معاينة صفحة
         * المنتج — فلا قاعدة ثانية لِما يجوز نشره.
         */
        Route::get('/samples', SampleController::class);

        /*
         * خدمة ملفّ العيّنة. خارج مسار المنح عمدًا: العيّنة عامّة بالتعريف،
         * فاشتراط منحةٍ لها تناقض. الحدود الثلاثة في المتحكّم.
         */
        Route::get('/samples/files/{asset}', SampleFileController::class)
            ->whereNumber('asset');

        /*
         * الوسائط العامّة. المتصفّح لا يبلغ Garage (داخلي على شبكة Docker)،
         * ولا حجم مثبَّت على حاوية الـAPI، فبدون هذا المسار لا سبيل لعرض
         * غلاف منتج أصلًا. المتحكّم يقصر الخدمة على البادئة «media/» وحدها،
         * فالأصول المدفوعة في الدلو نفسه تبقى بعيدة عن متناوله.
         */
        Route::get('/media/{path}', PublicMediaController::class)->where('path', '.*');
        Route::get('/products/{slug}', [ProductController::class, 'show']);

        /* الحزم المنشورة — بطاقاتٌ بمكوّناتها، والحزمة الواحدة بمعرّفها. */
        Route::get('/bundles', [BundleController::class, 'index']);
        Route::get('/bundles/{slug}', [BundleController::class, 'show']);

    });

    /*
     * طلب مادة غير متوفّرة — نقطة كتابة عامّة، فلها حدّ الكتابة لا القراءة:
     * نموذج مفتوح بلا حدّ يصير قناة إغراق تُفسد التقرير الذي بُني لأجله.
     */
    Route::post('/material-requests', MaterialRequestController::class)
        ->middleware('throttle:write');

    // ملفات مسارات مملوكة لكل نطاق — لا يعدّل هذا الملف إلا المنسّق.
    require __DIR__.'/api-content.php';
    require __DIR__.'/api-search.php';
    require __DIR__.'/api-commerce.php';
    require __DIR__.'/api-seo.php';
    require __DIR__.'/api-reviews.php';
    require __DIR__.'/api-admin.php';
});
