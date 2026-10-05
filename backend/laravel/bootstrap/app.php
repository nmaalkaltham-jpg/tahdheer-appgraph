<?php

use App\Domain\Operations\Exceptions\IdempotencyConflict;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * كان المكدّس فارغًا. النتيجتان العمليتان:
         *  · لا معرّف ارتباط، فلا سبيل لربط سطر في سجلّ العامل بالطلب الذي
         *    أنتجه إلا التخمين بالوقت.
         *  · لا ترويسات أمان، فمتصفّح يعامل استجابات الـAPI بأوسع الصلاحيات
         *    الافتراضية.
         *
         * prepend لا append: المعرّف يجب أن يوجد قبل أن يكتب أي وسيط لاحق
         * سطرًا في السجلّ، وإلّا خرجت أوّل الأسطر — وهي غالبًا أسطر الرفض —
         * بلا معرّف.
         */
        $middleware->api(prepend: [RequestId::class, SecurityHeaders::class]);
        $middleware->web(prepend: [RequestId::class]);

        /*
         * وكلاء موثوقون — بدونها كل ما سبق يكذب.
         *
         * الإنتاج خلف Traefik ينهي TLS. بلا هذا الضبط يقرأ Laravel عنوان
         * الوكيل عنوانًا للعميل. أثبتُّه بالتنفيذ: طلب يحمل
         * X-Forwarded-For: 203.0.113.9 من REMOTE_ADDR: 10.0.0.5 كان
         * request->ip() فيه يساوي 10.0.0.5.
         *
         * ثلاث نتائج، كلها صامتة:
         *  · كل حدود المعدّل تُفهرس على عنوان الوكيل، فيصير للزوّار جميعًا
         *    دلوٌ واحد: مهاجم واحد يستنفد حصّة الجميع (حجب خدمة)، والزائر
         *    الشرعي يُخنق. الحدّ يتحوّل من حماية إلى ثغرة.
         *  · عنوان العميل في السجلّات والتحليلات خطأ دائمًا.
         *  · ‎$request->secure()‎ تساوي false خلف TLS، فأي منطق يشترط HTTPS
         *    (كوكي Secure، توليد روابط) يتصرّف كأن الاتصال غير مشفّر.
         *
         * النطاقات الخاصّة لا '*': التطبيق لا يُبلَغ إلا عبر شبكة Docker
         * الداخلية، و'*' تعني أن أي طالب يبلغه مباشرةً يستطيع انتحال عنوانه
         * بترويسة — فيتخطّى الحدود ويلوّث سجلّ التدقيق.
         */
        /*
         * قيمة فارغة تعني «استعمل الافتراض»، لا «لا تثق بأحد».
         *
         * ‎env('X', $default)‎ تُعيد الافتراض حين يغيب المفتاح، لا حين يوجد
         * فارغًا. و‎TRUSTED_PROXIES=‎ الفارغ — وهو أطبع ما يُكتب للدلالة على
         * «غير محدَّد» — كان يعطي '' فتصير القائمة [] فلا يُوثَق بوكيل واحد،
         * وتنهار كل الفهرسة على عنوان العميل بصمت.
         *
         * كشفه قياسٌ لا مراجعة: استهلكتُ 25 طلبًا من جهازي فنقص عدّاد الخادم
         * معها — دلو واحد للعالم كلّه. والاختبارات المحلية كانت تمرّ لأن
         * المفتاح غائب أصلًا هناك فيعمل الافتراض.
         */
        $configured = trim((string) env('TRUSTED_PROXIES', ''));
        $proxies = $configured !== ''
            ? $configured
            : '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1';

        $middleware->trustProxies(
            at: array_values(array_filter(array_map('trim', explode(',', $proxies)))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * ٤٠٩ على تصادم مفتاح الخمول.
         *
         * الخمول عقدٌ طرفاه المفتاح والطلب. مفتاحٌ يُعاد بجسمٍ مختلف إمّا خطأ
         * توليدٍ عند العميل وإمّا نيّةٌ أخرى — وإعادةُ نتيجة الطلب الأوّل في
         * الحالتين كذب. الرمز يقول «تعارض» لا «نجاح»، ويحمل معرّف العمليّة
         * القائمة ليُقرأ ما جرى فعلًا.
         */
        $exceptions->render(function (IdempotencyConflict $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'IDEMPOTENCY_CONFLICT',
                'operationId' => $e->existing->id,
                'operationStatus' => $e->existing->status,
            ], 409);
        });

        /*
         * 404 عربي موحّد بدل رسالة Laravel الداخلية.
         *
         * `findOrFail`/`firstOrFail` تُحوَّل إلى NotFoundHttpException حاملةً
         * رسالتها كما هي، فيصل العميل نصٌّ مثل:
         *   "No query results for model [App\Models\Product]."
         * أثبتُّه بالتنفيذ على GET /api/v1/products/no-such-slug. يكشف اسم
         * النموذج الكامل، وينجليزي في واجهة كل رسائلها عربية، ويمسّ أربعة
         * وعشرين مسارًا تستعمل findOrFail.
         *
         * يُلتقط ModelNotFoundException نفسه — قبل تحويله — فلا تُدهس رسائل
         * 404 المقصودة التي يكتبها المتحكّم صراحةً («الطلب غير موجود»).
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            /*
             * يُلتقط بعد التحويل لا قبله: Laravel يحوّل ModelNotFoundException
             * إلى NotFoundHttpException في prepareException قبل أن تعمل
             * مُصيِّرات التطبيق، فمُصيِّرٌ مسجَّل على النوع الأصلي لا يُستدعى
             * أبدًا (جرّبتُه فبقي التسريب). السبب الأصلي هو ما يميّز 404
             * الناتج عن ربط نموذج من 404 مقصود كتبه متحكّم برسالة عربية.
             */
            if (! ($e->getPrevious() instanceof ModelNotFoundException)) {
                return null;
            }

            return response()->json([
                'message' => 'المورد المطلوب غير موجود.',
                'code' => 'NOT_FOUND',
                'requestId' => $request->attributes->get(RequestId::CONTEXT_KEY),
            ], 404);
        });

        /*
         * جسم خطأ واحد لكل الـAPI: {message, code, requestId}.
         *
         * قبله كان الاستثناء غير المتوقَّع يخرج بشكل Laravel الافتراضي —
         * ونصّ الرسالة فيه قد يحمل استعلامًا أو مسارًا داخليًّا. وبلا
         * requestId لا يستطيع عميل أبلغ عن خطأ أن يدلّنا على سطره في السجلّ.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }
            /*
             * ما يُترك لـ Laravel:
             *  · HttpExceptionInterface — 404/403/419… رموز مقصودة.
             *  · ValidationException — رسائل 422 عربية معدّة للعرض.
             *  · HttpResponseException — تحمل استجابةً جاهزة بُنيت في عمق
             *    المكدّس. هذه بالذات أوقعتني: وسيط الخنق يرمي بها حاملًا ردّ
             *    429، فابتلعها هذا المعالج وحوّل كل تجاوز حدّ إلى 500. عطلٌ
             *    كان سيظهر في الإنتاج فقط، عند أوّل فيض حقيقي.
             */
            if ($e instanceof HttpExceptionInterface
                || $e instanceof HttpResponseException
                || $e instanceof ValidationException) {
                return null;
            }

            $requestId = $request->attributes->get(RequestId::CONTEXT_KEY);

            return response()->json([
                // نصّ ثابت: تفاصيل العطل في السجلّ لا في جسم الاستجابة.
                'message' => 'حدث خطأ غير متوقّع. تم تسجيل العطل.',
                'code' => 'INTERNAL_ERROR',
                'requestId' => $requestId,
            ], 500);
        });
    })->create();
