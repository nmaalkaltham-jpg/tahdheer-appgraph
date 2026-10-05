<?php

namespace App\Domain\Insights\Aggregators;

use App\Domain\Insights\InsightAggregator;
use App\Domain\Insights\InsightWindow;
use App\Domain\Insights\Metric;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use Illuminate\Support\Facades\DB;

/**
 * إيراد الصفحات — السؤال الذي كان بلا جواب ممكن.
 *
 * ─── لماذا لم يكن ممكنًا ───
 *
 * جدول `orders` لا يحمل عمود إسنادٍ واحدًا. فـ«أيّ صفحةٍ أنتجت هذا الإيراد»
 * لم تكن لوحةً ناقصة بل استحالةً بنيويّة: لا شيء في الطلب يشير إلى ما سبقه.
 * وطبقة الأحداث ثمّ `order_attributions` هما ما جعلاه سؤالًا له مصدر.
 *
 * ─── ونموذج الإسناد مُعلَنٌ في كلّ صيغة ───
 *
 * **اللمسة الأولى**: أوّل صفحةٍ رآها الزائر ضمن المدّة المحفوظة. لا آخر
 * لمسة — وهي التي تنسب كلّ إيرادٍ إلى صفحة الدفع فيبدو المتجر كأنّه يكسب من
 * صفحاتٍ لا تجلب أحدًا. من يقرأ الرقم يجب أن يعرف أيّ نموذجٍ أنتجه، ولذلك
 * النموذج في `formula` لا في تعليقٍ لا يصل الواجهة.
 *
 * ─── والتغطية تُعلَن قبل الأرقام ───
 *
 * الإسناد يحتاج موافقةً على القياس، فطلبُ من رفض القياس لا سطر إسنادٍ له —
 * وهذا صحيحٌ لا عطل. ولكن قراءة «إيراد الصفحة» بلا معرفة أنّها تغطّي ثلث
 * الطلبات تجعل المقارنة بين صفحتين مقارنةً بين عيّنتين مجهولتَي الحجم.
 * فالتغطية مؤشّرٌ أوّل لا حاشية.
 */
