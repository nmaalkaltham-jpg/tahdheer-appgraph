<?php

namespace App\Domain\Insights\Aggregators;

use App\Domain\Insights\InsightAggregator;
use App\Domain\Insights\InsightWindow;
use App\Domain\Insights\Metric;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ذكاء العملاء — من يشتري، ومن يكرّر، ومن على وشك أن يذهب.
 *
 * ─── لماذا المقام «الدافعون» لا «المسجّلون» ───
 *
 * معدّل تكرار الشراء على كلّ من سجّل حسابًا رقمٌ بلا معنى: من لم يشترِ قطّ
 * لا يمكن أن «يكرّر». المقام هنا من اشترى مرّةً على الأقلّ، والعددان
 * يُعلَنان كلاهما فلا يُقرأ أحدهما مكان الآخر.
 *
 * ─── وضيوف الدفع يُعدّون ولا يُقسَّمون ───
 *
 * طلبٌ بلا `customer_id` مالٌ حقيقيّ وصل، فيدخل الإيراد. لكنّه لا يدخل
 * الشرائح: لا هويّة تُتبع عبر الطلبات، فلا «تكرار» ولا «خمول» له. عدّه في
 * الشرائح كان سيجعل كلّ ضيفٍ عميلًا جديدًا في كلّ طلب.
 */
