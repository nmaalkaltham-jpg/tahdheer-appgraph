<?php

namespace App\Domain\Insights\Aggregators;

use App\Domain\Insights\InsightAggregator;
use App\Domain\Insights\InsightWindow;
use App\Domain\Insights\Metric;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use Illuminate\Support\Facades\DB;

/**
 * الاستردادات — كم خرج، ولماذا، ومن يكرّره.
 *
 * ─── معدّل الاسترداد ومقامه ───
 *
 * «معدّل الاسترداد» له مقامان مشروعان: عدد الطلبات، أو مبلغ المبيعات.
 * وهما يختلفان كثيرًا حين تُستردّ طلباتٌ كبيرة. يُحسب الاثنان هنا ويُسمّى
 * كلٌّ منهما بمقامه، بدل رقمٍ واحد يُقرأ على وجهين.
 *
 * ─── وزمن المعالجة ───
 *
 * الفرق بين إنشاء صفّ الاسترداد وإشعار العميل (`notified_at`). وهو ليس زمن
 * وصول المال إلى بطاقته — ذاك عند المزوّد ولا أثر له عندنا. يُسمّى بما يقيسه
 * تمامًا: «حتّى إشعار العميل» لا «حتّى وصول المال».
 */
final readonly class RefundsInsight implements InsightAggregator
{
    private const PAID = OrderController::PAID_STATUSES;

    public function metrics(InsightWindow $window): array
    {
        /*
         * العمود مؤهَّل باسم جدوله: الاستعلام الجزئيّ يصل `orders` وفيه
         * `created_at` أيضًا، فاسمٌ عارٍ يصير مبهمًا وتسقط الاستجابة كلّها.
         */
        // المرفوض لم يُخرج مالًا: لا يدخل في «كم خرج».
        $inWindow = fn () => DB::table('refunds')
            ->where('refunds.status', '!=', 'FAILED')
            ->whereBetween('refunds.created_at', [$window->from, $window->to]);

        $row = $inWindow()
            ->selectRaw('count(*) as n, coalesce(sum(amount_minor), 0) as amount, count(distinct order_id) as orders')
            ->first();

        $count = (int) ($row->n ?? 0);
        $amount = (int) ($row->amount ?? 0);
        $orders = (int) ($row->orders ?? 0);

        $paidOrders = DB::table('orders')
            ->whereBetween('created_at', [$window->from, $window->to])
            ->whereIn('status', self::PAID)
            ->count();

        $paidAmount = (int) DB::table('orders')
            ->whereBetween('created_at', [$window->from, $window->to])
            ->whereIn('status', self::PAID)
            ->selectRaw('coalesce(sum(total_minor), 0) as v')
            ->value('v');

        $previousAmount = (int) DB::table('refunds')
            ->where('status', '!=', 'FAILED')
            ->whereBetween('created_at', [$window->previousFrom, $window->previousTo])
            ->selectRaw('coalesce(sum(amount_minor), 0) as v')
            ->value('v');

        /*
         * الجزئيّ: استردادٌ أقلّ من إجماليّ طلبه. يُميَّز لأنّ علاجه مختلف —
         * الكامل غالبًا إلغاءُ بيع، والجزئيّ شكوى عن جزءٍ من المحتوى.
         */
        $partial = $inWindow()
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->whereColumn('refunds.amount_minor', '<', 'orders.total_minor')
            ->count();

        $latencies = $inWindow()
            ->whereNotNull('notified_at')
            ->selectRaw('extract(epoch from (notified_at - created_at)) as secs')
            ->pluck('secs')
            ->map(fn ($v) => (int) $v)
            ->sort()
            ->values();

        return [
            Metric::measured('refundCount', 'عدد الاستردادات', 'count', $count, 'صفوف استردادٍ أُنشئت في المدى.', href: '/payments?view=refunds'),
            Metric::measured('refundAmount', 'مبلغ الاستردادات', 'money', $amount, 'مجموع مبالغ الاستردادات في المدى.', previous: $previousAmount, href: '/payments?view=refunds'),
            Metric::measured('refundedOrders', 'طلبات استُردّ منها', 'count', $orders, 'طلباتٌ فريدة لها استردادٌ واحدٌ على الأقلّ في المدى.', href: '/orders?view=refunded&'.$window->queryString()),
            Metric::measured('partialRefunds', 'استردادات جزئيّة', 'count', $partial, 'استردادٌ مبلغه أقلّ من إجماليّ طلبه.'),
            Metric::ratio(
                'refundRateByOrders',
                'معدّل الاسترداد بالطلبات',
                $orders,
                $paidOrders,
                'طلباتٌ استُردّ منها ÷ الطلبات المدفوعة في المدى.',
                'لا طلب مدفوع في المدى — لا مقام لمعدّل الاسترداد.',
                alert: true,
            ),
            Metric::ratio(
                'refundRateByValue',
                'معدّل الاسترداد بالمبلغ',
                $amount,
                $paidAmount,
                'مبلغ الاستردادات ÷ مبلغ المبيعات المدفوعة في المدى.',
                'لا مبيعات مدفوعة في المدى — لا مقام لمعدّل الاسترداد بالمبلغ.',
                alert: true,
            ),
            $latencies->isEmpty()
                ? Metric::noData(
                    'notifyLatencyP50',
                    'وسيط زمن إشعار العميل',
                    'لا استرداد أُشعر صاحبه في المدى — لا زمن يُقاس.',
                )
                : Metric::measured(
                    'notifyLatencyP50',
                    'وسيط زمن إشعار العميل',
                    'duration',
                    (int) $latencies[intdiv($latencies->count() - 1, 2)],
                    'ثوانٍ بين إنشاء صفّ الاسترداد وإشعار العميل. ليس زمن وصول المال إلى بطاقته — ذاك عند المزوّد ولا أثر له عندنا.',
                ),
        ];
    }

    public function lists(InsightWindow $window): array
    {
        $reasons = DB::table('refunds')
            ->whereBetween('created_at', [$window->from, $window->to])
            ->selectRaw("coalesce(nullif(trim(reason), ''), '(بلا سبب مكتوب)') as reason, count(*) as n, sum(amount_minor) as amount")
            ->groupBy('reason')
            ->orderByDesc('n')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['reason' => (string) $r->reason, 'count' => (int) $r->n, 'amountMinor' => (int) $r->amount])
            ->all();

        /*
         * أكثر المنتجات استردادًا: يُنسب الاسترداد إلى أصناف طلبه كلّها —
         * جدول الاستردادات على الطلب لا على الصنف، فلا سبيل إلى نسبته إلى
         * صنفٍ بعينه. القيد يُقال في الملاحظة ولا يُخفى خلف رقم.
         */
        $products = DB::table('refunds')
            ->join('order_items', 'order_items.order_id', '=', 'refunds.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereBetween('refunds.created_at', [$window->from, $window->to])
            ->selectRaw('products.id, products.title, count(distinct refunds.id) as refunds')
            ->groupBy('products.id', 'products.title')
            ->orderByDesc('refunds')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'productId' => (int) $r->id,
                'title' => (string) $r->title,
                'refunds' => (int) $r->refunds,
                'href' => '/studio/catalog/'.(int) $r->id,
            ])
            ->all();

        $repeat = DB::table('refunds')
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->whereNotNull('orders.customer_id')
            ->selectRaw('orders.customer_id, orders.email, count(*) as refunds, sum(refunds.amount_minor) as amount')
            ->groupBy('orders.customer_id', 'orders.email')
            ->havingRaw('count(*) > 1')
            ->orderByDesc('refunds')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'customerId' => (int) $r->customer_id,
                'email' => (string) $r->email,
                'refunds' => (int) $r->refunds,
                'amountMinor' => (int) $r->amount,
                'href' => '/customers/'.(int) $r->customer_id,
            ])
            ->all();

        return [
            'reasons' => ['label' => 'أسباب الاسترداد', 'note' => 'كما كتبها المشرف عند التنفيذ.', 'rows' => $reasons],
            'topProducts' => [
                'label' => 'منتجات في طلباتٍ مستردّة',
                'note' => 'الاسترداد على الطلب لا على الصنف، فكلّ أصناف الطلب المستردّ تُنسب إليه.',
                'rows' => $products,
            ],
            'repeatRefunders' => [
                'label' => 'عملاء تكرّر استردادهم',
                'note' => 'استردادان فأكثر — بلا قيد مدًى.',
                'rows' => $repeat,
            ],
        ];
    }
}
