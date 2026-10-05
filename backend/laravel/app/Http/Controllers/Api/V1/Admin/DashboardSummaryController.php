<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * مؤشّرات لوحة التشغيل على مدًى زمنيّ — ومقارنتها بالمدى السابق مباشرةً.
 *
 * ─── لماذا المقارنة بمدًى مساوٍ لا بـ«الشهر الماضي» ───
 *
 * «آخر ٧ أيّام» تُقارن بالسبعة التي سبقتها بالضبط، لا بأسبوع التقويم. وإلّا
 * لَقُورنت ستّة أيّام بسبعة فظهر هبوطٌ لم يقع. الطول يُحسب من المدى نفسه
 * ويُطرح كما هو.
 *
 * ─── لماذا الحقيقة من الطلبات لا من أحداث المتصفّح ───
 *
 * المال يُقرأ من صفوف الطلبات وحدها (§٩٫١). حدثُ متصفّحٍ يُحجب بمانع إعلانات
 * أو يُكرَّر بإعادة تحميل، وبناءُ رقم إيرادٍ عليه يجعل الدفتر تخمينًا.
 *
 * ─── وما لا يُقاس يُعلَن ───
 *
 * معدّل التحويل والزوّار بلا مقام: لا جدول أحداث زيارة. يُرَدّان
 * `NOT_CONFIGURED` بسببهما ولا يُرَدّان صفرًا ولا نسبةً مخترعة.
 */
class DashboardSummaryController extends Controller
{
    /** المال محصَّلٌ فعلًا في هذه الحالات وحدها — مرآة `OrderController`. */
    /*
     * المبيعات = ما وصل مالُه وبقي منه شيء: المحصَّل والمستردّ جزئيًّا. كان
     * الأخير خارج القائمة فيُصفّر الردُّ الجزئيّ الطلبَ كلَّه بدل أن يُطرح
     * منه — والمستردّ كاملًا يبقى خارجها لأنّ ماله كلَّه خرج.
     */
    private const PAID = [...OrderController::PAID_STATUSES, 'PARTIALLY_REFUNDED'];

    /** طلبات تنتظر فعلًا بشريًّا. */
    private const NEEDS_ACTION = ['PENDING', 'AWAITING_PAYMENT', 'PROCESSING'];

