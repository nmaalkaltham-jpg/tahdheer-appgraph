<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ترويسات أمان لاستجابات الـAPI.
 *
 * هذه استجابات JSON لا صفحات، فالمجموعة مقصودة ضيّقة ومناسبة لها:
 *
 *  · nosniff — يمنع المتصفّح من إعادة تخمين نوع المحتوى. استجابة JSON
 *    يخمّنها المتصفّح HTML هي ثغرة XSS جاهزة إن حملت نصًّا من المستخدم.
 *  · frame-ancestors 'none' — لا تُؤطَّر استجابة API في أي صفحة.
 *  · Referrer-Policy — لا يتسرّب مسار كامل (وقد يحمل رمزًا في مسار تحميل)
 *    إلى موقع خارجي عبر ترويسة الإحالة.
 *  · Cross-Origin-Resource-Policy: same-site — يمنع موقعًا آخر من تضمين
 *    الاستجابة كمورد.
 *
 * ما ليس هنا عمدًا:
 *  · HSTS — تخصّ الطبقة التي تنهي TLS (Traefik)، وضبطها من التطبيق خلف وكيل
 *    يعطي شعورًا بالحماية دون أن تصل الترويسة كما ينبغي في كل مسار.
 *  · CSP كامل — لا معنى له لاستجابة JSON؛ محلّه صفحات المتجر.
 *
 * لا تُلمس ترويسات CORS: يديرها Laravel من config/cors.php، وكتابتها هنا
 * تنتج مصدرين للحقيقة يتضاربان.
 */
final class SecurityHeaders
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Cross-Origin-Resource-Policy' => 'same-site',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            // لا يُدهس ما ضبطه متحكّم عمدًا (تنزيل ملفّ مثلًا).
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
