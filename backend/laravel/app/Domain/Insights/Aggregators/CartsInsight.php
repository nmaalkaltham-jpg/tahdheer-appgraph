<?php

namespace App\Domain\Insights\Aggregators;

use App\Domain\Insights\InsightAggregator;
use App\Domain\Insights\InsightWindow;
use App\Domain\Insights\Metric;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use Illuminate\Support\Facades\DB;

/**
 * ذكاء السلال — ما وُضع ولم يُشترَ، وكم يساوي.
 *
 * ─── تعريف «مهجورة»، ولماذا سلّة الضيف خارجه ───
 *
 * المهجورة: سلّةٌ **لعميلٍ معروف** فيها صنفٌ واحدٌ على الأقلّ، ولا طلبَ مدفوعٌ
 * له بعد إنشائها.
 *
 * وقيدُ «عميلٍ معروف» ليس تفصيلًا. سلّة الضيف بلا `customer_id`، ولا سبيل إلى
 * ربطها بطلبٍ مهما تحوّلت، فهي «مهجورة» بحكم التعريف لا بحكم القياس. وعدّها
 * مع البقيّة أنتج في هذه القاعدة بالذات «معدّل هجر ١٠٠٪» من اثنتين وثمانين
 * سلّةٍ كلّها للضيوف: رقمٌ يُفزع المشغّل ولا يقيس شيئًا.
 *
 * فتُفصل سلال الضيوف عدّادًا قائمًا بذاته يقول إنّها غير قابلة للحكم، ويبقى
 * المقام العملاءَ المعروفين وحدهم. ومقامٌ صفريّ يُعلَن `NO_DATA` بسببه.
 *
 * ─── وما لا يُقاس هنا ───
 *
 * «معدّل الاسترداد» يحتاج أثرَ رسالةِ متابعةٍ مرتبطًا بالسلّة. أعمدة المتابعة
 * في المشروع على **الطلب** لا على السلّة (`orders.reminder_stage`)، وهي
 * متابعةُ دفعٍ لطلبٍ قائم لا استردادَ سلّةٍ مهجورة. فيُعلَن غير مهيّأ ولا
 * يُحسب من عمودٍ يقيس شيئًا آخر.
 *
 * و«بدء الدفع» حدثٌ في `analytics_events` لا صفٌّ في جدول، وإطلاقه اليوم
 * مرّةٌ واحدة في القاعدة كلّها — فالمقام غير صالح ويُعلَن كذلك.
 */
