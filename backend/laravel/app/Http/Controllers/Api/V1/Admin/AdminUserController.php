<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\AdminRole;
use App\Domain\Admin\AdminValidation;
use App\Domain\Admin\Models\AdminUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * مستخدمو اللوحة وأدوارهم — القدرة الأخيرة التي كانت اللوحة تُقرّ بغيابها.
 *
 * شاشة الإعدادات كانت تقول حرفيًّا: «إدارة الحسابات من اللوحة لم تُبْنَ؛ الحساب
 * يُنشأ من الخادم عبر AdminUserSeeder». وكان ذلك صدقًا لا عذرًا: نظام الأدوار
 * كامل ومفروض على كل مسار منذ اليوم الأول، والناقص بابُه وحده.
 *
 * ─── ثلاثة أقفال تمنع إغلاق الباب على الجميع ───
 *
 * صلاحية `users.manage` يملكها **المالك وحده**. فحسابٌ واحد خاطئ يمحو قدرة
 * المتجر على إدارة نفسه إلى الأبد — لا استعادة إلا بكتابة صفٍّ في قاعدة
 * البيانات باليد. ولذلك ثلاثة قيود، كلٌّ منها يمنع طريقًا مختلفًا إلى القفل:
 *
 *  ١. **لا تحذف نفسك.** أوضح الطرق وأسخفها.
 *
 *  ٢. **لا تغيّر دور نفسك.** مالكٌ يخفض نفسه إلى «قراءة فقط» يفقد `users.manage`
 *     في اللحظة نفسها، فلا يستطيع استرجاعه. والقيد ليس وصاية: من أراد التنازل
 *     يرفع غيره مالكًا أوّلًا ثمّ يخفضه ذلك الغير — خطوتان بفاعلَين، وهو
 *     بالضبط ما يجعل الخطأ الصامت مستحيلًا.
 *
 *  ٣. **لا يبقى المتجر بلا مالك.** آخر مالك لا يُحذف ولا يُخفَّض. وهو القيد
 *     الحقيقي؛ الأوّلان حالتان خاصّتان منه يستحقّان رسالةً أوضح.
 *
 * ─── وكلمة المرور ───
 *
 * لا تُعاد في أيّ ردّ، ولا تُكتب في سجلّ التدقيق — لا في `before` ولا في
 * `after`. التدقيق يسجّل **أنّ** كلمة المرور بُدّلت ومن بدّلها ومتى، لا ما
 * هي. وتبديلها نقطة نهاية مستقلّة لا حقلٌ في التعديل العامّ: خلطها بتغيير
 * الاسم يجعل كل تعديلٍ حمولةً تحمل سرًّا بلا داعٍ.
 */
class AdminUserController extends Controller
{
    /** ١٢ محرفًا: حساب لوحة يملك المتجر كلّه، لا حساب تعليقات. */
    private const MIN_PASSWORD = 12;

    public function __construct(private readonly AdminAuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $rows = AdminUser::query()->orderBy('id')->get();

        return response()->json([
            'data' => $rows->map(fn (AdminUser $user) => $this->row($user))->all(),
            /*
             * الأدوار وصلاحياتها تُرسل مع القائمة، فتبني الواجهة قائمة الاختيار
             * وشرح كل دور من **المصدر الذي يفرضه الخادم** — لا من نسخة مكتوبة
             * في الواجهة تتقادم بصمت عند أوّل تعديل على AdminRole.
             */
            'roles' => array_map(fn (AdminRole $role) => [
                'value' => $role->value,
                'label' => $role->labelAr(),
                'permissions' => array_map(fn (string $key) => [
                    'key' => $key,
                    'label' => AdminRole::labelForPermission($key),
                ], $role->permissions()),
            ], AdminRole::cases()),
            'meta' => [
                'ownerCount' => $rows->where('role', AdminRole::Owner)->count(),
                'currentUserId' => $this->actor()?->id,
                'minPasswordLength' => self::MIN_PASSWORD,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email|max:190|unique:admin_users,email',
            'password' => 'required|string|min:'.self::MIN_PASSWORD.'|max:200',
            'role' => ['required', Rule::enum(AdminRole::class)],
        ], AdminValidation::MESSAGES);

        $user = AdminUser::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'role' => $data['role'],
        ]);

        $this->audit->log(
            $this->actor(), 'admin_user.created', 'admin_user', $user->id,
            null, $this->row($user), null, $request,
        );

