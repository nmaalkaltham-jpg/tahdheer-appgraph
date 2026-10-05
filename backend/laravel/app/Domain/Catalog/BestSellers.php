<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Orders\OrderStatus;
use App\Models\Product;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * «الأكثر مبيعًا» — المصدر الواحد لترتيب المتجر ولشارة البطاقة.
 *
 * العدّاد هو وحدات البيع على طلباتٍ **حُصِّل مالُها** فعلًا، والقائمة هي
 * أعلى اثني عشر منتجًا منشورًا بهذا العدّاد. منتجٌ بلا بيعٍ مدفوع لا يدخلها،
 * فشارة «الأكثر مبيعًا» لا تُمنح لمنتجٍ جديدٍ ولا تُكتب يدويًّا.
 *
 * مسجَّلٌ كنسخةٍ واحدة لكلّ طلب: القائمة تُحسب مرّةً ثمّ تُسأل عنها كلّ
 * بطاقة، فلا استعلامَ لكلّ بطاقةٍ في القوائم.
 */
final class BestSellers
{
    public const LIMIT = 12;

    /** @var list<int>|null */
    private ?array $ids = null;

    /**
     * وحدات صفّ `products` الخارجيّ المبيعة على طلباتٍ مدفوعة. قائمة الحالات
     * هي النسخة العامّة الوحيدة لـ«المال حُصِّل» — نفسها التي تعدّ بها لوحة
     * الإدارة الإيرادات — فلا يفترق «الأكثر مبيعًا» عن «المدفوع».
     */
    public static function paidUnitsSold(): QueryBuilder
    {
        return DB::table('order_items')
            ->selectRaw('count(*)')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereColumn('order_items.product_id', 'products.id')
            ->whereIn('orders.status', OrderStatus::COLLECTED);
    }

    /** @return list<int> */
    public function ids(): array
    {
        return $this->ids ??= Product::query()->published()
            ->where(self::paidUnitsSold(), '>', 0)
            ->orderByDesc(self::paidUnitsSold())
            ->orderByDesc('published_at')
            ->limit(self::LIMIT)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @var array<int, int> وحدات البيع المدفوعة لكلّ منتج سُئل عنه. */
    private array $units = [];

    /**
     * يُحمّل عدّاد الشراء لقائمة منتجاتٍ باستعلامٍ واحد — تستدعيه مجموعةُ
     * البطاقات قبل تصييرها، فلا يسأل كلّ صفٍّ لنفسه.
     *
     * @param  list<int>  $ids
     */
    public function prime(array $ids): void
    {
        $missing = array_values(array_diff(array_unique($ids), array_keys($this->units)));
        if ($missing === []) {
            return;
        }

        $counts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.product_id', $missing)
            ->whereIn('orders.status', OrderStatus::COLLECTED)
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, count(*) as units')
            ->pluck('units', 'product_id');

        foreach ($missing as $id) {
            $this->units[$id] = (int) ($counts[$id] ?? 0);
        }
    }

    /** وحدات البيع المدفوعة لمنتجٍ واحد — من الذاكرة، وإلّا باستعلامٍ لهذا المنتج. */
    public function unitsFor(int $productId): int
    {
        if (! array_key_exists($productId, $this->units)) {
            $this->prime([$productId]);
        }

        return $this->units[$productId];
    }

    public function contains(int $productId): bool
    {
        return in_array($productId, $this->ids(), true);
    }
}
