<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\AiHealthService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * فحص الجاهزية.
 *
 * كان يفحص قاعدة البيانات وحدها ويردّ 200 وValkey ساقط — وعليه الجلسات
 * والسلة والكاش وحدود المعدّل. مُنسّق يقرأ ذلك الردّ يُبقي الحاوية في الخدمة
 * ويوجّه إليها العملاء بينما كل عملية شراء تفشل. فحصُ صحّةٍ يكذب أسوأ من
 * غيابه: غيابه يجعلك تنظر، وكذبُه يجعلك تنظر في المكان الخطأ.
 *
 * التبعيات مصنّفة، لا مجموعة واحدة:
 *   · حرجة  — بدونها لا يعمل شراء واحد ⇒ 503 وإخراج من الخدمة.
 *   · ثانوية — تُضعف الخدمة ولا تُسقطها (البحث يسقط إلى استعلام قاعدة
 *     البيانات، والتخزين يلزم للتحميل لا للتصفّح) ⇒ 200 مع «degraded».
 *
 * لكل فحص مهلة: فحص جاهزية يعلّق هو نفسه انقطاع خدمة، لأن المنسّق ينتظر ثم
 * يعتبر الحاوية ميّتة.
 *
 * لا يكشف شيئًا: أسماء المضيفين والمنافذ ونصوص الاستثناءات لا تخرج من هنا.
 * الجواب حالة لكل تبعية ومدّة بالمللي ثانية، وتفاصيل العطل تذهب للسجلّ.
 */
final class HealthController extends Controller
{
    /** تبعيات لا تعمل المنصّة بدونها. */
    private const CRITICAL = ['database', 'cache'];

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->timed(static function (): bool {
                DB::select('select 1');

                return true;
            }),
            'cache' => $this->timed(static function (): bool {
                // كتابة ثم قراءة: PING يثبت الاتصال ولا يثبت أن التخزين يعمل
                // (قرص ممتلئ أو ذاكرة مستنفدة يقبلان الاتصال ويرفضان الكتابة).
                $key = 'health:'.Str::random(8);
                Cache::put($key, '1', 5);
                $ok = Cache::get($key) === '1';
                Cache::forget($key);

                return $ok;
            }),
            /*
             * الذكاء تبعيّةٌ غير حرجة — عمدًا وبقرارٍ معلن.
             *
             * سقوطُه يجعل الحالة «متدهورة» ويبقى الردّ ٢٠٠. وإدراجُه في
             * `CRITICAL` كان يجعل نموذجًا ساقطًا يُخرج المنصّة من الخدمة عند
             * موازن الحمل، فيتوقّف الشراء والبحث لأجل ميزةٍ اختياريّة. وهذا
             * بالضبط ما تمنعه قاعدة «عطل الذكاء لا يُسقط الدفع ولا البحث».
             *
             * والمطفأ ليس ساقطًا: راية مطفأة تعني «لا نقيس» فيُعدّ الفحص
             * ناجحًا — وإلّا بدا إطفاءٌ مقصود عطلًا دائمًا في كلّ لوحة.
             */
            'ai' => $this->timed(static function (): bool {
                if (! (bool) config('ai.enabled')) {
                    return true;
                }

                return app(AiHealthService::class)->isHealthy();
            }),
            'queue' => $this->timed(static function (): bool {
                // عمق الطابور ليس صحّة؛ المهمّ أن المخزن قابل للقراءة.
                DB::table('jobs')->count();

                return true;
            }),
            'storage' => $this->timed(static function (): bool {
                /*
                 * مفتاح حقيقي لا '.' — مخزن الكائنات ليس نظام ملفّات.
                 *
                 * أوّل نسخة سألت عن '.'، فتُطبَّع إلى مفتاح فارغ يرفضه S3
                 * ويرمي UnableToCheckFileExistence. النتيجة أن الفحص أعلن
                 * «التخزين معطّل» على الإنتاج بينما الكتابة والقراءة والحذف
                 * كلها تنجح — فحصٌ يكذب في الاتجاه المعاكس، وهو تمامًا ما
                 * بُني هذا الملف لمنعه.
                 *
                 * وجود المفتاح غير مهمّ؛ المهمّ أن يردّ المخزن. الاستعلام
                 * يكلّف نحو 4ms ولا يكتب شيئًا، فلا يترك أثرًا كل خمس عشرة
                 * ثانية كما تفعل دورة كتابة/حذف.
                 */
                Storage::disk(config('filesystems.default'))->exists('.health/probe');

                return true;
            }),
        ];

        $failedCritical = array_keys(array_filter(
            $checks,
            static fn (array $c, string $name): bool => in_array($name, self::CRITICAL, true) && ! $c['ok'],
            ARRAY_FILTER_USE_BOTH,
        ));

        $anyFailed = in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $failedCritical !== [] ? 'unhealthy' : ($anyFailed ? 'degraded' : 'ok'),
            'checks' => $checks,
        ], $failedCritical !== [] ? 503 : 200);
    }

    /**
     * ينفّذ فحصًا ويعيد نتيجته ومدّته. أي استثناء = فشل، ولا يتسرّب نصّه.
     *
     * @param  callable(): bool  $check
     * @return array{ok: bool, ms: float}
     */
    private function timed(callable $check): array
    {
        $start = hrtime(true);

        try {
            $ok = $check();
        } catch (Throwable $e) {
            report($e);
            $ok = false;
        }

        return ['ok' => $ok, 'ms' => round((hrtime(true) - $start) / 1_000_000, 1)];
    }
}
