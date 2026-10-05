<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\EventIngest;
use App\Domain\Analytics\VisitorIdentity;
use App\Domain\Privacy\PrivacyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * قرار الزائر في القياس — النقطة التي يُولد فيها المعرّف ويُقتل.
 *
 * ─── لماذا يوجد هذا المتحكّم أصلًا ───
 *
 * `EventIngest` كان يقرأ `consent_records` ويحترمها، ولا كاتبَ في المشروع
 * كلّه يكتب فيها سطرًا لزائرٍ بلا حساب. فالنتيجة المقيسة: كلّ حدثٍ يُخزَّن
 * بلا معرّف، إلى الأبد، ولا سبيل لزائرٍ أن يوافق حتّى لو أراد. قارئٌ بلا
 * كاتبٍ ليس نظام موافقة بل حقلٌ مطفأ.
 *
 * ─── وثلاثة قرارات هي صحّةٌ لا تفضيل ───
 *
 *  · **المنح والكتابة في طلبٍ واحد.** المعرّف يُولد هنا، وسطرُ السجلّ يُكتب
 *    هنا، ومرجعُ السطر هو بصمة المعرّف نفسها. فلا كعكةٌ بلا سطرٍ يبرّرها،
 *    ولا سطرٌ يشير إلى معرّفٍ لا يملكه أحد.
 *
 *  · **إشارة الرفض تعلو على الموافقة.** «ولا يوجد إعداد في الموقع يستطيع
 *    تجاوز ذلك» — والشريط إعدادٌ في الموقع. فمن يرسل `Sec-GPC` لا يُمنح
 *    معرّفًا ولو نقر «أوافق»؛ ويُردّ عليه بالحالة كما هي: مقاسٌ متوقّف.
 *
 *  · **السحب يمحو ثمّ يكتب.** «سحب الموافقة يحذف كوكيز القياس فورًا» —
 *    فتُمحى الكعكتان في الردّ نفسه، ويُقيَّد السحب في السجلّ قبل أن يُفقد
 *    المرجع الذي يُقيَّد عليه.
 */
class ConsentController extends Controller
{
    public function __construct(
        private readonly VisitorIdentity $identity,
        private readonly EventIngest $ingest,
        private readonly PrivacyService $privacy,
    ) {}

    /**
     * ما يُعرض في الشريط، وأين يقف الزائر الآن.
     *
     * النصّ يأتي من هنا ليُعرض ويُسجَّل من مصدرٍ واحد (انظر
     * `config/analytics.php`).
     */
    public function show(Request $request): JsonResponse
    {
        $consent = (array) config('analytics.consent');
        $refused = $this->ingest->refusesTracking($request);

        return response()->json([
            'data' => [
                'policyVersion' => $consent['policy_version'],
                'title' => $consent['title'],
                'wording' => $consent['wording'],
                /*
                 * `null` = لم يُسأل بعد. وهو مختلفٌ عن `false`: الأوّل يعرض
                 * الشريط والثاني يصمت. وصفرٌ في مكان الغياب يجعل من رفضٍ
                 * صريح سؤالًا يتكرّر كلّ زيارة.
                 */
                'granted' => $refused ? false : $this->granted($request),
                /*
                 * إشارة المتصفّح تُعلَن للزائر: شريطٌ يختفي بلا سببٍ ظاهر
                 * يبدو عطلًا، وإعلانها يجعل الاحترام مرئيًّا لا مزعومًا.
                 */
                'refusedBySignal' => $refused,
            ],
        ]);
    }

    /** منحٌ أو سحب. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['granted' => 'required|boolean']);
        $granted = (bool) $data['granted'];

        if ($this->ingest->refusesTracking($request)) {
            /*
             * لا سطر ولا كعكة — ولا حتّى كعكة القرار: كتابتُها تجعل الرفض
             * قرارًا اتّخذه الزائر في الموقع، وهو في الحقيقة إشارةٌ من
             * متصفّحه قد تختفي غدًا فيُسأل من جديد كما يجب.
             */
            return $this->state(false, true);
        }

        $consent = (array) config('analytics.consent');
        $visitorId = $this->identity->read($request, VisitorIdentity::VISITOR_COOKIE);

        if (! $granted) {
            /*
             * السحب يُقيَّد فقط حين يوجد ما يُسحب: زائرٌ بلا معرّف لم يوافق
             * قطّ، وسطرُ «سحب» له يُنشئ مرجعًا لم يوجد — ضجيجٌ في دفتر
             * الامتثال لا دليل.
             */
            if ($visitorId !== null) {
                $this->record($request, $consent, $this->ingest->hash($visitorId), false);
            }

            return $this->state(false, false)
                ->withCookie($this->identity->decision(false))
                ->withCookie($this->identity->forget(VisitorIdentity::VISITOR_COOKIE))
                ->withCookie($this->identity->forget(VisitorIdentity::SESSION_COOKIE));
        }

        // موافقةٌ ثانية بالمعرّف نفسه: يُجدَّد السطر ولا يُولد معرّفٌ ثانٍ
        // فينقطع عدّ الزائر عن ماضيه بلا سبب.
        $visitorId ??= $this->identity->mint();
        $this->record($request, $consent, $this->ingest->hash($visitorId), true);

        return $this->state(true, false)
            ->withCookie($this->identity->decision(true))
            ->withCookie($this->identity->visitor($visitorId))
            ->withCookie($this->identity->session($this->identity->mint()));
    }

    /** @param array<string, mixed> $consent */
    private function record(Request $request, array $consent, string $subjectRef, bool $granted): void
    {
        $this->privacy->recordConsent(
            /*
             * بلا زبون — عمدًا. المتجر لا يملك حارس مصادقةٍ للزائر
             * (`config/auth.php` فيه حارس الإدارة وحده)، والمرجع الصحيح هنا
             * هو `subject_ref` وهو ما وُضع له حرفيًّا في الهجرة: «مرجعٌ لمن
             * لا حساب له بعد». وربطُ السطر بزبونٍ مُخمَّن أسوأ من تركه بلا
             * ربط.
             */
            customer: null,
            purpose: (string) $consent['purpose'],
            granted: $granted,
            policyVersion: (string) $consent['policy_version'],
            source: (string) $consent['source'],
            wording: (string) $consent['wording'],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            subjectRef: $subjectRef,
        );
    }

    private function granted(Request $request): ?bool
    {
        $visitorId = $this->identity->read($request, VisitorIdentity::VISITOR_COOKIE);

        if ($visitorId !== null) {
            return $this->ingest->hasAnalyticsConsent($this->ingest->hash($visitorId));
        }

        // لا معرّف: القرار المُعلَن في كعكة القرار هو كلّ ما يُعرف — ورفضٌ
        // صريحٌ سابق يُحترم فلا يُعاد السؤال.
        return $this->identity->decided($request);
    }

    private function state(bool $granted, bool $refusedBySignal): JsonResponse
    {
        return response()->json(['data' => [
            'granted' => $granted,
            'refusedBySignal' => $refusedBySignal,
        ]]);
    }
}
