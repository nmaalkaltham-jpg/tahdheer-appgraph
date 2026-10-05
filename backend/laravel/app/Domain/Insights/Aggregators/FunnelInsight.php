<?php

namespace App\Domain\Insights\Aggregators;

use App\Domain\Insights\InsightAggregator;
use App\Domain\Insights\InsightWindow;
use App\Domain\Insights\Metric;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use Illuminate\Support\Facades\DB;

/**
 * قمع التحويل — ما يُقاس منه من صفوف المعاملات، وما لا يُقاس ولماذا.
 *
 * ─── القمع مقطوعٌ من أعلاه ───
 *
 * القمع الكامل يبدأ من الزائر: زيارة ← عرض منتج ← إضافة إلى السلّة ← بدء
 * دفع ← محاولة دفع ← نجاح. والثلاث الأولى سلوكُ متصفّحٍ لا أثر له في هذا
 * المشروع: لا جدول أحداث، ولا نقطة نهاية تستقبلها، ولا باعثَ في المتجر.
 * جداول `analytics_events` و`analytics_daily` موجودة في قاعدة التطوير وحدها
 * بوصفها رواسبَ شيفرةٍ حُذفت — مسجَّلةً في `scripts/known-schema-residue.json`
 * — ولا هجرةَ تُنشئها في نشرٍ نظيف.
 *
 * ─── فلماذا لا يُحسب القمع من الصفوف الموجودة إذن ───
 *
 * جُرّب: صفوف تلك الرواسب تقول اثنين وعشرين شراءً مقابل سبع مشاهدات منتج.
 * قمعٌ تحويلُه ثلاثمئة بالمئة. والسبب أنّ أحداث المال كانت تُكتب في الخادم من
 * صفوف الطلبات بينما المشاهدات تأتي من المتصفّح، فالمقام والبسط من عالمين.
 * رقمٌ كهذا ليس نصف حقيقة، هو خطأٌ يبدو معقولًا — وهو ما تمنعه قاعدة «لا
 * تخترع Metric غير قابلة للقياس».
 *
 * ─── وما يبقى صادقًا ───
 *
 * أسفل القمع كلّه صفوف معاملات: سلّةٌ فيها صنف، ثمّ طلبٌ أُنشئ، ثمّ طلبٌ وصل
 * ماله. ثلاث مراحل حقيقيّة بمصدرٍ واحد، فتُعرض مراحلَ ويُعلَن ما فوقها غائبًا
 * باسمه. نصفُ قمعٍ مقيس خيرٌ من قمعٍ كاملٍ نصفه مخترع.
 */
