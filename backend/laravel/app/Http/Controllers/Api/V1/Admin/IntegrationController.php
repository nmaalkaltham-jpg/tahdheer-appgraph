<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Integrations\CredentialVault;
use App\Domain\Integrations\IntegrationCatalogue;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * مفاتيح الربط — بوّابة الاعتمادات.
 *
 * ─── لماذا وحدة التحكّم رقيقة ───
 *
 * `CredentialVault` يملك التعمية والقناع والبصمة وفحص البادئة والبيئة.
 * وإعادةُ أيٍّ من ذلك هنا تعني نسختين تتباعدان — وأخطر ما فيهما أنّ إحداهما
 * قد تُخزّن سرًّا ظاهرًا.
 *
 * ─── ثلاث قواعد لا تُخالَف ───
 *
 * ١ — لا قيمة سرّيّة تُرَدّ في أيّ استجابة. الخزنة تُعيد معاينةً مقنَّعة
 *     وبصمةً قصيرة، والقيمة الكاملة تُعرض مرّةً في المتصفّح — من الحقل الذي
 *     كتبها المستعمل — ولا تعود من الخادم أبدًا.
 *
 * ٢ — لا قيمة في سجلّ التدقيق. السجلّ يُقرأ بصلاحيةٍ أوسع ويُصدَّر ويُنسخ،
 *     فيُسجَّل **أنّ** المفتاح تغيّر وبصمته لا هو.
 *
 * ٣ — البيئة الحيّة تحتاج تأكيدًا صريحًا. لصقةٌ في الحقل الخطأ تُحوّل مال
 *     الزبائن إلى حسابٍ آخر، وهو عطلٌ لا يُكتشف إلّا عند التسوية.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly CredentialVault $vault,
    ) {}

    /** الكتالوج والمحفوظ — بلا سرٍّ واحد. */
    public function index(): JsonResponse
    {
        return response()->json([
            'providers' => IntegrationCatalogue::describe(),
            'environments' => IntegrationCatalogue::ENVIRONMENTS,
            'credentials' => $this->vault->listFor(),
            /*
             * يُقال صراحةً في الواجهة: القيمة لا تعود بعد الحفظ. ووعدٌ غير
             * مقولٍ يجعل المستعمل يحفظ ولا ينسخ، ثمّ يطلب ما لن يُعطى.
             */
            'policy' => [
                'secretsReturned' => false,
                'note' => 'السرّ يُعرض مرّةً واحدة لحظة الإدخال ولا يعود من الخادم. من نسيه يُدوّره.',
            ],
        ]);
    }

    /**
     * حفظ أو تدوير قيمة.
     *
     * الفعل نفسه للاثنين عمدًا: «أضف» و«دوّر» يكتبان الحقل نفسه، والتفريق
     * بينهما في الواجهة كان سيسمح بحالةٍ ثالثة — حقلٌ له قيمتان فعّالتان.
     */
    public function store(Request $request, AdminUser $actor): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(IntegrationCatalogue::PROVIDERS))],
            'environment' => ['required', Rule::in(IntegrationCatalogue::ENVIRONMENTS)],
            'field' => ['required', 'string', 'max:64'],
            'value' => ['required', 'string', 'max:4096'],
            'confirmLive' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if ($data['environment'] === 'live' && ($data['confirmLive'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'confirmLive' => ['الكتابة في البيئة الحيّة تحتاج تأكيدًا صريحًا.'],
            ]);
        }

        $before = collect($this->vault->listFor($data['provider']))
            ->firstWhere(fn (array $row) => $row['environment'] === $data['environment']
                && $row['field'] === $data['field']);

        $saved = $this->vault->put(
            $data['provider'],
            $data['environment'],
            $data['field'],
            $data['value'],
            $actor->id,
        );

        /*
         * ما يُسجَّل: البصمة والمعاينة والزمن. وما لا يُسجَّل: القيمة.
         * `$saved` و`$before` كلاهما من الخزنة، وهي لا تُخرج سرًّا أصلًا —
         * فالحاجز مبنيّ في المصدر لا معتمَدًا على انتباه هذا السطر.
         */
        $this->audit->log(
            $actor,
            $before === null ? 'integration.credential.created' : 'integration.credential.rotated',
            'integration_credential',
            null,
            $before,
            $saved,
            $data['reason'] ?? null,
            $request,
        );

        return response()->json(['data' => $saved], $before === null ? 201 : 200);
    }

    /** السحب — يُبطل ويمحو القيمة، ويُبقي الصفّ للتاريخ. */
    public function destroy(Request $request, AdminUser $actor): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(IntegrationCatalogue::PROVIDERS))],
            'environment' => ['required', Rule::in(IntegrationCatalogue::ENVIRONMENTS)],
            'field' => ['required', 'string', 'max:64'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $before = collect($this->vault->listFor($data['provider']))
            ->firstWhere(fn (array $row) => $row['environment'] === $data['environment']
                && $row['field'] === $data['field']);

        if ($before === null || $before['revokedAt'] !== null) {
            return response()->json(['message' => 'لا اعتماد فعّال بهذا الوصف.'], 404);
        }

        $this->vault->revoke($data['provider'], $data['environment'], $data['field']);

        $this->audit->log(
            $actor, 'integration.credential.revoked', 'integration_credential', null,
            $before, null, $data['reason'] ?? null, $request,
        );

        return response()->json(['revoked' => true]);
    }
}
