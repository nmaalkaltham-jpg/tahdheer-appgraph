<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Orders\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Product;
use App\Models\SearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard counters (MIP §1.3 honesty doctrine + §9.1 analytics truth
 * contract).
 *
 * Everything under `measured` is a real aggregate over a real table. Anything
 * the platform cannot yet observe is listed under `notConfigured` with a
 * reason — it is NEVER emitted as a zero, because a zero is indistinguishable
 * from "we measured and found none".
 */
class DashboardController extends Controller
{
    private const PRODUCT_STATUSES = ['draft', 'published', 'retired'];

    private const ORDER_STATUSES = OrderStatus::ALL;

    private const ENTITLEMENT_STATUSES = ['ACTIVE', 'EXPIRED', 'REVOKED', 'SUSPENDED'];

    public function __invoke(): JsonResponse
    {
        /*
         * يُحسب مرّةً ويُعاد استعماله: المقام نفسه يخدم النسبتين، وإعادة
         * العدّ تعطي رقمين من لحظتين قد يختلفان تحت حملٍ حقيقيّ.
         */
        $searchTotal = SearchQuery::query()->count();
        $searchClicked = SearchQuery::query()->whereNotNull('clicked_product_id')->count();

        $searchStats = [
            'queriesLogged' => $searchTotal,
            'zeroResultQueries' => SearchQuery::query()->where('zero_result', true)->count(),
            'clickedQueries' => $searchClicked,
            'clickThroughRate' => $searchTotal === 0 ? null : round($searchClicked / $searchTotal, 4),
            'meanClickPosition' => $searchClicked === 0
                ? null
                : round((float) SearchQuery::query()->whereNotNull('position')->avg('position'), 2),
        ];

        $paidOrders = Order::query()->whereIn('status', OrderController::PAID_STATUSES);

        return response()->json([
            'generatedAt' => now()->toIso8601String(),
            'measured' => [
                'products' => [
                    'total' => Product::query()->count(),
                    'byStatus' => $this->counts('products', 'status', self::PRODUCT_STATUSES),
                ],
                'orders' => [
                    'total' => Order::query()->count(),
                    'byStatus' => $this->counts('orders', 'status', self::ORDER_STATUSES),
                ],
                'revenue' => [
                    // Commerce truth derives from transactional order rows only
                    // (MIP §9.1) — never from client analytics events.
                    'paidStatuses' => OrderController::PAID_STATUSES,
                    'ordersCount' => (clone $paidOrders)->count(),
                    'totalMinor' => (int) (clone $paidOrders)->sum('total_minor'),
                    'vatMinor' => (int) (clone $paidOrders)->sum('vat_minor'),
                    'currency' => 'SAR',
                ],
                'entitlements' => [
                    'total' => Entitlement::query()->count(),
                    'byStatus' => $this->counts('entitlements', 'status', self::ENTITLEMENT_STATUSES),
                ],
                /*
                 * ─── نقرات البحث صارت مقيسة ───
                 *
                 * كان الإعلان يقول «العمود موجود ولا مسار يسجّل النقرات بعد».
                 * وقد بُني المسار: `SearchClickController` يكتب
                 * `clicked_product_id` و`position` عبر `POST /search/click`،
                 * والمتجر يناديه من `search-api.ts`. فبقاء الإعلان بعد ذلك
                 * يُخفي مقياسًا يعمل.
                 *
                 * والنسبة `null` عند مقامٍ صفريّ لا ٠٪: صفرٌ هنا يُقرأ «بحثٌ
                 * لا يُنقر عليه أبدًا» وهي تهمةٌ للفهرس بينما الحقيقة أنّ لا
                 * بحث جرى.
                 */
                'search' => $searchStats,
            ],
            'notConfigured' => [
                [
                    'key' => 'visitors',
                    'label' => 'الزوار',
                    'state' => 'NOT_CONFIGURED',
                    'reason' => 'منظومة التحليلات الأولى‑الطرف (MIP Part 9) لم تُبنَ بعد — لا يوجد جدول أحداث ولا مصدر زيارات.',
                ],
                [
                    'key' => 'conversionRate',
                    'label' => 'معدّل التحويل',
                    'state' => 'NOT_CONFIGURED',
                    'reason' => 'يتطلّب عدد الزيارات، وهو غير مُقاس بعد؛ النسبة بلا مقام لا تُعرض.',
                ],
            ],
        ]);
    }

    /**
     * One GROUP BY per table, zero-filled across the known enum so a missing
     * bucket is an observed zero (the table WAS queried), not a guess.
     *
     * @param  list<string>  $known
     * @return array<string, int>
     */
    private function counts(string $table, string $column, array $known): array
    {
        $rows = DB::table($table)
            ->selectRaw($column.', count(*) as aggregate')
            ->groupBy($column)
            ->pluck('aggregate', $column);

        $out = array_fill_keys($known, 0);
        foreach ($rows as $key => $count) {
            $out[(string) $key] = (int) $count;
        }

        return $out;
    }
}
