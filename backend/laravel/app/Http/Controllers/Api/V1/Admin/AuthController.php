<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\AdminValidation;
use App\Domain\Admin\Models\AdminUser;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminAuthenticate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Session login for the operations console. The password hash never leaves
 * the server and failures are deliberately indistinguishable (unknown email
 * and wrong password return the identical message) so the endpoint cannot be
 * used to enumerate operator accounts.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AdminAuditLogger $audit) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|max:255',
        ], AdminValidation::MESSAGES);

        $user = AdminUser::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();

        if ($user === null || ! Hash::check($data['password'], (string) $user->password_hash)) {
            /*
             * المحاولة الفاشلة تُسجَّل — ولم تكن تُسجَّل.
             *
             * كانت شاشة الأمان تُعلن «محاولات الدخول الفاشلة غير مهيّأة» لأنّ
             * لا جدول يحفظها، والحماية الوحيدة مُحدِّد معدّلٍ في الذاكرة. أي
             * أنّ حملة تخمينٍ كاملة تمرّ بلا أن تترك أثرًا يُقرأ بعدها.
             *
             * والردّ نفسه لكلتا الحالتين عمدًا: تمييزُ «بريد مجهول» عن «كلمة
             * مرور خاطئة» في الردّ يُسرّب أيّ الحسابات موجود. التمييز يبقى في
             * السجلّ الداخليّ وحده حيث يخدم التحقيق ولا يخدم المهاجم.
             */
            $this->audit->loginFailed($data['email'], $user !== null, $request);

            return response()->json([
                'message' => 'بيانات الدخول غير صحيحة.',
                'code' => 'INVALID_CREDENTIALS',
            ], 401);
        }

        // Destroy the pre-login session id as well (fixation protection).
        $request->session()->regenerate(true);
        $token = Str::random(40);
        $request->session()->put(AdminAuthenticate::SESSION_KEY, $user->id);
        $request->session()->put(AdminAuthenticate::CSRF_KEY, $token);

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json($this->identity($user, $token));
    }

    public function logout(Request $request): JsonResponse
    {
        // invalidate() = flush + regenerate WITH destroy, so the old session id
        // is unusable even if its cookie is replayed.
        $request->session()->invalidate();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    public function me(Request $request, AdminUser $user): JsonResponse
    {
        return response()->json($this->identity(
            $user,
            (string) $request->session()->get(AdminAuthenticate::CSRF_KEY, ''),
        ));
    }

    private function identity(AdminUser $user, string $csrfToken): array
    {
        return [
            'user' => $user->toIdentityArray(),
            'permissions' => $user->role->permissions(),
            'csrfToken' => $csrfToken,
        ];
    }
}