final readonly class CartsInsight implements InsightAggregator
{
    private const PAID = OrderController::PAID_STATUSES;

    public function metrics(InsightWindow $window): array
    {
        $inWindow = fn () => DB::table('carts')->whereBetween('carts.created_at', [$window->from, $window->to]);

        $total = $inWindow()->count();

        $withItems = $inWindow()
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('cart_items')->whereColumn('cart_items.cart_id', 'carts.id'))
            ->count();

        /* سلالٌ لعميلٍ معروف — وحدها قابلةٌ للحكم بالتحوّل أو الهجر. */
        $identified = $inWindow()
            ->whereNotNull('carts.customer_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('cart_items')->whereColumn('cart_items.cart_id', 'carts.id'))
            ->count();

        $guest = $inWindow()
            ->whereNull('carts.customer_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('cart_items')->whereColumn('cart_items.cart_id', 'carts.id'))
            ->count();

        $abandonedIds = $inWindow()
            ->whereNotNull('carts.customer_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('cart_items')->whereColumn('cart_items.cart_id', 'carts.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('orders')
                ->whereColumn('orders.customer_id', 'carts.customer_id')
                ->whereIn('orders.status', self::PAID)
                ->whereColumn('orders.created_at', '>=', 'carts.created_at'))
            ->pluck('carts.id');

        $abandonedValue = $abandonedIds->isEmpty() ? 0 : (int) DB::table('cart_items')
            ->whereIn('cart_id', $abandonedIds)
            ->selectRaw('coalesce(sum(qty * unit_price_snapshot_minor), 0) as v')
            ->value('v');

        $cartValue = (int) DB::table('cart_items')
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->whereBetween('carts.created_at', [$window->from, $window->to])
            ->selectRaw('coalesce(sum(qty * unit_price_snapshot_minor), 0) as v')
            ->value('v');

        $abandoned = $abandonedIds->count();

        return [
            Metric::measured('cartsCreated', 'سلال أُنشئت', 'count', $total, 'كلّ سلّةٍ أُنشئت في المدى، فارغةً كانت أو ممتلئة.'),
            Metric::measured('cartsWithItems', 'سلال فيها أصناف', 'count', $withItems, 'سلالٌ تحوي صنفًا واحدًا على الأقلّ.'),
            Metric::measured(
                'identifiedCarts',
                'سلال لعميلٍ معروف',
                'count',
                $identified,
                'سلالٌ فيها أصناف ولها `customer_id` — وحدها يمكن الحكم عليها بالتحوّل أو الهجر.',
            ),
            Metric::measured(
                'guestCarts',
                'سلال ضيوف — بلا حكم',
                'count',
                $guest,
                'سلالٌ فيها أصناف بلا حساب. لا سبيل إلى ربطها بطلبٍ مهما تحوّلت، فلا تدخل معدّل الهجر ولا تُعدّ مهجورة.',
            ),
            Metric::measured(
                'abandonedCarts',
                'سلال مهجورة',
                'count',
                $abandoned,
                'سلّةٌ لعميلٍ معروف فيها صنف، ولا طلبَ مدفوعٌ له بعد إنشائها.',
                alert: $abandoned > 0,
            ),
            Metric::measured(
                'abandonedValue',
                'قيمة السلال المهجورة',
                'money',
                $abandonedValue,
                'مجموع (الكمّيّة × السعر الملتقط) في السلال المهجورة.',
            ),
            Metric::ratio(
                'abandonmentRate',
                'معدّل الهجر',
                $abandoned,
                $identified,
                'السلال المهجورة ÷ سلال العملاء المعروفين. سلال الضيوف خارج المقام — لا يمكن الحكم عليها.',
                'لا سلّة لعميلٍ معروف في المدى — لا مقام لمعدّل الهجر. كلّ السلال للضيوف.',
                alert: true,
            ),
            $withItems === 0
                ? Metric::noData('averageCartValue', 'متوسّط قيمة السلّة', 'لا سلّة فيها أصناف في المدى.')
                : Metric::measured('averageCartValue', 'متوسّط قيمة السلّة', 'money', intdiv($cartValue, $withItems), 'قيمة أصناف سلال المدى ÷ عدد السلال التي فيها أصناف.'),
            Metric::notConfigured(
                'recoveryRate',
                'معدّل استرداد السلال',
                'لا أثرَ لرسالة متابعةٍ مرتبطةٍ بالسلّة. وأعمدة المتابعة على الطلب '
                    .'(orders.reminder_stage و reminded_at) ليست مُهاجَرة أصلًا — هي رواسبُ شيفرةٍ '
                    .'حُذفت (scripts/known-schema-residue.json)، وكانت متابعة دفعٍ لطلبٍ قائم لا '
                    .'استرداد سلّةٍ مهجورة.',
            ),
            Metric::notConfigured(
                'checkoutStartRate',
                'معدّل بدء الدفع',
                'يحتاج حدث checkout_start لكلّ جلسة. الحدث مُعرَّف في analytics_events '
                    .'ولا يُطلَق من المتجر إلّا نادرًا، فالمقام غير صالح.',
            ),
        ];
    }

    public function lists(InsightWindow $window): array
    {
        /*
         * أكثر المنتجات هجرًا: يُرتَّب بعدد السلال المهجورة لا بالكمّيّة —
         * صنفٌ واحدٌ بكمّيّة عشرة في سلّةٍ واحدة إشارةٌ أضعف من عشر سلالٍ
         * مختلفة تركته.
         */
        $rows = DB::table('cart_items')
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->join('products', 'products.id', '=', 'cart_items.product_id')
            ->whereBetween('carts.created_at', [$window->from, $window->to])
            ->whereNotNull('carts.customer_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('orders')
                ->whereColumn('orders.customer_id', 'carts.customer_id')
                ->whereIn('orders.status', self::PAID)
                ->whereColumn('orders.created_at', '>=', 'carts.created_at'))
            ->selectRaw('products.id, products.title, count(distinct carts.id) as carts, sum(cart_items.qty * cart_items.unit_price_snapshot_minor) as value')
            ->groupBy('products.id', 'products.title')
            ->orderByDesc('carts')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'productId' => (int) $r->id,
                'title' => (string) $r->title,
                'carts' => (int) $r->carts,
                'valueMinor' => (int) $r->value,
                'href' => '/studio/catalog/'.(int) $r->id,
            ])
            ->all();

        return [
            'topAbandonedProducts' => [
                'label' => 'أكثر المنتجات هجرًا',
                'note' => 'مرتَّبة بعدد السلال المهجورة التي ضمّتها.',
                'rows' => $rows,
            ],
        ];
    }
}
