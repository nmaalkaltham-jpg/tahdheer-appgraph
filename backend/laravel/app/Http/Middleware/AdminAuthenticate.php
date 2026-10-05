<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session authentication for /api/v1/admin/*.
 *
 * The session id travels in an HttpOnly, SameSite=Lax cookie (never readable
 * by JS). Because the console is a separate origin from the API, SameSite
 * alone is not treated as sufficient: every state-changing request must also
 * echo a per-session token issued at login in the `X-Admin-Csrf` header
 * (double-submit, in-memory on the client — a stolen cookie without the token
 * cannot mutate anything).
 *
 * Responses are always JSON with Arabic messages; the console renders them.
 */
class AdminAuthenticate
{
    public const SESSION_KEY = 'admin_user_id';

    public const CSRF_KEY = 'admin_csrf';

    public const CSRF_HEADER = 'X-Admin-Csrf';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        $user = $id === null ? null : AdminUser::query()->find($id);

        if ($user === null) {
            return response()->json([
                'message' => 'يلزم تسجيل الدخول للوصول إلى لوحة التشغيل.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $expected = (string) $request->session()->get(self::CSRF_KEY, '');
            $presented = (string) $request->header(self::CSRF_HEADER, '');

            if ($expected === '' || ! hash_equals($expected, $presented)) {
                return response()->json([
                    'message' => 'رمز الحماية من التزوير مفقود أو غير صالح — أعد تسجيل الدخول.',
                    'code' => 'CSRF_MISMATCH',
                ], 419);
            }
        }

        // Bound as a shared instance so controllers can type-hint AdminUser.
        app()->instance(AdminUser::class, $user);

        return $next($request);
    }
}
