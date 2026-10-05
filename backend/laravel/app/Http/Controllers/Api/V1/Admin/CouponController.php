<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Cart\CouponRules;
use App\Domain\Cart\Models\Coupon;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * رموز الخصم — القدرة التي كانت كاملة بلا باب.
 *
 * الجدول والنموذج والقواعد وتطبيق السلة وإعادة الحساب عند إنشاء الطلب: كلّها
 * مبنيّة ومُختبَرة. ولم يكن في المنتج أي سبيل لإنشاء رمز واحد — فمالك المتجر
 * لا يستطيع إصدار خصم إلا بكتابة صفٍّ في قاعدة البيانات بيده.
 *
 * ثلاثة قرارات في هذا المتحكّم:
 *
 *  · **لا حذف لرمز استُعمل.** الطلبات تحفظ مبلغ الخصم لا الرمز، لكن حذف رمز
 *    استعمله عميل يمحو تفسير خصمٍ في سجلٍّ محاسبي. المستعمَل يُنهى بتاريخ،
 *    وغير المستعمَل وحده يُحذف.
 *
 *  · **الحالة محسوبة لا مخزَّنة.** «ساري» ليس عمودًا يُنسى تحديثه، بل نتيجة
 *    `CouponRules::usable` — نفس الدالّة التي تحكم السلة. فما تعرضه اللوحة هو
 *    ما سيحدث للمشتري، لا رأيًا ثانيًا فيه.
 *
 *  · **العدّاد يُعرض ولا يُكتب.** `usage_count` يرتفع عند إنشاء الطلب؛ تعديله
 *    من اللوحة يفسد حدّ الاستعمال بلا أثر مرئي.
 */
class CouponController extends Controller
{
    public function __construct(private readonly AdminAuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $rows = Coupon::query()->orderByDesc('id')->limit(200)->get();

        return response()->json([
            'data' => $rows->map(fn (Coupon $coupon) => $this->present($coupon))->all(),
            'counts' => [
                'all' => $rows->count(),
                'active' => $rows->filter(static fn (Coupon $c) => CouponRules::usable($c))->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request, null);

        $coupon = Coupon::query()->create($validated + ['usage_count' => 0]);

        $this->audit->log(
            $this->actor(), 'coupon.created', 'coupon', $coupon->id, null, $validated, null, $request,
        );

        return response()->json(['data' => $this->present($coupon)], 201);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $before = $coupon->only(['code', 'type', 'value', 'usage_limit', 'expires_at', 'first_order_per_account']);
        $validated = $this->validated($request, $coupon);

        $coupon->update($validated);

        $this->audit->log(
            $this->actor(), 'coupon.updated', 'coupon', $coupon->id, $before, $validated, null, $request,
        );

        return response()->json(['data' => $this->present($coupon->fresh())]);
    }

    /**
     * الإنهاء الفوري — البديل الآمن للحذف.
     *
     * يضع تاريخ انتهاء في الماضي، فتُرجعه `CouponRules::usable` غير صالح فورًا
     * في السلة وفي إعادة الحساب معًا. لا عمود «مفعَّل» ثانٍ يمكن أن يختلف عن
     * تاريخ الانتهاء.
     */
    public function expire(Request $request, Coupon $coupon): JsonResponse
    {
        $coupon->update(['expires_at' => now()->subSecond()]);

        $this->audit->log(
            $this->actor(), 'coupon.expired', 'coupon', $coupon->id, null, null, null, $request,
        );

        return response()->json(['data' => $this->present($coupon->fresh())]);
    }

    public function destroy(Request $request, Coupon $coupon): JsonResponse
    {
        if ((int) $coupon->usage_count > 0) {
            return response()->json([
                'message' => 'استُعمل هذا الرمز في طلبات — أنهِه بتاريخ بدل حذفه كي يبقى تفسير الخصم في السجل.',
                'error' => ['code' => 'COUPON_IN_USE'],
            ], 422);
        }

        $id = $coupon->id;
        $coupon->delete();

        $this->audit->log($this->actor(), 'coupon.deleted', 'coupon', $id, null, null, null, $request);

        return response()->json(['data' => null]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Coupon $existing): array
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('coupons', 'code')->ignore($existing?->id),
            ],
            'type' => 'required|in:percent,fixed',
            /*
             * المدى يتبع النوع: نسبة فوق ١٠٠ خصمٌ أكبر من السعر، ومبلغ ثابت
             * بالهللات لا بالريالات. التحقّق هنا لأن رفض القيمة عند الإنشاء
             * أرخص من اكتشافها في سلة مشترٍ.
             */
            'value' => 'required|integer|min:1',
            'usageLimit' => 'nullable|integer|min:1',
            'expiresAt' => 'nullable|date',
            'firstOrderPerAccount' => 'sometimes|boolean',
        ], [
            'code.regex' => 'الرمز حروف وأرقام وشرطات فقط — المسافة والرموز تُكتب خطأً في السلة.',
        ]);

        if ($validated['type'] === 'percent' && (int) $validated['value'] > 100) {
            abort(response()->json([
                'message' => 'نسبة الخصم لا تتجاوز ١٠٠٪.',
                'errors' => ['value' => ['نسبة الخصم لا تتجاوز ١٠٠٪.']],
            ], 422));
        }

        return [
            'code' => $validated['code'],
            'type' => $validated['type'],
            'value' => (int) $validated['value'],
            'usage_limit' => $validated['usageLimit'] ?? null,
            'expires_at' => $validated['expiresAt'] ?? null,
            'first_order_per_account' => (bool) ($validated['firstOrderPerAccount'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function present(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'type' => $coupon->type,
            'value' => (int) $coupon->value,
            'label' => $coupon->label(),
            'usageLimit' => $coupon->usage_limit,
            'usageCount' => (int) $coupon->usage_count,
            'expiresAt' => $coupon->expires_at?->toIso8601String(),
            'firstOrderPerAccount' => (bool) $coupon->first_order_per_account,
            // نفس الدالّة التي تحكم السلة — لا رأي ثانٍ في الصلاحية.
            'usable' => CouponRules::usable($coupon),
            'reason' => $this->reason($coupon),
        ];
    }

    /** لماذا لا يعمل الرمز — لا «غير صالح» وحدها. */
    private function reason(Coupon $coupon): ?string
    {
        if ($coupon->expires_at !== null && $coupon->expires_at->isPast()) {
            return 'انتهى تاريخه';
        }
        if ($coupon->usage_limit !== null && $coupon->usage_count >= $coupon->usage_limit) {
            return 'بلغ حدّ الاستعمال';
        }

        return null;
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
