<?php

namespace App\Domain\Catalog;

use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use Illuminate\Support\Facades\DB;

/**
 * «يُشترى معه عادةً» — من الطلبات المدفوعة فعلًا، لا من التخمين.
 *
 * التوصية سلوكيّة أو تحريريّة، والمخطّط يفرّق بينهما بعمود `source` (§٥٧).
 * هذا الصانع يكتب **السلوكيّة وحدها** (`BEHAVIORAL_SIGNAL`)، ولا يمسّ صفًّا
 * مصدرُه إنسان: قرارُ محرّرٍ لا يُدهَس بإحصاءٍ من طلبين.
 *
 * والعتبة ليست زينة: زوجٌ ظهر مرّةً واحدة صدفةٌ لا نمط، وعرضُه تحت «يُشترى
 * معه عادةً» ادّعاءُ عادةٍ لم تُلاحَظ. فبلا طلباتٍ متعدّدة المنتجات لا تُكتب
 * علاقةٌ واحدة، والقسم يغيب عن الصفحة — وهو الجواب الأمين لمتجرٍ في أوّله.
 *
 * الثقة = P(ب | أ) مئويّةً: من اشترى أ، كم منهم اشترى ب معه. غير متماثلة
 * عمدًا، فتُكتب للاتّجاهين بقيمتين مختلفتين.
 */
final class CoPurchaseMiner
{
    /**
     * «مالٌ حُصّل فعلًا» — إشارةٌ إلى النسخة الواحدة لا تعريفٌ ثانٍ. قائمتان
     * متوازيتان تفترقان يومًا، فيوصي المتجر بناءً على طلباتٍ لم تُدفع.
     */
    public const PAID_STATUSES = AdminOrderController::PAID_STATUSES;

    public const RELATION = 'CROSS_SELL';

    public const SOURCE = 'BEHAVIORAL_SIGNAL';

    /** أقلّ عددِ طلباتٍ يجمع المنتجين حتّى يُعدّ الاقتران نمطًا. */
    public const MIN_SUPPORT = 2;

    /** أكثر ما يُحفظ لكلّ منتج — الصفحة تعرض أربعة. */
    public const MAX_PER_PRODUCT = 8;

    /**
     * @return array{written: int, removed: int, pairs: int}
     */
    public function mine(int $minSupport = self::MIN_SUPPORT): array
    {
        $pairs = $this->pairs($minSupport);
        $totals = $this->productOrderTotals();
        $groups = $this->groupByLegacyProduct();

        /* الاتّجاهان صفّان مستقلّان: ثقة «أ ← ب» غير ثقة «ب ← أ». */
        $directed = [];
        foreach ($pairs as $pair) {
            foreach ([[$pair->p1, $pair->p2], [$pair->p2, $pair->p1]] as [$from, $to]) {
                $fromGroup = $groups[$from] ?? null;
                $toGroup = $groups[$to] ?? null;
                if ($fromGroup === null || $toGroup === null || $fromGroup === $toGroup) {
                    continue;
                }

                $base = $totals[$from] ?? 0;
                if ($base <= 0) {
                    continue;
                }

                $directed[$fromGroup][] = [
                    'to' => $toGroup,
                    'support' => (int) $pair->n,
                    'confidence' => min(100, (int) round((int) $pair->n / $base * 100)),
                ];
            }
        }

        return DB::transaction(function () use ($directed) {
            /*
             * الاستبدال كاملٌ لكن للسلوكيّ وحده: علاقةٌ سقطت عن العتبة يجب
             * أن تختفي، وإلّا بقيت الصفحة تعرض نمطًا انقضى. والتحريريّ يبقى.
             */
            $removed = DB::table('product_relationships')
                ->where('relation_type', self::RELATION)
                ->where('source', self::SOURCE)
                ->delete();

            $written = 0;
            $now = now();

            foreach ($directed as $fromGroup => $rows) {
                usort($rows, static fn ($a, $b) => [$b['confidence'], $b['support']] <=> [$a['confidence'], $a['support']]);
                $rows = array_slice($rows, 0, self::MAX_PER_PRODUCT);

                foreach ($rows as $position => $row) {
                    /*
                     * صفٌّ تحريريّ بالمفتاح نفسه يمنع الكتابة ولا يُستبدَل:
                     * القيد فريدٌ على (من، إلى، النوع)، والإنسان أعلى مرتبةً
                     * في سلّم الحقيقة (§٥٩).
                     */
                    $taken = DB::table('product_relationships')
                        ->where('from_product_group_id', $fromGroup)
                        ->where('to_product_group_id', $row['to'])
                        ->where('relation_type', self::RELATION)
                        ->exists();

                    if ($taken) {
                        continue;
                    }

                    DB::table('product_relationships')->insert([
                        'from_product_group_id' => $fromGroup,
                        'to_product_group_id' => $row['to'],
                        'relation_type' => self::RELATION,
                        'position' => $position,
                        'source' => self::SOURCE,
                        'confidence' => $row['confidence'],
                        'note' => 'اشتُريا معًا في '.$row['support'].' طلبًا مدفوعًا',
                        'verified_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $written++;
                }
            }

            return ['written' => $written, 'removed' => $removed, 'pairs' => count($directed)];
        });
    }

    /** أزواج المنتجات في الطلب الواحد، وعدد الطلبات التي جمعتها. */
    private function pairs(int $minSupport): array
    {
        return DB::table('order_items as a')
            ->join('order_items as b', function ($join) {
                $join->on('b.order_id', '=', 'a.order_id')
                    ->whereColumn('a.product_id', '<', 'b.product_id');
            })
            ->join('orders as o', 'o.id', '=', 'a.order_id')
            ->whereIn('o.status', self::PAID_STATUSES)
            ->groupBy('a.product_id', 'b.product_id')
            ->havingRaw('count(distinct a.order_id) >= ?', [$minSupport])
            ->selectRaw('a.product_id as p1, b.product_id as p2, count(distinct a.order_id) as n')
            ->get()
            ->all();
    }

    /** كم طلبًا مدفوعًا ضمّ كلّ منتج — مقام الثقة. */
    private function productOrderTotals(): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::PAID_STATUSES)
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as pid, count(distinct order_items.order_id) as n')
            ->pluck('n', 'pid')
            ->map(intval(...))
            ->all();
    }

    /** جسر المنتج القديم إلى مجموعته؛ منتجٌ خارج الردم لا علاقة له. */
    private function groupByLegacyProduct(): array
    {
        return DB::table('product_groups')
            ->whereNotNull('legacy_product_id')
            ->where('status', 'published')
            ->pluck('id', 'legacy_product_id')
            ->map(intval(...))
            ->all();
    }
}
