<?php

namespace Tests\Feature\Architecture;

use App\Domain\Admin\AdminRole;
use App\Http\Middleware\AdminAuthenticate;
use App\Http\Middleware\AdminAuthorize;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * كلّ مسارٍ إداريّ يغيّر حالةً يحمل مصادقةً وصلاحية (§٨٫٤).
 *
 * ─── لماذا اختبارٌ لا حارسٌ نصّيّ ───
 *
 * الوسيط قد يوضع على المسار نفسه أو على **المجموعة** المحيطة به. وفحصٌ
 * يقرأ السطور لا يرى المجموعة، فيبلّغ عن مساراتٍ محروسةٍ بوصفها مكشوفة —
 * والتبليغ الكاذب يُدرِّب المشغّل على تجاهل الحارس فيمرّ العيب الحقيقيّ.
 *
 * الموجّه وحده يعرف الوسائط المُحلَّة فعلًا بعد ضمّ المجموعات. فهو المرجع.
 *
 * ─── لماذا هذا يستحقّ اختبارًا دائمًا ───
 *
 * مسارٌ إداريّ جديد بلا صلاحيةٍ لا يُظهر عطلًا: يعمل تمامًا — لكلّ من
 * يستطيع تسجيل الدخول. ولا يُكتشف إلّا حين يستعمله من لا يملك الدور.
 */
class AdminRoutePermissionTest extends TestCase
{
    /** مساراتٌ خارج نموذج الصلاحيات بطبيعتها — لا تملك عاملًا بعد. */
    private const EXEMPT = [
        'api/v1/admin/auth/login',
        'api/v1/admin/auth/logout',
        'api/v1/admin/auth/refresh',
    ];

    /**
     * مساراتٌ تكتب في وثيقة صاحب الجلسة وحده — لا كيانَ في المتجر تحرسه
     * صلاحية. معفاةٌ من شرط الصلاحية **لا** من المصادقة: الفحص الثالث أدناه
     * يبقى يطالبها بـ`AdminAuthenticate`.
     */
    private const SELF_SCOPED = [
        'api/v1/admin/me/preferences',
    ];

    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @return list<Route> */
    private function adminRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            fn (Route $r) => str_starts_with($r->uri(), 'api/v1/admin/'),
        ));
    }

    public function test_admin_routes_exist(): void
    {
        // حارسٌ للحارس: تصفيةٌ خاطئة تجعل الاختبارين التاليين يمرّان فارغين.
        $this->assertGreaterThan(50, count($this->adminRoutes()));
    }

    public function test_every_mutating_admin_route_requires_a_permission(): void
    {
        $withoutPermission = [];

        foreach ($this->adminRoutes() as $route) {
            if (in_array($route->uri(), self::EXEMPT, true) || in_array($route->uri(), self::SELF_SCOPED, true)) {
                continue;
            }

            if (array_intersect($route->methods(), self::MUTATING) === []) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $hasPermission = false;

            foreach ($middleware as $entry) {
                if (! is_string($entry)) {
                    continue;
                }

                // الصيغة `App\Http\Middleware\AdminAuthorize:orders.refund`
                if (str_starts_with($entry, AdminAuthorize::class.':')) {
                    $hasPermission = true;
                    break;
                }
            }

            if (! $hasPermission) {
                $withoutPermission[] = implode('|', array_intersect($route->methods(), self::MUTATING))
                    .' /'.$route->uri();
            }
        }

        $this->assertSame([], $withoutPermission, "مسارٌ إداريّ يغيّر حالةً بلا صلاحية:\n".implode("\n", $withoutPermission));
    }

    public function test_every_admin_route_requires_authentication(): void
    {
        $unauthenticated = [];

        foreach ($this->adminRoutes() as $route) {
            if (in_array($route->uri(), self::EXEMPT, true)) {
                continue;
            }

            if (! in_array(AdminAuthenticate::class, $route->gatherMiddleware(), true)) {
                $unauthenticated[] = implode('|', $route->methods()).' /'.$route->uri();
            }
        }

        $this->assertSame([], $unauthenticated, "مسارٌ إداريّ بلا مصادقة:\n".implode("\n", $unauthenticated));
    }

    public function test_permission_names_are_declared_in_the_role_matrix(): void
    {
        // صلاحيةٌ بخطأٍ إملائيّ تُرفض للجميع صامتةً — ولا أحد يلاحظ إلّا صاحب الدور.
        $known = array_keys(AdminRole::CATALOGUE);
        $unknown = [];

        foreach ($this->adminRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $entry) {
                if (! is_string($entry) || ! str_starts_with($entry, AdminAuthorize::class.':')) {
                    continue;
                }

                foreach (explode(',', substr($entry, strlen(AdminAuthorize::class) + 1)) as $permission) {
                    if (! in_array($permission, $known, true)) {
                        $unknown[] = $permission.' ← /'.$route->uri();
                    }
                }
            }
        }

        $this->assertSame([], array_unique($unknown), "صلاحيةٌ غير معرّفة:\n".implode("\n", array_unique($unknown)));
    }
}