final readonly class FunnelInsight implements InsightAggregator
{
    private const PAID = OrderController::PAID_STATUSES;

    /** ما يحتاجه كلّ ما فوق السلّة — يُقال مرّةً ويُعاد لكلّ مرحلةٍ غائبة. */
    private const MISSING_TELEMETRY = 'لا قياس سلوكَ متصفّحٍ في المشروع: لا جدول أحداث، ولا نقطة '
        .'نهاية تستقبلها، ولا باعثَ في المتجر. جداول التحليلات في قاعدة التطوير رواسبُ شيفرةٍ '
        .'حُذفت (scripts/known-schema-residue.json) ولا هجرةَ تُنشئها.';

    public function metrics(InsightWindow $window): array
    {
        $cartsWithItems = DB::table('carts')
            ->whereBetween('carts.created_at', [$window->from, $window->to])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('cart_items')->whereColumn('cart_items.cart_id', 'carts.id'))
            ->count();

        $ordersCreated = DB::table('orders')
            ->whereBetween('created_at', [$window->from, $window->to])
            ->count();

        $ordersPaid = DB::table('orders')
            ->whereBetween('created_at', [$window->from, $window->to])
            ->whereIn('status', self::PAID)
            ->count();

        return [
            Metric::notConfigured('visitors', 'زوّار', self::MISSING_TELEMETRY),
            Metric::notConfigured('productViews', 'مشاهدات منتج', self::MISSING_TELEMETRY),
            Metric::notConfigured('addToCart', 'إضافة إلى السلّة', self::MISSING_TELEMETRY),

            Metric::measured(
                'cartsWithItems',
                'سلال فيها أصناف',
                'count',
                $cartsWithItems,
                'سلالٌ أُنشئت في المدى وتحوي صنفًا واحدًا على الأقلّ. أوّل مرحلةٍ لها صفٌّ في قاعدة البيانات.',
            ),
            Metric::measured(
                'ordersCreated',
                'طلبات أُنشئت',
                'count',
                $ordersCreated,
                'كلّ طلبٍ أُنشئ في المدى مهما كانت حالته.',
                href: '/orders?'.$window->queryString(),
            ),
            Metric::measured(
                'ordersPaid',
                'طلبات وصل مالها',
                'count',
                $ordersPaid,
                'طلباتٌ حالتها مدفوع أو قيد التنفيذ أو مكتمل.',
                href: '/orders?view=paid&'.$window->queryString(),
            ),

            /*
             * ─── لماذا لا نسبةَ بين السلّة والطلب ───
             *
             * حُسبت فخرجت مئةً وستّةً وثمانين بالمئة: مئةٌ وثلاثة وخمسون طلبًا
             * مقابل اثنتين وثمانين سلّة. وتحويلٌ فوق المئة مستحيل، وسببه أنّ
             * البسط والمقام مجموعتان لا رابط بينهما: لا عمودَ في `carts` يقول
             * أيّ طلبٍ صارته، ولا عمودَ في `orders` يقول من أيّ سلّةٍ جاء.
             *
             * فالنسبة ليست «تقريبيّة»، هي بلا معنى. تُعلَن الحلقة المفقودة
             * باسمها — وهي فجوةٌ خلفيّةٌ تُصلَح بعمودٍ واحد — ولا يُعرض رقمٌ
             * يبدو معقولًا ويقود إلى قرارٍ خاطئ.
             */
            Metric::notConfigured(
                'cartToOrder',
                'من سلّةٍ إلى طلب',
                'لا رابط بين السلّة والطلب: لا عمود في carts يقول أيّ طلبٍ صارته، ولا عمود في '
                    .'orders يقول من أيّ سلّةٍ جاء. وقسمة المجموعتين على بعضهما أخرجت ١٨٦٪ — '
                    .'تحويلٌ فوق المئة مستحيل. يُصلَح بحفظ معرّف السلّة على الطلب عند إتمام الدفع.',
            ),
            Metric::ratio(
                'orderToPaid',
                'من طلبٍ إلى دفع',
                $ordersPaid,
                $ordersCreated,
                'الطلبات التي وصل مالها ÷ الطلبات المُنشأة.',
                'لا طلب في المدى — لا مقام لهذه المرحلة.',
                alert: true,
            ),

            Metric::notConfigured(
                'largestLeak',
                'أكبر تسرّب',
                'يحتاج القمع كاملًا لتُقارَن مراحله. ونصفه الأعلى غير مقيس، فأكبر تسرّبٍ قد يكون فيه.',
            ),
        ];
    }

    public function lists(InsightWindow $window): array
    {
        /*
         * المراحل مصفوفةً مرتّبة لا خريطة: الرسم يحتاج الترتيب، وترتيب
         * مفاتيح الكائن في JSON ليس عقدًا يُعتمد عليه.
         */
        return [
            'stages' => [
                'label' => 'مراحل القمع',
                'note' => 'المراحل الثلاث الأولى بلا مصدر. و«سلال فيها أصناف» مقيسةٌ لكنّها غير '
                    .'مربوطة بما بعدها (linked=false)، فلا تُقسَم عليها مرحلةٌ تالية.',
                'rows' => [
                    ['key' => 'visitors', 'label' => 'زوّار', 'state' => 'NOT_CONFIGURED'],
                    ['key' => 'productViews', 'label' => 'مشاهدات منتج', 'state' => 'NOT_CONFIGURED'],
                    ['key' => 'addToCart', 'label' => 'إضافة إلى السلّة', 'state' => 'NOT_CONFIGURED'],
                    ['key' => 'cartsWithItems', 'label' => 'سلال فيها أصناف', 'state' => 'MEASURED', 'linked' => false],
                    ['key' => 'ordersCreated', 'label' => 'طلبات أُنشئت', 'state' => 'MEASURED', 'linked' => true],
                    ['key' => 'ordersPaid', 'label' => 'طلبات وصل مالها', 'state' => 'MEASURED', 'linked' => true],
                ],
            ],
        ];
    }
}