        return response()->json(['data' => $this->row($user)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = AdminUser::query()->find($id);
        if ($user === null) {
            return $this->missing();
        }

        $data = $request->validate([
            'name' => 'sometimes|string|min:2|max:120',
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('admin_users', 'email')->ignore($user->id)],
            'role' => ['sometimes', Rule::enum(AdminRole::class)],
        ], AdminValidation::MESSAGES);

        $newRole = isset($data['role']) ? AdminRole::from($data['role']) : null;
        $roleChanges = $newRole !== null && $newRole !== $user->role;

        /*
         * الفحص والكتابة في معاملةٍ واحدة — لأنّ «آخر مالك» ثابتٌ لا شرط.
         *
         * اقرأ·تحقّق·اكتب بلا قفل ليس حراسةً: مالكان، وطلبان متزامنان يخفض
         * كلٌّ منهما واحدًا. يقرأ الأوّل «٢» فيمرّ، ويقرأ الثاني «٢» فيمرّ،
         * ثمّ يكتبان — فتبقى اللوحة بلا مالك، و`users.manage` للمالك وحده،
         * فلا يدَ تُصلح ما وقع إلّا صفًّا يُكتب في قاعدة البيانات مباشرةً.
         *
         * و`ownerCount()` يقفل صفوف الملّاك (`lockForUpdate`)، فالطلب الثاني
         * ينتظر التزام الأوّل ثمّ يقرأ «١» فيُردّ بـLAST_OWNER — وهو الجواب
         * الصحيح الذي كان سيُعطى لو تعاقبا.
         */
        return DB::transaction(function () use ($request, $user, $data, $roleChanges) {
            if ($roleChanges) {
                if ($user->id === $this->actor()?->id) {
                    return $this->conflict(
                        'SELF_ROLE_CHANGE',
                        'لا يمكنك تغيير دور حسابك بنفسك: خفضُ دورك يسحب منك صلاحية إدارة المستخدمين في اللحظة نفسها فلا تستطيع التراجع. ارفع حسابًا آخر إلى «المالك» وليُغيّر هو دورك.',
                    );
                }
                if ($user->role === AdminRole::Owner && $this->ownerCount() <= 1) {
                    return $this->conflict(
                        'LAST_OWNER',
                        'هذا آخر حساب بدور «المالك»، وهو وحده يملك إدارة المستخدمين. خفضُه يترك المتجر بلا من يديره. عيّن مالكًا آخر أوّلًا.',
                    );
                }
            }

            $before = $this->row($user);
            $user->fill($data)->save();

            $this->audit->log(
                $this->actor(),
                $roleChanges ? 'admin_user.role_changed' : 'admin_user.updated',
                'admin_user', $user->id, $before, $this->row($user->fresh()), null, $request,
            );

            return response()->json(['data' => $this->row($user->fresh())]);
        });
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $user = AdminUser::query()->find($id);
        if ($user === null) {
            return $this->missing();
        }

        $data = $request->validate([
            'password' => 'required|string|min:'.self::MIN_PASSWORD.'|max:200',
        ], AdminValidation::MESSAGES);

        $user->password_hash = Hash::make($data['password']);
        $user->save();

        /*
         * لا `before` ولا `after`: كلاهما سيحمل تجزئة كلمة المرور أو ما يدلّ
         * عليها. الحدث نفسه هو المعلومة المطلوبة — من بدّل، لمن، ومتى.
         */
        $this->audit->log(
            $this->actor(), 'admin_user.password_reset', 'admin_user', $user->id,
            null, null, 'كلمة مرور جديدة عُيّنت من اللوحة.', $request,
        );

        return response()->json(['data' => $this->row($user->fresh())]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = AdminUser::query()->find($id);
        if ($user === null) {
            return $this->missing();
        }

        if ($user->id === $this->actor()?->id) {
            return $this->conflict(
                'SELF_DELETE',
                'لا يمكنك حذف حسابك وأنت تستعمله. ليحذفه مالك آخر.',
            );
        }

        /* المعاملة والقفل لعين السبب المشروح في `update()` أعلاه. */
        return DB::transaction(function () use ($request, $user, $id) {
            if ($user->role === AdminRole::Owner && $this->ownerCount() <= 1) {
                return $this->conflict(
                    'LAST_OWNER',
                    'هذا آخر حساب بدور «المالك». حذفه يترك المتجر بلا من يديره. عيّن مالكًا آخر أوّلًا.',
                );
            }

            /*
             * التدقيق قبل الحذف لا بعده: بعده لا يبقى صفٌّ يُقرأ منه ما حُذف.
             * وسجلّ التدقيق نفسه يحتفظ ببريد الفاعل حتى لو حُذف حسابه لاحقًا
             * (`actor_email` في الجدول)، فالأثر لا ينكسر بحذف صاحبه.
             */
            $snapshot = $this->row($user);
            $this->audit->log(
                $this->actor(), 'admin_user.deleted', 'admin_user', $user->id,
                $snapshot, null, null, $request,
            );

            $user->delete();

            return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
        });
    }

    /** @return array<string, mixed> */
    private function row(AdminUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'roleLabel' => $user->role->labelAr(),
            'lastLoginAt' => $user->last_login_at?->toIso8601String(),
            'createdAt' => $user->created_at?->toIso8601String(),
        ];
    }

    private function actor(): ?AdminUser
    {
        $user = app()->bound(AdminUser::class) ? app(AdminUser::class) : null;

        return $user instanceof AdminUser ? $user : null;
    }

    /**
     * عدد الملّاك — **مقفولًا**.
     *
     * ‏`pluck('id')->count()` لا `count()`: PostgreSQL يرفض `FOR UPDATE` مع
     * دالّة تجميع («FOR UPDATE is not allowed with aggregate functions»)،
     * فالصفوف تُجلب مقفولةً ثمّ تُعدّ في PHP. والعدد صغيرٌ بطبعه — الملّاك
     * قلّةٌ في أيّ متجر — فلا كلفةَ في جلب معرّفاتهم.
     */
    private function ownerCount(): int
    {
        return AdminUser::query()
            ->where('role', AdminRole::Owner->value)
            ->lockForUpdate()
            ->pluck('id')
            ->count();
    }

    private function missing(): JsonResponse
    {
        return response()->json(['message' => 'الحساب غير موجود.', 'code' => 'NOT_FOUND'], 404);
    }

    private function conflict(string $code, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], 409);
    }
}
