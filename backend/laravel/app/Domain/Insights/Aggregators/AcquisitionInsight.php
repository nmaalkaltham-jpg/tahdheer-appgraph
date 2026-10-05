<?php

namespace App\Domain\Insights\Aggregators;

use App\Domain\Insights\InsightAggregator;
use App\Domain\Insights\InsightWindow;
use App\Domain\Insights\Metric;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use Illuminate\Support\Facades\DB;

/**
 * قنوات الاكتساب — نطاقٌ كان مُعلَن الغياب، وصار نصفه مقيسًا.
 *
 * ─── ما تغيّر، ولماذا وجب تغيير هذا الملفّ ───
 *
 * كان هنا سببٌ واحد لكلّ مؤشّر: «لا جدول جلسات، ولا عمود مصدرٍ على الطلب،
 * ولا التقاطَ لوسوم الحملات». وثلثاه لم يعودا صحيحين: للجلسة بصمةٌ في
 * `analytics_events`، وللطلب مُحيلٌ محفوظ في `order_attributions`.
 *
 * وإبقاءُ «غير مهيّأ» على قياسٍ صار مبنيًّا هو العطل الأخطر في هذا المشروع —
 * سطحٌ يقول «لم يُبنَ» عن شيءٍ بُني، فلا يفتحه أحد ولا يُصلحه أحد. فالملفّ
 * يتبع المصدر لا العكس.
 *
 * ─── وما بقي غائبًا بحقّ ───
 *
 * وسوم الحملات (UTM): لا التقاطَ لها في المتجر ولا عمودَ يحملها، فالتمييز
 * بين حملتين على المُحيل نفسه مستحيل — ويُعلَن.
 *
 * والكلفة: CAC وROAS نسبتان مقامهما إنفاقٌ إعلانيّ، ولا مكانَ في المشروع
 * يُدخَل فيه إنفاق. ونسبةٌ بمقامٍ مخترع أسوأ من غيابها — تُبنى عليها قرارات
 * ميزانيّة.
 *
 * ─── وتصنيف القناة تحويلٌ مُعلَن لا اختراع ───
 *
 * القناة تُشتقّ من المضيف بجدولٍ ثابتٍ مكتوبٍ هنا، والمضيفُ الخام يبقى
 * معروضًا في `lists` — فمن يشكّ في التصنيف يراجع مصدره. تصنيفٌ لا يُرى
 * مصدره هو الذي يصير اختراعًا.
 */