final readonly class CustomersInsight implements InsightAggregator
{
    private const PAID = OrderController::PAID_STATUSES;

    public function metrics(InsightWindow $window): array
    {
        $newInWindow = DB::table('customers')
            ->whereBetween('created_at', [$window->from, $window->to])
            ->count();

        $previousNew = DB::table('customers')
            ->whereBetween('created_at', [$window->previousFrom, $window->previousTo])
            ->count();

        /*
         * إنفاقُ كلّ عميلٍ وعدد طلباته وآخر شراء — مرّةً واحدة.
         *
         * الشرائح كلّها تُشتقّ من هذه اللقطة لا من استعلامٍ لكلّ شريحة: ستّة
         * استعلاماتٍ على الجدول نفسه تُنتج ستّ لحظاتٍ قد تتناقض، ومجموعُ
         * الشرائح قد يخالف الكلّ.
         */
        $paying = DB::table('orders')
            ->whereIn('status', self::PAID)
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, count(*) as orders, sum(total_minor) as spend, max(created_at) as last_order')
            ->groupBy('customer_id')
            ->get();

        $payingCount = $paying->count();
        $repeat = $paying->where('orders', '>', 1)->count();

        $metrics = [
            Metric::measured(
                'newCustomers',
                'عملاء جدد',
                'count',
                $newInWindow,
                'حسابات أُنشئت داخل المدى — تسجيلًا لا شراءً.',
                previous: $previousNew,
            ),
            Metric::measured(
                'payingCustomers',
                'عملاء اشتروا مرّةً على الأقلّ',
                'count',
                $payingCount,
                'عملاءُ لهم طلبٌ واحدٌ وصل ماله، بلا قيد مدًى.',
            ),
            Metric::measured(
                'guestOrders',
                'طلبات ضيوف',
                'count',
                DB::table('orders')->whereIn('status', self::PAID)->whereNull('customer_id')->count(),
                'طلباتٌ مدفوعة بلا حساب — تدخل الإيراد ولا تدخل الشرائح.',
                href: '/orders?view=paid',
            ),
            Metric::ratio(
                'repeatRate',
                'معدّل تكرار الشراء',
                $repeat,
                $payingCount,
                'عملاءُ لهم طلبان فأكثر ÷ العملاء الذين اشتروا مرّةً على الأقلّ.',
                'لا عميل اشترى بعد — لا مقام لمعدّل التكرار.',
            ),
        ];

        if ($payingCount === 0) {
            $metrics[] = Metric::noData('lifetimeValue', 'متوسّط قيمة العميل', 'لا عميل دافع — لا متوسّط يُحسب.');
            $metrics[] = Metric::noData('ordersPerCustomer', 'طلبات لكلّ عميل', 'لا عميل دافع — لا متوسّط يُحسب.');
        } else {
            $metrics[] = Metric::measured(
                'lifetimeValue',
                'متوسّط قيمة العميل',
                'money',
                intdiv((int) $paying->sum('spend'), $payingCount),
                'مجموع إنفاق العملاء الدافعين ÷ عددهم.',
            );
            $metrics[] = Metric::measured(
                'ordersPerCustomer',
                'طلبات لكلّ عميل',
                'count',
                round($paying->sum('orders') / $payingCount, 2),
                'مجموع طلبات العملاء الدافعين ÷ عددهم.',
            );
        }

        return [...$metrics, ...$this->segments($paying)];
    }

    /**
     * الشرائح بقواعدها — والقاعدة تُعاد مع الرقم.
     *
     * @param  Collection<int, object>  $paying
     * @return list<Metric>
     */
    private function segments(Collection $paying): array
    {
        if ($paying->isEmpty()) {
            return [Metric::noData('segments', 'شرائح العملاء', 'لا عميل دافع — لا شريحة تُحسب.')];
        }

        $rules = (array) config('insights.segments');
        $dormantDays = (int) $rules['dormant_after_days'];
        $atRiskDays = (int) $rules['at_risk_after_days'];

        /*
         * حدّ الكبار مئويّةٌ محسوبة لا مبلغٌ مكتوب: يتحرّك مع المتجر، ويبقى
         * معنى «الأعلى عشرة بالمئة» ثابتًا مهما تغيّرت الأسعار.
         */
        $spends = $paying->pluck('spend')->map(fn ($v) => (int) $v)->sort()->values();
        $vipFloor = (int) $spends[(int) floor((float) $rules['vip_spend_percentile'] * ($spends->count() - 1))];

        $now = now();
        $counts = ['NEW' => 0, 'REPEAT' => 0, 'VIP' => 0, 'AT_RISK' => 0, 'DORMANT' => 0];

        foreach ($paying as $row) {
            $since = (int) $now->diffInDays(Carbon::parse($row->last_order), absolute: true);

            /*
             * الترتيب يحسم التداخل: الخامل خاملٌ ولو كان كبيرًا، والمعرَّض
             * للفقد يسبق «متكرّر». بلا ترتيبٍ معلن يقع العميل في شريحتين
             * فيصير مجموع الشرائح أكبر من عدد العملاء.
             */
            if ($since >= $dormantDays) {
                $counts['DORMANT']++;
            } elseif ((int) $row->orders > 1 && $since >= $atRiskDays) {
                $counts['AT_RISK']++;
            } elseif ((int) $row->spend >= $vipFloor) {
                $counts['VIP']++;
            } elseif ((int) $row->orders > 1) {
                $counts['REPEAT']++;
            } else {
                $counts['NEW']++;
            }
        }

        $defs = [
            'NEW' => ['segmentNew', 'جدد — طلبٌ واحد', 'طلبٌ مدفوع واحد، وإنفاقٌ دون حدّ الكبار.'],
            'REPEAT' => ['segmentRepeat', 'متكرّرون', 'طلبان مدفوعان فأكثر، وآخر شراءٍ حديث.'],
            'VIP' => ['segmentVip', 'كبار الإنفاق', "إنفاقٌ عند حدّ الشريحة العليا أو فوقه — {$vipFloor} هللة."],
            'AT_RISK' => ['segmentAtRisk', 'معرَّضون للفقد', "طلبان فأكثر، وآخر شراءٍ منذ {$atRiskDays} يومًا فأكثر."],
            'DORMANT' => ['segmentDormant', 'خاملون', "آخر شراءٍ منذ {$dormantDays} يومًا فأكثر."],
        ];

        $out = [];

        foreach ($defs as $key => [$metricKey, $label, $formula]) {
            $out[] = Metric::measured(
                $metricKey,
                $label,
                'count',
                $counts[$key],
                $formula,
                href: '/customers',
                alert: $key === 'AT_RISK' && $counts[$key] > 0,
            );
        }

        return $out;
    }

    public function lists(InsightWindow $window): array
    {
        $top = DB::table('orders')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->whereIn('orders.status', self::PAID)
            ->selectRaw('customers.id, customers.email, customers.name, count(*) as orders, sum(orders.total_minor) as spend, max(orders.created_at) as last_order')
            ->groupBy('customers.id', 'customers.email', 'customers.name')
            ->orderByDesc('spend')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'email' => (string) $r->email,
                'name' => (string) $r->name,
                'orders' => (int) $r->orders,
                'spendMinor' => (int) $r->spend,
                'lastOrderAt' => Carbon::parse($r->last_order)->toIso8601String(),
                'href' => '/customers/'.(int) $r->id,
            ])
            ->all();

        return [
            'topByValue' => [
                'label' => 'أعلى العملاء إنفاقًا',
                'note' => 'بلا قيد مدًى — قيمة العميل تُقاس على عمره لا على شهر.',
                'rows' => $top,
            ],
        ];
    }
}
