<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AdminAuthenticate;
use App\Http\Middleware\RequestId;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * سياسة حدود المعدّل — مسمّاة وموثّقة في مكان واحد.
 *
 * ما كان قبلها: ستّة أرقام سحرية مبعثرة في ملفّات المسارات (10,1 و20,1
 * و30,1 و60,1×3) بلا سبب مكتوب لأيٍّ منها، وأربع عشرة نقطة قراءة عامّة بلا
 * أيّ حدّ — منها البحث الذي يضرب Meilisearch، وخرائط الموقع، وllms-data.
 * سياسة غير مكتوبة لا يمكن مراجعتها ولا ضبطها؛ رقم في سطر مسار لا يخبر
 * أحدًا لماذا هو ذاك الرقم.
 *
 * التصنيف بالكلفة والخطر لا بالتخمين:
 *
 *  · read      — قراءة رخيصة تخدمها الفهارس والكاش. سخيّة عمدًا: زائر
 *                يتصفّح بسرعة ليس مهاجمًا، وحدّ ضيّق هنا يكسر التصفّح العادي.
 *  · search    — تستدعي خدمة خارجية، فلكل طلب كلفة خارج قاعدة البيانات.
 *  · expensive — خرائط الموقع وllms-data تبني ردودًا كبيرة؛ عشرات الطلبات
 *                المتوازية تستهلك الذاكرة والوقت.
 *  · write     — كتابة عامّة (السلة، الكوبون، المراجعات): تُنشئ صفوفًا.
 *  · telemetry — إبلاغ القياس (‎/not-found‎): أضيق، فالفيض يلوّث التقارير.
 *  · auth      — تسجيل الدخول: الحدّ هنا دفاع عن كلمات المرور لا عن الأداء.
 *  · admin     — جلسة مصادَقة؛ الحدّ دفاع في العمق إن سُرقت الجلسة.
 *
 * المفتاح: مُعرّف المشرف حين يوجد، وإلّا عنوان IP. الخلط بينهما يعني أن
 * مشرفًا خلف نفس شبكة زائر يستهلك حصّته.
 *
 * ما لا يُحَدّ عمدًا: webhook الدفع. البوّابة تعيد المحاولة عند الفشل، وخنقها
 * يُسقط إشعارات دفع حقيقية — وحمايته توقيعُه لا معدّلُه.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * الحدود بالدقيقة. مجموعة في ثابت واحد ليُقرأ السقف كلّه دفعةً.
     *
     * @var array<string, int>
     */
    public const LIMITS = [
        'read' => 300,
        'search' => 60,
        'expensive' => 20,
        'write' => 60,
        // إبلاغ عن 404 من المتجر: قياس لا كتابة أعمال. حدّ أضيق لأن
        // فيضانه يلوّث التحليلات لا قاعدة البيانات وحدها.
        'telemetry' => 30,
        'auth' => 10,
        'admin' => 240,
        // فحص الروابط يجعل خادمنا مصدر طلبات لمواقع أخرى؛ الحدّ ضيّق عمدًا
        // حتى لا تتحوّل لوحة مشرف إلى أداة إغراق لطرف ثالث.
        'link-check' => 10,
    ];

    public function boot(): void
    {
        foreach (self::LIMITS as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)
                ->by($this->key($request))
                ->response(fn (Request $r, array $headers) => $this->tooMany($r, $headers)));
        }

        /*
         * الدخول يُحَدّ بالبريد + IP معًا، لا بأحدهما:
         *  · بالـIP وحده ⇒ مهاجم موزّع يجرّب كلمات مرور على حساب واحد بحرّية.
         *  · بالبريد وحده ⇒ مهاجم يقفل حساب المالك عنه بمحاولات فاشلة متعمّدة.
         */
        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(self::LIMITS['auth'])->by('auth:ip:'.$request->ip())
                    ->response(fn (Request $r, array $h) => $this->tooMany($r, $h)),
                Limit::perMinute(self::LIMITS['auth'])->by('auth:id:'.sha1($email))
                    ->response(fn (Request $r, array $h) => $this->tooMany($r, $h)),
            ];
        });
    }

    /**
     * مشرف مصادَق ⇒ حصّته الخاصّة؛ وإلّا عنوان الشبكة.
     *
     * يُقرأ من الجلسة لا من الحاوية: AdminAuthenticate يربط النموذج في
     * الحاوية، لكن وسيط الحدّ قد يعمل قبله في السلسلة فيجد الحاوية فارغة
     * ويحسب كل المشرفين على IP واحد. مفتاح الجلسة متاح فور StartSession،
     * ولا يكلّف استعلامًا.
     */
    private function key(Request $request): string
    {
        $adminId = $request->hasSession()
            ? $request->session()->get(AdminAuthenticate::SESSION_KEY)
            : null;

        return $adminId !== null
            ? 'admin:'.$adminId
            : 'ip:'.(string) $request->ip();
    }

    /**
     * ردّ 429 بنفس عقد الأخطاء في بقيّة الـAPI — لا شكل ثانٍ يفاجئ العميل.
     * الافتراضي في Laravel نصّ إنجليزي بلا `code`، فلا تستطيع الواجهة تمييزه.
     *
     * @param  array<string, mixed>  $headers
     */
    private function tooMany(Request $request, array $headers): Response
    {
        $seconds = (int) ($headers['Retry-After'] ?? 60);

        return response()->json([
            'message' => 'تجاوزت عدد الطلبات المسموح. أعد المحاولة بعد '.$seconds.' ثانية.',
            'code' => 'RATE_LIMITED',
            'retryAfter' => $seconds,
            'requestId' => $request->attributes->get(RequestId::CONTEXT_KEY),
        ], 429, $headers);
    }
}
