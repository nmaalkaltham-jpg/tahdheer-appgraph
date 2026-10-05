<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\EventIngest;
use App\Domain\Analytics\VisitorIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * استقبال أحداث القياس — نقطةٌ عامّة عالية الحجم.
 *
 * ─── لماذا `throttle:telemetry` ───
 *
 * ثلاثون في الدقيقة، وهو المُحدِّد القائم لتقارير الروابط المكسورة: حجمٌ
 * مشابه وأثرٌ مشابه. والدفعة تحمل حتّى عشرين حدثًا، فالسقف الفعليّ ستّمئة
 * حدثٍ في الدقيقة للمصدر الواحد — أكثر من أيّ تصفّحٍ بشريّ وأقلّ من أن
 * يُغرق الجدول.
 *
 * ─── ولماذا ٢٠٤ دائمًا ───
 *
 * القياس لا يُبلّغ المتصفّح بشيء: جسمُ ردٍّ يُغري بمنطقٍ في العميل يعتمد على
 * نجاح القياس، فيصير المتجر يعتمد على ما لا يجوز أن يعتمد عليه. و`sendBeacon`
 * لا يقرأ الرد أصلًا.
 *
 * وحتّى الرفض بإشارة «لا تتبّعني» يردّ ٢٠٤: رمزٌ مميّز يجعل الرفض قابلًا
 * للكشف من الشبكة، وهو بذاته إشارةٌ تُبنى عليها بصمة.
 */
class EventController extends Controller
{
    public function __construct(private readonly VisitorIdentity $identity) {}

    public function store(Request $request, EventIngest $ingest): JsonResponse
    {
        $data = $request->validate([
            'events' => 'required|array|min:1|max:'.EventIngest::MAX_BATCH,
            'events.*.type' => 'required|string|max:40',
            'events.*.path' => 'sometimes|nullable|string|max:2000',
            'events.*.referrer' => 'sometimes|nullable|string|max:2000',
            'events.*.productId' => 'sometimes|nullable|integer',
            'events.*.valueMinor' => 'sometimes|nullable|integer',
            'events.*.isLoggedIn' => 'sometimes|boolean',
            'events.*.id' => 'sometimes|nullable|string|max:80',
        ]);

        /*
         * المعرّفان من الكعكتين لا من جسم الطلب: قيمةٌ في الجسم يختارها
         * العميل تجعل انتحال زائرٍ آخر سطرًا واحدًا. والكعكتان يضبطهما
         * الخادم عند الموافقة وحدها.
         */
        $visitorId = $this->identity->read($request, VisitorIdentity::VISITOR_COOKIE);

        /*
         * الجلسة تُولد هنا حين تغيب — لا في الموافقة وحدها.
         *
         * مدّتها ثلاثون دقيقة كما تقول السياسة المنشورة، فهي تنتهي بين
         * زيارةٍ وأخرى **بالتصميم**. ولو لم تُجدَّد إلّا عند الموافقة لصار
         * لكلّ زائرٍ موافقٍ جلسةٌ واحدةٌ في السنة ثمّ لا شيء — وعدّ الجلسات
         * صفرًا كاذبًا لا قياسًا.
         *
         * والمولَّد هنا يُهمَل بلا أثر إن لم تكن هناك موافقة: `record` لا
         * تبصم الجلسة إلّا مع معرّف زائرٍ موافق، والكعكة لا تُكتب إلّا في
         * تلك الحال بالذات.
         */
        $sessionId = $this->identity->read($request, VisitorIdentity::SESSION_COOKIE)
            ?? $this->identity->mint();

        $result = $ingest->record($request, $data['events'], $visitorId, $sessionId);

        $response = response()->json(null, 204);

        /*
         * تجديدٌ منزلق: كلّ دفعةٍ تدفع انتهاء الجلسة ثلاثين دقيقة إلى الأمام،
         * فتنتهي الجلسة بثلاثين دقيقة من **الخمول** لا من أوّل صفحة — وهو
         * معنى «جلسة» في كلّ تقريرٍ يقرؤه بشر.
         */
        return $result['identified']
            ? $response->withCookie($this->identity->session($sessionId))
            : $response;
    }
}
