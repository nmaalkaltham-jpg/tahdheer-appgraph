<?php

namespace App\Http\Middleware;

use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Granular permission check (MIP §8.4) — always server-side, never a UI hint.
 * Runs after AdminAuthenticate, which binds the resolved operator.
 *
 * Usage: ->middleware(AdminAuthorize::class.':products.publish')
 */
class AdminAuthorize
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = app()->bound(AdminUser::class) ? app(AdminUser::class) : null;

        if (! $user instanceof AdminUser) {
            return response()->json([
                'message' => 'يلزم تسجيل الدخول للوصول إلى لوحة التشغيل.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        foreach ($permissions as $permission) {
            if (! $user->allows($permission)) {
                return response()->json([
                    'message' => 'صلاحية «'.AdminRole::labelForPermission($permission).'» غير ممنوحة لدورك ('.$user->role->labelAr().').',
                    'code' => 'FORBIDDEN',
                    'requiredPermission' => $permission,
                ], 403);
            }
        }

        return $next($request);
    }
}
