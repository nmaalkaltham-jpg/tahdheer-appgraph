<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Insights\InsightsRegistry;
use App\Domain\Insights\InsightWindow;
use App\Domain\Insights\Metric;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * طبقة التجميع الموحّدة — نطاقٌ واحدٌ في كلّ نداء، وعقدٌ واحدٌ لكلّها.
 *
 * ─── لماذا نقطةٌ واحدة بنطاقاتٍ لا نقطةٌ لكلّ نطاق ───
 *
 * تسع نقاطٍ تعني تسع صيغ استجابةٍ تتباعد، وتسع نسخٍ من حلّ المدى، وتسع
 * فرصٍ لكسر قاعدة «ما لا يُقاس يُعلَن». العقد هنا واحد: `metrics` و`lists`
 * و`notConfigured`، والواجهة تكتب مُصيِّرًا واحدًا يخدم النطاقات كلّها.
 *
 * ─── ولماذا نطاقٌ واحدٌ في كلّ نداء ───
 *
 * اللوحة تُحمّل ودجاتها كسولًا: ما لم يُفتح لا يُستعلَم. وردُّ النطاقات
 * التسعة في نداءٍ واحد كان سيجعل أوّل تحميلٍ للصفحة ينتظر أبطأ تجميعٍ فيها،
 * وهو ما تمنعه قاعدة «لا استعلام ثقيل يحجب».
 *
 * ─── والصلاحية ───
 *
 * `analytics.read` كما `‎/admin/dashboard‎` تمامًا: هذه قراءةُ أرقامٍ مجمَّعة
 * لا فتحُ صفوفٍ بعينها. وكلّ رابط تحقيقٍ يخرج من هنا يهبط على شاشةٍ لها
 * حارسها الخاصّ، فمن لا يملك `orders.read` يرى الرقم ولا يفتح صفوفه.
 */
class InsightsController extends Controller
{
    public function __construct(private readonly InsightsRegistry $registry) {}

    /** فهرس النطاقات — ما الذي يمكن سؤاله أصلًا. */
    public function index(): JsonResponse
    {
        return response()->json([
            'generatedAt' => now()->toIso8601String(),
            'domains' => $this->registry->catalogue(),
        ]);
    }

    public function show(Request $request, string $domain): JsonResponse
    {
        /*
         * الاسم المجهول يُرفض ٤٢٢ لا ٤٠٤: هذا خطأُ وسيطٍ في نداءٍ صحيح، لا
         * موردٌ غائب. والرسالة تعدّد المتاح فلا يخمّن العميل.
         */
        if (! $this->registry->has($domain)) {
            throw ValidationException::withMessages([
                'domain' => 'نطاقٌ غير معروف. المتاح: '.implode('، ', $this->registry->names()).'.',
            ]);
        }

        $data = $request->validate([
            'from' => 'sometimes|date',
            'to' => 'sometimes|date',
        ]);

        $window = InsightWindow::fromRequest($data);
        $aggregator = $this->registry->make($domain);
        $metrics = $aggregator->metrics($window);

        /*
         * قائمة الغائب تُشتقّ من المؤشّرات ولا تُكتب مرّتين: مُجمِّعٌ يُعلن
         * مؤشّرًا غير مهيّأ وينسى إضافته إلى القائمة كان سيُخفيه عن اللوحة
         * التي تعرض «ما ليس مُقاسًا».
         */
        $notConfigured = array_values(array_map(
            fn (Metric $m) => $m->toArray(),
            array_filter($metrics, fn (Metric $m) => $m->state !== 'MEASURED'),
        ));

        return response()->json([
            'generatedAt' => now()->toIso8601String(),
            'domain' => ['key' => $domain] + $this->registry->describe($domain),
            'range' => $window->toArray(),
            'currency' => 'SAR',
            'metrics' => array_map(fn (Metric $m) => $m->toArray(), $metrics),
            'lists' => $aggregator->lists($window),
            'notConfigured' => $notConfigured,
        ]);
    }
}
