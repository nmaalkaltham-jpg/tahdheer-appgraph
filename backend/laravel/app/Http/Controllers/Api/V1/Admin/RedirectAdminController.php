<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Operations\OperationLedger;
use App\Domain\Operations\RequestFingerprint;
use App\Domain\Redirects\RedirectRule;
use App\Domain\Redirects\RedirectRuleError;
use App\Domain\Redirects\RedirectService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إدارة قواعد التحويل.
 *
 * ─── لماذا وحدة التحكّم رقيقة إلى هذا الحدّ ───
 *
 * `RedirectService::save` يملك الضمانتين ويفرضهما: لا حلقة، ولا هدفٌ
 * معروفٌ بأنّه ٤٠٤. ويحلّ السلسلة **وقت الحفظ** فيُخزَّن الهدف نهائيًّا —
 * فلا قفزتان في طلب زائر. إعادةُ أيٍّ من ذلك هنا تعني نسختين تتباعدان،
 * وأخطرُ ما فيهما أنّ الواجهة العامّة تقرأ نسخةً والإدارة تكتب بالأخرى.
 *
 * فدورُ هذا الملفّ ثلاثة: يترجم أسماء الحقول، ويُدقّق، ويحوّل خطأ النطاق
 * إلى ٤٢٢ تحت اسم الحقل الذي يخصّه.
 *
 * ─── لماذا يُترجم الخطأ إلى حقل ───
 *
 * «حلقة تحويل» ليست خطأً عامًّا: مصدرها حقلٌ بعينه. وردُّها بلا حقل يجعل
 * النموذج يعرض شريطًا أحمر أعلى الصفحة ولا يُبرز المدخل الذي يجب تغييره.
 */
class RedirectAdminController extends Controller
{
    /** أيّ حقلٍ يخصّه كلّ خطأ — فيُبرز النموذج المدخل الصحيح. */
    private const FIELD_FOR = [
        RedirectRuleError::SELF_REDIRECT => 'toPath',
        RedirectRuleError::LOOP => 'toPath',
        RedirectRuleError::TARGET_NOT_FOUND => 'toPath',
        RedirectRuleError::INVALID_TARGET => 'toPath',
        RedirectRuleError::INVALID_SOURCE => 'fromPath',
        RedirectRuleError::DUPLICATE_SOURCE => 'fromPath',
        RedirectRuleError::INVALID_STATUS => 'statusCode',
        RedirectRuleError::STALE_VERSION => 'expectedVersion',
    ];