    /** أطول مدًى مقبول — سنتان. أوسع منه مسحُ جدولٍ لا لوحة تشغيل. */
    private const MAX_DAYS = 730;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'sometimes|date',
            'to' => 'sometimes|date',
        ]);

        [$from, $to] = $this->range($data);

        /*
         * الطول يُحسب على **التواريخ** لا على اللحظات.
         *
         * `$to` نهاية اليوم (٢٣:٥٩:٥٩)، والفرق بينه وبين بداية `$from` كسرٌ
         * لا عدد صحيح: ٦٫٩٩٩ لا ٧. وتمرير الكسر إلى `subDays` كان يُزيح
         * المدى السابق بثوانٍ فيبتلع طلبًا من حافّة اليوم أو يُسقطه — خطأٌ
         * لا يُرى في الرقم الكبير ويُرى في المقارنة.
         */
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;
        $previousTo = $from->subDay()->endOfDay();
        $previousFrom = $previousTo->subDays($days - 1)->startOfDay();

        $current = $this->aggregate($from, $to);
        $previous = $this->aggregate($previousFrom, $previousTo);

        $range = [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'days' => $days,
            'previousFrom' => $previousFrom->toIso8601String(),
            'previousTo' => $previousTo->toIso8601String(),
        ];

        return response()->json([
            'generatedAt' => now()->toIso8601String(),
            'range' => $range,
            'currency' => 'SAR',
            'kpis' => $this->kpis($current, $previous, [$from->toDateString(), $to->toDateString()]),
            'series' => $this->series($from, $to),
            'ordersByStatus' => $this->ordersByStatus($from, $to),
            'topProducts' => $this->topProducts($from, $to),
            'recentOrders' => $this->recentOrders(),
            'notConfigured' => [
                [
                    'key' => 'visitors',
                    'label' => 'الزوّار',
                    'state' => 'NOT_CONFIGURED',
                    'reason' => 'لا جدول أحداث زيارة — لا مصدر لعدد الزوّار.',
                ],
                [
                    'key' => 'conversionRate',
                    'label' => 'معدّل التحويل',
                    'state' => 'NOT_CONFIGURED',
                    'reason' => 'يحتاج عدد الزيارات مقامًا، وهو غير مُقاس؛ نسبةٌ بلا مقام رقمٌ مختلَق.',
                ],
                [
                    'key' => 'acquisitionChannels',
                    'label' => 'قنوات الاكتساب',
                    'state' => 'NOT_CONFIGURED',
                    'reason' => 'لا مصدر إحالةٍ محفوظ مع الطلب — القناة غير معروفة.',
                ],
            ],
        ]);
    }

    /**
     * المدى المطلوب، أو آخر ثلاثين يومًا.
     *
     * الحدّان يُقلبان إن وردا معكوسَين بدل أن يُرَدّ خطأ: المستعمل اختار
     * تاريخين، والترتيب تفصيلُ إدخالٍ لا نيّةٌ مختلفة.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(array $data): array
    {
        $to = isset($data['to'])
            ? CarbonImmutable::parse((string) $data['to'])->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        $from = isset($data['from'])
            ? CarbonImmutable::parse((string) $data['from'])->startOfDay()
            : $to->subDays(29)->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        if ($from->startOfDay()->diffInDays($to->startOfDay()) > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS)->startOfDay();
        }

        return [$from, $to];
    }

    /**
     * تجميعةٌ واحدة لمدًى واحد.
     *
     * @return array<string, int>
     */
    private function aggregate(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $paid = DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('status', self::PAID);

        $row = (clone $paid)
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(total_minor), 0) as gross')
            ->selectRaw('coalesce(sum(vat_minor), 0) as vat')
            ->selectRaw('coalesce(sum(refunded_total_minor), 0) as refunded')
            ->first();

        $orders = (int) ($row->orders ?? 0);
        $gross = (int) ($row->gross ?? 0);

        return [
            'orders' => $orders,
            'grossMinor' => $gross,
            // الصافي = الإجماليّ ناقص الضريبة ناقص المستردّ. الصيغة تُعلَن للمشغّل.
            'netMinor' => $gross - (int) ($row->vat ?? 0) - (int) ($row->refunded ?? 0),
            'refundedMinor' => (int) ($row->refunded ?? 0),
            // متوسّطٌ بلا مقام لا يُحسب — صفرُ طلباتٍ يعني متوسّطًا غير معرَّف.
            'aovMinor' => $orders === 0 ? 0 : intdiv($gross, $orders),
            'allOrders' => DB::table('orders')->whereBetween('created_at', [$from, $to])->count(),
            'needsAction' => DB::table('orders')
                ->whereBetween('created_at', [$from, $to])
                ->whereIn('status', self::NEEDS_ACTION)
                ->count(),
            'newCustomers' => DB::table('customers')->whereBetween('created_at', [$from, $to])->count(),
        ];
    }

    /**
     * كلّ مؤشّرٍ بقيمته وقيمة المدى السابق والصيغة التي حُسب بها.
     *
     * النسبة تُترك للواجهة؟ لا — تُحسب هنا مرّة. حسابُها في مكانين يجعل
     * تقريرًا مُصدَّرًا يخالف ما على الشاشة.
     *
     * @param  array<string, int>  $now
     * @param  array<string, int>  $was
     * @return list<array<string, mixed>>
     */
    private function kpis(array $now, array $was, array $window): array
    {
        /*
         * الوجهة جزءٌ من تعريف المؤشّر لا زينةٌ فوقه.
         *
         * كلّ وجهةٍ هنا تعيد إنتاج المجموعة التي حُسب منها الرقم بالضبط:
         * العدسة نفسها والمدى نفسه، فيعدّ المشغّل الصفوف فيجدها الرقم. ولذلك
         * يُحمل المدى في الرابط — رابطٌ بلا مدًى يهبط على كلّ الطلبات منذ
         * الإطلاق ويجعل البطاقة تكذب.
         *
         * و«عملاء جدد» بلا وجهة: قائمة العملاء لا تقرأ مدًى من الرابط بعد،
         * فرابطٌ إليها كان سيعرض تسعةً وستّين حيث تقول البطاقة اثنين. الغياب
         * أصدق من وجهةٍ لا تطابق.
         */
        [$fromDate, $toDate] = $window;
        $window = '&from='.$fromDate.'&to='.$toDate;

        $defs = [
            ['grossSales', 'إجمالي المبيعات', 'money', 'grossMinor', 'مجموع إجماليّ الطلبات المدفوعة في المدى.', '/orders?view=paid'.$window],
            ['netSales', 'صافي المبيعات', 'money', 'netMinor', 'الإجماليّ ناقص ضريبة القيمة المضافة ناقص المستردّ.', '/orders?view=paid'.$window],
            ['orders', 'الطلبات المدفوعة', 'count', 'orders', 'عدد الطلبات التي وصل مالها في المدى.', '/orders?view=paid'.$window],
            ['allOrders', 'كلّ الطلبات', 'count', 'allOrders', 'كلّ الطلبات المُنشأة في المدى مهما كانت حالتها.', '/orders?'.ltrim($window, '&')],
            ['aov', 'متوسّط قيمة الطلب', 'money', 'aovMinor', 'إجماليّ المبيعات ÷ عدد الطلبات المدفوعة.', '/orders?view=paid'.$window],
            ['needsAction', 'طلبات تحتاج معالجة', 'count', 'needsAction', 'المُنشأة في المدى وحالتها: بانتظار الدفع أو قيد المعالجة.', '/orders?view=needsAction'.$window],
            ['newCustomers', 'عملاء جدد', 'count', 'newCustomers', 'حسابات عملاء أُنشئت في المدى.', null],
            ['refunded', 'المستردّ', 'money', 'refundedMinor', 'مجموع ما استُردّ من طلبات المدى.', '/orders?view=refunded'.$window],
        ];

        return array_map(function (array $def) use ($now, $was): array {
            [$key, $label, $format, $field, $formula, $href] = $def;
            $value = $now[$field];
            $before = $was[$field];

            return [
                'key' => $key,
                'label' => $label,
                'format' => $format,
                'value' => $value,
                'previous' => $before,
                /*
                 * لا نسبةَ من صفر: القسمة على المقام الصفريّ غير معرَّفة،
                 * و«+∞٪» أو «+١٠٠٪» كلاهما اختراع. `null` تُعرض «لا مقارنة».
                 */
                'changePercent' => $before === 0 ? null : round((($value - $before) / $before) * 100, 1),
                'formula' => $formula,
                'href' => $href,
            ];
        }, $defs);
    }

    /**
     * سلسلةٌ يوميّة — صفٌّ لكلّ يومٍ في المدى، والأيّام الخالية أصفارٌ **مقيسة**.
     *
     * التعبئة بالأصفار هنا صادقة: الجدول استُعلم والمدى معلوم، فاليوم الخالي
     * يومٌ بلا طلبات. وهي ضرورية للمخطّط: سلسلةٌ بفجوات ترسم خطًّا يقفز فوق
     * أيّامٍ لم تحدث فيها مبيعات فيبدو الأسبوع أقصر ممّا هو.
     *
     * @return list<array<string, mixed>>
     */
    private function series(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::PAID), '?'));

        $rows = DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as day")
            ->selectRaw('count(*) as all_orders')
            ->selectRaw("count(*) filter (where status in ({$placeholders})) as paid_orders", self::PAID)
            ->selectRaw("coalesce(sum(total_minor) filter (where status in ({$placeholders})), 0) as gross", self::PAID)
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $out = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $hit = $rows[$key] ?? null;
            $out[] = [
                'date' => $key,
                'orders' => (int) ($hit->all_orders ?? 0),
                'paidOrders' => (int) ($hit->paid_orders ?? 0),
                'grossMinor' => (int) ($hit->gross ?? 0),
            ];
        }

        return $out;
    }

    /** @return array<string, int> */
    private function ordersByStatus(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * أكثر المنتجات مبيعًا — من بنود الطلبات المدفوعة وحدها.
     *
     * ─── لماذا الترتيب بعدد الطلبات لا بالإيراد ───
     *
     * `order_items.quantity` فارغٌ في صفوفٍ قائمة. وضربُ السعر في «١»
     * افتراضًا اختراعُ كمّيّة، ورَدُّ صفرٍ يجعل المنتج يبدو غير مبيعٍ وهو
     * مبيع. فالمقياس الذي يُقاس دائمًا هو **عدد الطلبات المدفوعة التي ضمّته**،
     * وهو ما يُرتَّب به. والكمّيّة والإيراد يُرَدّان `null` حين لا يُعرفان —
     * فتقول الواجهة «غير مُقاس» بدل أن تعرض رقمًا كاذبًا.
     *
     * العنوان من اللقطة (`title_snapshot`) لا من جدول المنتجات: المنتج قد
     * يُعاد تسميته أو يُحذف، والتقرير يقول ما بيع وقت البيع.
     *
     * @return list<array<string, mixed>>
     */
    private function topProducts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->whereIn('orders.status', self::PAID)
            ->selectRaw('order_items.product_id')
            ->selectRaw('max(order_items.title_snapshot) as title')
            ->selectRaw('count(distinct order_items.order_id) as order_count')
            ->selectRaw('sum(order_items.quantity) as units')
            ->selectRaw('sum(order_items.price_snapshot_minor * order_items.quantity) as revenue')
            ->groupBy('order_items.product_id')
            ->orderByDesc('order_count')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'productId' => $row->product_id === null ? null : (int) $row->product_id,
                'title' => (string) $row->title,
                'orders' => (int) $row->order_count,
                'units' => $row->units === null ? null : (int) $row->units,
                'revenueMinor' => $row->revenue === null ? null : (int) $row->revenue,
            ])
            ->all();
    }

    /**
     * آخر الطلبات — بلا قيد مدًى عمدًا.
     *
     * اللوحة تسأل «ماذا يجري الآن؟»، ومدًى يعود شهرًا للوراء كان سيُخفي
     * طلبًا وصل قبل دقيقة لأنّ المشغّل يتصفّح تقرير الشهر الماضي.
     *
     * @return list<array<string, mixed>>
     */
    private function recentOrders(): array
    {
        return DB::table('orders')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'number', 'email', 'status', 'total_minor', 'created_at'])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->number,
                'email' => (string) $row->email,
                'status' => (string) $row->status,
                'totalMinor' => (int) $row->total_minor,
                'createdAt' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
            ])
            ->all();
    }
}