final readonly class PagesInsight implements InsightAggregator
{
    private const PAID = OrderController::PAID_STATUSES;

    /** أقصى ما يُعرض في القوائم — ذيلٌ طويلٌ بلا حدٍّ يُغرق اللوحة. */
    private const TOP = 20;

    private const NO_ATTRIBUTION = 'لا طلب مُسنَد في المدى بعد. الإسناد يُكتب لحظة الشراء لمن وافق على '
        .'القياس، فالغياب هنا يعني إمّا لا مبيعات في المدى، وإمّا أنّ أحدًا لم يوافق.';

    public function metrics(InsightWindow $window): array
    {
        /*
         * الأعمدة مؤهَّلة بجدولها: الاستعلام يُضمّ لاحقًا إلى
         * `order_attributions` وفيه `created_at` أيضًا، و`created_at` مجرّدًا
         * يصير ملتبسًا فيسقط الاستعلام كلّه.
         */
        $paidOrders = DB::table('orders')
            ->whereIn('orders.status', self::PAID)
            ->whereBetween('orders.created_at', [$window->from, $window->to]);

        $total = (clone $paidOrders)->count();

        $attributedIds = (clone $paidOrders)
            ->join('order_attributions', 'order_attributions.order_id', '=', 'orders.id')
            ->pluck('orders.id');

        $attributed = $attributedIds->count();

        $revenue = $attributed === 0 ? 0 : (int) DB::table('orders')
            ->whereIn('id', $attributedIds)
            ->selectRaw('coalesce(sum(total_minor), 0) as v')
            ->value('v');

        $landings = (clone $paidOrders)
            ->join('order_attributions', 'order_attributions.order_id', '=', 'orders.id')
            ->whereNotNull('order_attributions.landing_path')
            ->distinct()
            ->count('order_attributions.landing_path');

        /*
         * لمسةٌ واحدة تعني أنّ الزائر وافق على القياس في الصفحة التي اشترى
         * منها. ونسبةُ إيرادها إلى «صفحةٍ جالبة» استنتاجٌ لا قياس — فيُعلَن
         * عددها ليقرأ المشغّل بقيّة الأرقام على قدرها.
         */
        $singleTouch = (clone $paidOrders)
            ->join('order_attributions', 'order_attributions.order_id', '=', 'orders.id')
            ->where('order_attributions.touch_count', '<=', 1)
            ->count();

        return [
            Metric::ratio(
                'attributionCoverage',
                'تغطية الإسناد',
                $attributed,
                $total,
                'طلباتٌ مدفوعةٌ لها سطر إسناد ÷ كلّ الطلبات المدفوعة في المدى. '
                    .'ما دونها طلباتُ من لم يوافق على القياس — وغيابُها صحيحٌ لا عطل.',
                'لا طلب مدفوعٌ في المدى، فلا مقام للنسبة.',
            ),
            Metric::measured(
                'attributedOrders',
                'طلبات مُسنَدة',
                'count',
                $attributed,
                'طلباتٌ مدفوعةٌ في المدى لها سطر في `order_attributions`.',
            ),
            Metric::measured(
                'unattributedOrders',
                'طلبات بلا إسناد',
                'count',
                $total - $attributed,
                'طلباتٌ مدفوعةٌ بلا سطر إسناد: لم يوافق صاحبها على القياس، أو اشترى قبل بناء الطبقة.',
            ),
            Metric::measured(
                'attributedRevenue',
                'إيرادٌ مُسنَد',
                'money',
                $revenue,
                'مجموع `total_minor` للطلبات المدفوعة المُسنَدة. لا يساوي إيراد المتجر — يساوي ما يمكن نسبته إلى صفحة.',
            ),
            Metric::measured(
                'landingPages',
                'صفحات جالبة',
                'count',
                $landings,
                'عدد المسارات المختلفة التي كانت أوّل ما رآه مشترٍ (نموذج اللمسة الأولى).',
            ),
            Metric::measured(
                'singleTouchOrders',
                'إسنادٌ بلمسةٍ واحدة',
                'count',
                $singleTouch,
                'طلباتٌ لصاحبها حدثٌ واحد أو لا حدث: وافق على القياس عند الشراء، فصفحتُه الأولى هي صفحة الشراء نفسها.',
                alert: $attributed > 0 && $singleTouch === $attributed,
            ),
        ];
    }

    public function lists(InsightWindow $window): array
    {
        $rows = DB::table('order_attributions')
            ->join('orders', 'orders.id', '=', 'order_attributions.order_id')
            ->whereIn('orders.status', self::PAID)
            ->whereBetween('orders.created_at', [$window->from, $window->to]);

        $byPage = (clone $rows)
            ->whereNotNull('order_attributions.landing_path')
            ->groupBy('order_attributions.landing_path')
            ->orderByDesc('revenueMinor')
            ->limit(self::TOP)
            ->get([
                DB::raw('order_attributions.landing_path as path'),
                DB::raw('count(*) as orders'),
                DB::raw('coalesce(sum(orders.total_minor), 0) as "revenueMinor"'),
            ]);

        $byReferrer = (clone $rows)
            ->groupBy('order_attributions.landing_referrer_host')
            ->orderByDesc('revenueMinor')
            ->limit(self::TOP)
            ->get([
                DB::raw('order_attributions.landing_referrer_host as host'),
                DB::raw('count(*) as orders'),
                DB::raw('coalesce(sum(orders.total_minor), 0) as "revenueMinor"'),
            ]);

        return [
            'revenueByPage' => [
                'label' => 'إيراد كلّ صفحة جالبة',
                'note' => 'اللمسة الأولى. المسار بلا وسائط استعلام — فصفحةٌ واحدة لا تنقسم إلى عشر.',
                'empty' => self::NO_ATTRIBUTION,
                'rows' => $byPage->map(fn ($r) => [
                    'path' => $r->path,
                    'orders' => (int) $r->orders,
                    'revenueMinor' => (int) $r->revenueMinor,
                ])->all(),
            ],
            'revenueByReferrer' => [
                'label' => 'إيراد كلّ مُحيل',
                'note' => 'المضيف وحده لا الرابط الكامل. و«بلا مُحيل» زيارةٌ مباشرة أو مُحيلٌ حجبه المتصفّح.',
                'empty' => self::NO_ATTRIBUTION,
                'rows' => $byReferrer->map(fn ($r) => [
                    'host' => $r->host,
                    'orders' => (int) $r->orders,
                    'revenueMinor' => (int) $r->revenueMinor,
                ])->all(),
            ],
        ];
    }
}
