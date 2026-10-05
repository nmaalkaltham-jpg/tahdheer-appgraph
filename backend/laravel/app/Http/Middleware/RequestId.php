<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * معرّف ارتباط لكل طلب.
 *
 * لماذا: عطل واحد في هذا النظام يعبر أربع طبقات — متصفّح ⇒ Next ⇒ Laravel ⇒
 * عامل/قاعدة بيانات. بلا معرّف مشترك، ربط سطر في سجلّ العامل بالطلب الذي
 * أنتجه تخمينٌ بالوقت لا أكثر، ويصير سؤال «ما الذي حدث لطلب هذا العميل؟»
 * بلا جواب.
 *
 * ثلاث نقاط اتصال:
 *   · يُقبل معرّف وارد من الطبقة الأعلى (بعد تنظيفه) فتبقى السلسلة واحدة.
 *   · يُحقن في سياق السجلّات، فيحمله كل سطر يُكتب أثناء الطلب تلقائيًّا.
 *   · يُعاد في ترويسة الاستجابة، فيستطيع العميل — أو المستخدم عبر لقطة شاشة —
 *     تسليم المعرّف للدعم.
 *
 * المعرّف الوارد لا يُوثَق به كما هو: أي نصّ حرّ من الخارج يدخل السجلّات يفتح
 * باب حقن أسطر مزيّفة فيها. يُقبل الشكل السداسي عشري/الشرطات فقط، وبطول
 * محدود، وإلّا وُلّد معرّف جديد.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    /** مفتاح الحاوية — يقرؤه معالج الأخطاء ليضع المعرّف في جسم الخطأ. */
    public const CONTEXT_KEY = 'request-id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->inbound($request) ?? (string) Str::uuid();

        $request->attributes->set(self::CONTEXT_KEY, $id);
        app()->instance(self::CONTEXT_KEY, $id);

        // كل سطر سجلّ في هذا الطلب يحمل المعرّف بلا أن يمرّره أحد يدويًّا.
        Log::shareContext([self::CONTEXT_KEY => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /** معرّف وارد صالح، أو null. */
    private function inbound(Request $request): ?string
    {
        $raw = trim((string) $request->headers->get(self::HEADER, ''));

        if ($raw === '' || strlen($raw) > 64) {
            return null;
        }

        // حروف وأرقام وشرطات فقط: يمنع أسطرًا جديدة أو رموز تحكّم في السجلّ.
        return preg_match('/^[A-Za-z0-9-]+$/', $raw) === 1 ? $raw : null;
    }
}
