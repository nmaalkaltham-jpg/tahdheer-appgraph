<?php

namespace App\Domain\Insights\Aggregators;

use App\Domain\Insights\InsightAggregator;
use App\Domain\Insights\InsightWindow;
use App\Domain\Insights\Metric;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use Illuminate\Support\Facades\DB;

/**
 * ذكاء المنتجات — الرابحون، والمتعثّرون، والفرص.
 *
 * ─── لماذا «بلا مبيعات» يُقاس على العمر لا على المدى ───
 *
 * منتجٌ لم يبع هذا الشهر قد يكون موسميًّا، ومنتجٌ لم يبع قطّ منذ نشره عطلٌ:
 * إمّا سعرٌ خاطئ، أو ملفٌّ ناقص، أو لا يُرى في البحث. الرقمان مختلفان تمامًا
 * في المعنى والعلاج، فيُفصلان ويُسمّى كلٌّ منهما بمداه.
 *
 * ─── وما لا يُقاس هنا ───
 *
 * «مشاهدات عالية وتحويل منخفض» يحتاج مشاهداتٍ، ولا قياسَ سلوكَ متصفّحٍ في
 * المشروع. يُعلَن باسمه ولا يُستبدل بترتيبٍ يشبهه.
 *
 * ─── والطلب غير الملبّى ───
 *
 * بحثٌ تكرّر ولم يُرجع نتيجة هو أصدق إشارةِ فرصةٍ في المتجر: زائرٌ طلب شيئًا
 * بلسانه فلم يجده. ومصدره `search_queries` وهو جدولٌ مُهاجَر يكتبه
 * `QueryPipeline` في كلّ بحث — لا رواسبَ ولا تخمين.
 */