    /**
     * رمزٌ يُرَدّ لكلّ رفض.
     *
     * التعارض ليس خطأ إدخال: المشغّل لم يُخطئ، بل سبقه آخر. و٤٢٢ كان
     * سيجعل النموذج يعرضه بوصفه حقلًا معطوبًا، والصواب أن يعرض الحاليّ
     * ليُقارَن — ولذلك للرمز معنًى مختلف.
     */
    private const STATUS_FOR = [
        RedirectRuleError::STALE_VERSION => 409,
    ];

    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly RedirectService $redirects,
        private readonly OperationLedger $operations,
    ) {}

    public function index(): JsonResponse
    {
        $rules = RedirectRule::query()->orderByDesc('hits')->orderBy('from_path')->get();

        return response()->json([
            'data' => $rules->map($this->present(...))->values(),
            'counts' => [
                'all' => $rules->count(),
                'enabled' => $rules->where('enabled', true)->count(),
                // «أطلقت» لا «موجودة»: قاعدةٌ بلا إصابةٍ واحدة قد تكون ميتة.
                'fired' => $rules->where('hits', '>', 0)->count(),
            ],
        ]);
    }

    /**
     * إنشاءُ قاعدة — تحت مفتاح خمولٍ مُلزم.
     *
     * إعادةُ إرسال النموذج بعد مهلةٍ عند الوكيل كانت تُنشئ صفًّا ثانيًا أو
     * تردّ «مصدرٌ مكرّر» على طلبٍ هو نفسه الأوّل. المفتاح يجعل الثانية
     * تُعيد جواب الأولى ولا تكتب شيئًا.
     */
    public function store(Request $request, AdminUser $actor): JsonResponse
    {
        $data = $this->validated($request);
        $key = $this->idempotencyKey($request);

        [$operation, $isNew] = $this->operations->reserve(
            "redirect_rule_create:{$key}",
            'redirect_rule_create',
            'redirect_rule',
            null,
            $actor->id,
            RequestFingerprint::of($this->attributes($data)),
        );

        if (! $isNew) {
            return $this->replay($operation);
        }

        try {
            $rule = $this->redirects->save($this->attributes($data));
        } catch (RedirectRuleError $e) {
            /*
             * الرفض يُنهي العمليّة فشلًا ولا يتركها محجوزةً إلى الأبد —
             * وإلّا لَما استطاع المشغّل إعادة الإرسال بالمفتاح نفسه بعد
             * تصحيح المدخل، وهو التصرّف الطبيعيّ أمام خطأ تحقّق.
             */
            $refusal = $this->refuse($e);
            /*
             * `PERMANENT_BUSINESS` لا `TEMPORARY`: القاعدة مرفوضةٌ لذاتها،
             * وإعادةُ المحاولة بالطلب نفسه ستُرفض بالرفض نفسه. والجواب
             * يُحفظ ليُعاد حرفيًّا — فإعادةُ الإرسال تقول ما يجب تغييره لا
             * «عمليّةٌ قيد التنفيذ».
             */
            $this->operations->fail($operation, 'PERMANENT_BUSINESS', $e->getMessage(), [
                'refusal' => $refusal->getData(true),
                'status' => $refusal->getStatusCode(),
            ]);

            return $refusal;
        }

        $payload = $this->present($rule);
        $this->operations->succeed($operation, $payload);

        $this->audit->log(
            $actor, 'redirects.rule.created', 'redirect_rule', $rule->id,
            null, $rule->toArray(), $data['reason'] ?? null, $request,
        );

        return response()->json([
            'data' => $payload,
            'operationId' => $operation->id,
        ], 201);
    }

    /**
     * تحريرُ قاعدة — النسخة المتوقّعة مُلزمة.
     *
     * غيابُها يعني «اكتب فوق ما لا أعرفه»، وهو السلوك الذي انتهى. والنطاق
     * هو من يقارن تحت قفل الصفّ: مقارنةٌ هنا كانت ستترك فجوةً بين الفحص
     * والكتابة يتسلّل منها المحرِّر الثاني.
     */
    public function update(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $rule = RedirectRule::query()->find($id);

        if ($rule === null) {
            return response()->json(['message' => 'لا قاعدة تحويلٍ بهذا المعرّف.'], 404);
        }

        $data = $this->validated($request, requiresVersion: true);
        $key = $this->idempotencyKey($request);
        $before = $rule->toArray();

        [$operation, $isNew] = $this->operations->reserve(
            "redirect_rule_update:{$id}:{$key}",
            'redirect_rule_update',
            'redirect_rule',
            $id,
            $actor->id,
            RequestFingerprint::of($this->attributes($data) + ['expectedVersion' => (int) $data['expectedVersion']]),
        );

        if (! $isNew) {
            return $this->replay($operation);
        }

        try {
            $rule = $this->redirects->save($this->attributes($data), $rule->id, (int) $data['expectedVersion']);
        } catch (RedirectRuleError $e) {
            $refusal = $this->refuse($e);
            /*
             * `PERMANENT_BUSINESS` لا `TEMPORARY`: القاعدة مرفوضةٌ لذاتها،
             * وإعادةُ المحاولة بالطلب نفسه ستُرفض بالرفض نفسه. والجواب
             * يُحفظ ليُعاد حرفيًّا — فإعادةُ الإرسال تقول ما يجب تغييره لا
             * «عمليّةٌ قيد التنفيذ».
             */
            $this->operations->fail($operation, 'PERMANENT_BUSINESS', $e->getMessage(), [
                'refusal' => $refusal->getData(true),
                'status' => $refusal->getStatusCode(),
            ]);

            return $refusal;
        }

        $payload = $this->present($rule);
        $this->operations->succeed($operation, $payload);

        $this->audit->log(
            $actor, 'redirects.rule.updated', 'redirect_rule', $rule->id,
            $before, $rule->toArray(), $data['reason'] ?? null, $request,
        );

        return response()->json([
            'data' => $payload,
            'operationId' => $operation->id,
        ]);
    }

    /**
     * الحذف حذفٌ لا تعطيل.
     *
     * التعطيل متاحٌ في النموذج ومرئيّ في الجدول. والحذف يُطلب حين تكون
     * القاعدة خطأً من أصلها — وإبقاؤها معطّلةً يُراكم صفوفًا يقرؤها الوسيط
     * في كلّ طلب ولا تفعل شيئًا.
     */
    public function destroy(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        /*
         * الوجود يُفحص قبل النسخة. عكسُه كان يردّ «النسخة مطلوبة» على قاعدةٍ
         * محذوفةٍ أصلًا، فيبحث المشغّل عن نسخةٍ لصفٍّ لا وجود له.
         */
        $rule = RedirectRule::query()->find($id);

        if ($rule === null) {
            return response()->json(['message' => 'لا قاعدة تحويلٍ بهذا المعرّف.'], 404);
        }

        $expected = $request->input('expectedVersion');

        if (! is_numeric($expected)) {
            return response()->json([
                'message' => 'النسخة المتوقّعة مطلوبة للحذف.',
                'code' => 'EXPECTED_VERSION_REQUIRED',
                'errors' => ['expectedVersion' => ['النسخة المتوقّعة مطلوبة للحذف.']],
            ], 422);
        }

        $conflict = null;

        DB::transaction(function () use ($id, $expected, $actor, $request, &$conflict) {
            /*
             * القفل ثمّ المقارنة. الحذف بلا فحص نسخةٍ يمحو تحريرًا وقع قبل
             * ثوانٍ ولا يُبقي منه إلّا صفًّا في سجلّ التدقيق لا يقرؤه أحد
             * إلّا بعد أن يُسأل: «أين ذهبت القاعدة؟».
             */
            $locked = RedirectRule::query()->whereKey($id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            if ((int) $locked->version !== (int) $expected) {
                $conflict = RedirectRuleError::staleVersion((int) $expected, (int) $locked->version, [
                    'id' => (int) $locked->id,
                    'fromPath' => (string) $locked->from_path,
                    'toPath' => (string) $locked->to_path,
                    'statusCode' => (int) $locked->status_code,
                    'enabled' => (bool) $locked->enabled,
                    'version' => (int) $locked->version,
                ]);

                return;
            }

            $before = $locked->toArray();
            $locked->delete();

            $this->audit->log(
                $actor, 'redirects.rule.deleted', 'redirect_rule', $before['id'],
                $before, null, $request->input('reason'), $request,
            );
        });

        if ($conflict !== null) {
            return $this->refuse($conflict);
        }

        return response()->json(['deleted' => true]);
    }

    /**
     * مفتاح الخمول: من الترويسة أو من الجسم.
     *
     * الترويسة هي الشكل المعياريّ لعملاء الواجهة البرمجيّة، والجسم هو ما
     * تستطيع اللوحة إرساله بلا إعادة كتابة طبقة النداء كلّها. والاثنان
     * يُقرآن لأنّ فرض شكلٍ واحد كان سيترك أحد الطرفين بلا خمول.
     */
    private function idempotencyKey(Request $request): string
    {
        $key = (string) ($request->header('Idempotency-Key') ?? $request->input('idempotencyKey') ?? '');
        $key = trim($key);

        if ($key === '' || mb_strlen($key) > 200) {
            throw ValidationException::withMessages([
                'idempotencyKey' => ['مفتاح الخمول مطلوب (ترويسة Idempotency-Key أو حقل idempotencyKey).'],
            ]);
        }

        return $key;
    }

    /**
     * طلبٌ مكرّر بالمفتاح نفسه — يُعاد جوابُ الأوّل ولا يُكتب شيء.
     *
     * والذي لم يُحسم بعد لا يُعاد بوصفه نجاحًا: يُقال إنّه قيد التنفيذ،
     * لأنّ إعلان نجاحٍ لم يقع وعدٌ بما لا وجود له.
     */
    private function replay(OperationRecord $operation): JsonResponse
    {
        if ($operation->status === 'SUCCEEDED') {
            return response()->json([
                'data' => $operation->result,
                'operationId' => $operation->id,
                'duplicate' => true,
            ]);
        }

        // رفضٌ محفوظ: يُعاد كما كان — نفس الرمز ونفس الحقل.
        $stored = is_array($operation->result) ? $operation->result : [];

        if (isset($stored['refusal'], $stored['status'])) {
            return response()->json(
                $stored['refusal'] + ['operationId' => $operation->id, 'duplicate' => true],
                (int) $stored['status'],
            );
        }

        return response()->json([
            'message' => 'طلبٌ بالمفتاح نفسه قيد التنفيذ أو انتهى برفض.',
            'code' => 'OPERATION_IN_FLIGHT',
            'operationId' => $operation->id,
            'operationStatus' => $operation->status,
            'duplicate' => true,
        ], 409);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $requiresVersion = false): array
    {
        return $request->validate([
            'fromPath' => 'required|string|max:2048',
            'toPath' => 'required|string|max:2048',
            // ٣٠١ دائم و٣٠٢ مؤقّت. لا ثالث: البقيّة تُفسَّر تفسيراتٍ مختلفة.
            'statusCode' => 'required|integer|in:301,302',
            'enabled' => 'sometimes|boolean',
            'reason' => 'sometimes|nullable|string|max:500',
            // التحرير بلا نسخةٍ متوقّعة هو «آخر كاتبٍ يفوز» تحت اسمٍ آخر.
            'expectedVersion' => ($requiresVersion ? 'required' : 'sometimes').'|integer|min:1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'from_path' => $data['fromPath'],
            'to_path' => $data['toPath'],
            'status_code' => (int) $data['statusCode'],
            'enabled' => (bool) ($data['enabled'] ?? true),
            'reason' => $data['reason'] ?? null,
        ];
    }

    private function refuse(RedirectRuleError $error): JsonResponse
    {
        $field = self::FIELD_FOR[$error->errorCode] ?? 'fromPath';

        return response()->json([
            'message' => $error->getMessage(),
            'code' => $error->errorCode,
            'errors' => [$field => [$error->getMessage()]],
            // الحاليّ يُرَدّ مع التعارض ليُقارَن — لا ليُعاد الإرسال أعمى.
        ] + ($error->context === [] ? [] : ['conflict' => $error->context]),
            self::STATUS_FOR[$error->errorCode] ?? 422);
    }

    /** @return array<string, mixed> */
    private function present(RedirectRule $rule): array
    {
        return [
            'id' => $rule->id,
            'fromPath' => $rule->from_path,
            'toPath' => $rule->to_path,
            'statusCode' => (int) $rule->status_code,
            'enabled' => (bool) $rule->enabled,
            'hits' => (int) $rule->hits,
            // النسخة تُرَدّ لتعود في التحرير التالي — بلا رحلةٍ إضافيّة.
            'version' => (int) $rule->version,
            'reason' => $rule->reason,
            // النجمة تعني بادئة: `/old/*` تلتقط كلّ ما تحتها.
            'isWildcard' => str_ends_with((string) $rule->from_path, '/*'),
            'createdAt' => $rule->created_at?->toIso8601String(),
            'updatedAt' => $rule->updated_at?->toIso8601String(),
        ];
    }
}