final readonly class AcquisitionInsight implements InsightAggregator
{
    private const PAID = OrderController::PAID_STATUSES;

    private const NO_CAMPAIGN = 'يحتاج التقاط وسوم الحملات (UTM) عند الدخول وحملها حتّى الطلب. '
        .'لا التقاطَ لها في المتجر ولا عمودَ يحملها، فحملتان على المُحيل نفسه لا تفترقان.';

    private const NO_COST = 'يحتاج إنفاقًا إعلانيًّا حقيقيًّا. لا جدول حملاتٍ بميزانيّة ولا ربطَ '
        .'بمنصّة إعلانات، ونسبةٌ بمقامٍ مخترع تُبنى عليها قرارات ميزانيّة.';

    /**
     * تصنيف المضيف إلى قناة.
     *
     * المطابقة بلاحقة النطاق لا باحتواء النصّ: «google» موجودةٌ في
     * `notgoogle.example.com` أيضًا، والاحتواء يصنّفها بحثًا.
     */
    private const CHANNELS = [
        'بحث' => ['google.', 'bing.', 'yahoo.', 'duckduckgo.', 'yandex.', 'baidu.'],
        'اجتماعي' => ['facebook.', 'instagram.', 'twitter.', 'x.com', 'tiktok.', 'snapchat.', 'linkedin.', 'pinterest.', 'youtube.'],
        'مراسلة' => ['whatsapp.', 'telegram.', 't.co', 'l.facebook.'],
    ];

    public function metrics(InsightWindow $window): array
    {
        $byChannel = $this->byChannel($window);

        $visitors = $this->visitorsByChannel($window);
        $visitorTotal = array_sum($visitors);

        $orders = array_sum(array_column($byChannel, 'orders'));
        $revenue = array_sum(array_column($byChannel, 'revenueMinor'));

        return [
            Metric::measured(
                'channels',
                'قنوات مقيسة',
                'count',
                count($byChannel),
                'قنواتٌ ورد منها زائرٌ أو طلبٌ في المدى، مشتقّةً من مضيف المُحيل.',
            ),
            Metric::measured(
                'visitorsByChannel',
                'زوّار مقيسون',
                'count',
                $visitorTotal,
                'زوّارٌ فريدون (بصمة زائر) لهم حدثٌ في المدى. لا يشمل من رفض القياس — وهم غير قابلين للعدّ بالتعريف.',
            ),
            Metric::measured(
                'ordersByChannel',
                'طلبات مُسنَدة إلى قناة',
                'count',
                $orders,
                'طلباتٌ مدفوعةٌ لها سطر إسناد. القناة من مُحيل اللمسة الأولى.',
            ),
            Metric::measured(
                'revenueByChannel',
                'إيرادٌ مُسنَد إلى قناة',
                'money',
                $revenue,
                'مجموع `total_minor` للطلبات المدفوعة المُسنَدة، موزّعًا على القنوات في القائمة.',
            ),
            Metric::ratio(
                'conversionByChannel',
                'تحويل الزوّار المقيسين',
                $orders,
                $visitorTotal,
                'طلباتٌ مُسنَدة ÷ زوّارٌ فريدون مقيسون. المقام زوّارٌ وافقوا على القياس وحدهم، '
                    .'فالنسبة نسبةُ عيّنةٍ لا نسبةُ المتجر كلّه.',
                'لا زائر مقيسٌ في المدى: لم يوافق أحد على القياس بعد، فلا مقام للنسبة.',
            ),
            Metric::notConfigured('campaigns', 'الحملات', self::NO_CAMPAIGN),
            Metric::notConfigured('cac', 'كلفة اكتساب العميل', self::NO_COST),
            Metric::notConfigured('roas', 'العائد على الإنفاق الإعلانيّ', self::NO_COST),
        ];
    }

    public function lists(InsightWindow $window): array
    {
        $channels = $this->byChannel($window);
        $visitors = $this->visitorsByChannel($window);

        /*
         * الاتّحاد بـ`array_merge` لا بـ`+`: الأخير يُبقي قيمة اليسار عند
         * تصادم المفتاح، فيبدو صحيحًا هنا ويصير فخًّا عند أوّل تعديل يقرأ
         * القيمة بدل المفتاح.
         */
        $names = array_unique(array_merge(array_keys($visitors), array_keys($channels)));

        $rows = [];
        foreach ($names as $channel) {
            $rows[] = [
                'channel' => $channel,
                'visitors' => $visitors[$channel] ?? 0,
                'orders' => $channels[$channel]['orders'] ?? 0,
                'revenueMinor' => $channels[$channel]['revenueMinor'] ?? 0,
            ];
        }

        usort($rows, fn ($a, $b) => $b['revenueMinor'] <=> $a['revenueMinor'] ?: $b['visitors'] <=> $a['visitors']);

        return [
            'channels' => [
                'label' => 'القنوات',
                'note' => 'القناة مشتقّةٌ من مضيف المُحيل بجدولٍ ثابت. الزائر يُنسب إلى قناة أوّل حدثٍ له في المدى، '
                    .'والطلب إلى قناة لمسته الأولى — فقد يزيد عدد الزوّار عن مجموع أيّ قناةٍ واحدة.',
                'empty' => 'لا زائر مقيسٌ ولا طلب مُسنَد في المدى.',
                'rows' => $rows,
            ],
        ];
    }

    /**
     * طلبات وإيراد كلّ قناة.
     *
     * @return array<string, array{channel: string, orders: int, revenueMinor: int}>
     */
    private function byChannel(InsightWindow $window): array
    {
        $rows = DB::table('order_attributions')
            ->join('orders', 'orders.id', '=', 'order_attributions.order_id')
            ->whereIn('orders.status', self::PAID)
            ->whereBetween('orders.created_at', [$window->from, $window->to])
            ->groupBy('order_attributions.landing_referrer_host')
            ->get([
                DB::raw('order_attributions.landing_referrer_host as host'),
                DB::raw('count(*) as orders'),
                DB::raw('coalesce(sum(orders.total_minor), 0) as "revenueMinor"'),
            ]);

        $out = [];
        foreach ($rows as $row) {
            $channel = $this->channel($row->host);
            $out[$channel] ??= ['channel' => $channel, 'orders' => 0, 'revenueMinor' => 0];
            $out[$channel]['orders'] += (int) $row->orders;
            $out[$channel]['revenueMinor'] += (int) $row->revenueMinor;
        }

        return $out;
    }

    /**
     * زوّارٌ فريدون لكلّ قناة.
     *
     * القناة من **أوّل** حدثٍ للزائر في المدى: زائرٌ دخل من بحثٍ ثمّ عاد
     * مباشرةً قناتُه البحث، وعدُّه في الاثنتين يجعل المجموع أكبر من الحقيقة.
     *
     * @return array<string, int>
     */
    private function visitorsByChannel(InsightWindow $window): array
    {
        $firsts = DB::table('analytics_events as e')
            ->whereNotNull('e.visitor_hash')
            ->whereBetween('e.occurred_at', [$window->from, $window->to])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('analytics_events as earlier')
                ->whereColumn('earlier.visitor_hash', 'e.visitor_hash')
                ->whereBetween('earlier.occurred_at', [$window->from, $window->to])
                ->where(fn ($w) => $w->whereColumn('earlier.occurred_at', '<', 'e.occurred_at')
                    ->orWhere(fn ($t) => $t->whereColumn('earlier.occurred_at', '=', 'e.occurred_at')
                        ->whereColumn('earlier.id', '<', 'e.id'))))
            ->get(['e.visitor_hash', 'e.referrer_host']);

        $out = [];
        foreach ($firsts as $row) {
            $channel = $this->channel($row->referrer_host);
            $out[$channel] = ($out[$channel] ?? 0) + 1;
        }

        return $out;
    }

    private function channel(?string $host): string
    {
        if ($host === null || trim($host) === '') {
            return 'مباشر';
        }

        $host = mb_strtolower($host);

        foreach (self::CHANNELS as $channel => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($host, $prefix) || str_contains($host, '.'.$prefix) || $host === rtrim($prefix, '.')) {
                    return $channel;
                }
            }
        }

        return 'إحالة';
    }
}