final readonly class ProductsInsight implements InsightAggregator
{
    private const PAID = OrderController::PAID_STATUSES;

    public function metrics(InsightWindow $window): array
    {
        $published = DB::table('products')->where('status', 'published')->count();
        $total = DB::table('products')->count();

        $soldEver = fn () => DB::table('products')->whereExists(
            fn ($q) => $q->selectRaw('1')->from('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('orders.status', self::PAID)
                ->whereColumn('order_items.product_id', 'products.id'),
        );

        $neverSold = DB::table('products')->whereNotExists(
            fn ($q) => $q->selectRaw('1')->from('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('orders.status', self::PAID)
                ->whereColumn('order_items.product_id', 'products.id'),
        )->count();

        $soldInWindow = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::PAID)
            ->whereBetween('orders.created_at', [$window->from, $window->to])
            ->distinct()
            ->count('order_items.product_id');

        $noPrice = DB::table('products')->whereNotExists(
            fn ($q) => $q->selectRaw('1')->from('product_prices')->whereColumn('product_prices.product_id', 'products.id'),
        )->count();

        $noFiles = DB::table('products')->whereNotExists(
            fn ($q) => $q->selectRaw('1')->from('product_files')->whereColumn('product_files.product_id', 'products.id'),
        )->count();

        /*
         * التصنيف المدرسيّ ناقصٌ إن غاب أيّ من المراحل الأربع: المنتج لا
         * يظهر في أرشيفٍ ولا يُرشَّح، فهو منشورٌ وغير مكتشَف.
         */
        $noCurriculum = DB::table('products')
            ->where(fn ($q) => $q->whereNull('stage_id')->orWhereNull('grade_id')
                ->orWhereNull('subject_id')->orWhereNull('semester_id'))
            ->count();

        $unmetDemand = DB::table('search_queries')
            ->where('zero_result', true)
            ->whereBetween('created_at', [$window->from, $window->to])
            ->distinct()
            ->count('normalized_query');

        return [
            Metric::measured('published', 'منتجات منشورة', 'count', $published, "منتجاتٌ حالتها منشور، من {$total} في الكتالوج.", href: '/studio/catalog?status=published'),
            Metric::measured('soldInWindow', 'منتجات بيعت في المدى', 'count', $soldInWindow, 'منتجاتٌ فريدة ضمّها طلبٌ وصل ماله داخل المدى.'),
            Metric::measured(
                'neverSold',
                'منتجات لم تبع قطّ',
                'count',
                $neverSold,
                'منتجاتٌ لا صنفَ لها في أيّ طلبٍ وصل ماله — بلا قيد مدًى. «لم يبع هذا الشهر» و«لم يبع قطّ» عطلان مختلفان.',
                href: '/studio/catalog',
                alert: $neverSold > 0,
            ),
            Metric::measured('missingPrice', 'منتجات بلا سعر', 'count', $noPrice, 'لا صفَّ سعرٍ واحدًا — لا يمكن شراؤها.', href: '/studio/catalog', alert: $noPrice > 0),
            Metric::measured('missingFiles', 'منتجات بلا ملفّات', 'count', $noFiles, 'لا ملفَّ مرتبطًا — تُشترى ولا يُسلَّم شيء.', href: '/studio/catalog', alert: $noFiles > 0),
            Metric::measured('missingCurriculum', 'منتجات ناقصة التصنيف', 'count', $noCurriculum, 'ينقصها مرحلة أو صفّ أو مادّة أو فصل — لا تظهر في الأرشيفات ولا تُرشَّح.', href: '/catalog/quality', alert: $noCurriculum > 0),
            Metric::measured('unmetDemand', 'طلبٌ غير ملبّى', 'count', $unmetDemand, 'استعلامات بحثٍ فريدة لم تُرجع نتيجةً في المدى — أصدق إشارة فرصة.', href: '/demand'),
            Metric::notConfigured(
                'highViewLowConversion',
                'مشاهدات عالية وتحويل منخفض',
                'يحتاج مشاهدات منتجٍ لكلّ صفحة. لا قياس سلوكَ متصفّحٍ في المشروع: لا جدول أحداث ولا باعث في المتجر.',
            ),
            Metric::notConfigured(
                'wishlisted',
                'الأكثر حفظًا في المفضّلة',
                'لا جدول مفضّلة في المشروع.',
            ),
        ];
    }

    public function lists(InsightWindow $window): array
    {
        $winners = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('orders.status', self::PAID)
            ->whereBetween('orders.created_at', [$window->from, $window->to])
            ->selectRaw('products.id, products.title, count(distinct orders.id) as orders, sum(order_items.price_snapshot_minor) as revenue')
            ->groupBy('products.id', 'products.title')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'productId' => (int) $r->id,
                'title' => (string) $r->title,
                'orders' => (int) $r->orders,
                'revenueMinor' => (int) $r->revenue,
                'href' => '/studio/catalog/'.(int) $r->id,
            ])
            ->all();

        /*
         * المتعثّرون: منشورٌ ولم يبع قطّ. غير المنشور ليس عطلًا — هو مسوّدة،
         * وخلطُه بالمنشور يجعل القائمة ضجيجًا لا مهمّة.
         */
        $problems = DB::table('products')
            ->where('status', 'published')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereIn('orders.status', self::PAID)
                ->whereColumn('order_items.product_id', 'products.id'))
            ->selectRaw('id, title, sku, published_at')
            ->orderBy('published_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'productId' => (int) $r->id,
                'title' => (string) $r->title,
                'sku' => (string) $r->sku,
                'publishedAt' => $r->published_at === null ? null : (string) $r->published_at,
                'href' => '/studio/catalog/'.(int) $r->id,
            ])
            ->all();

        $opportunities = DB::table('search_queries')
            ->where('zero_result', true)
            ->whereBetween('created_at', [$window->from, $window->to])
            ->selectRaw('normalized_query, count(*) as searches, max(created_at) as last_seen')
            ->groupBy('normalized_query')
            ->orderByDesc('searches')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'query' => (string) $r->normalized_query,
                'searches' => (int) $r->searches,
                'lastSeenAt' => (string) $r->last_seen,
                'href' => '/demand',
            ])
            ->all();

        return [
            'winners' => ['label' => 'الأعلى إيرادًا في المدى', 'note' => 'مرتَّبة بمجموع الأسعار الملتقطة في الطلبات المدفوعة.', 'rows' => $winners],
            'problems' => ['label' => 'منشورة ولم تبع قطّ', 'note' => 'الأقدم نشرًا أوّلًا — أطولها انتظارًا.', 'rows' => $problems],
            'opportunities' => ['label' => 'بحثٌ بلا نتيجة', 'note' => 'ما طلبه الزوّار بلسانهم فلم يجدوه.', 'rows' => $opportunities],
        ];
    }
}
