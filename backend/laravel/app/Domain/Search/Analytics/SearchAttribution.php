<?php

declare(strict_types=1);

namespace App\Domain\Search\Analytics;

use App\Domain\Pricing\PricingService;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * إسنادُ السلّة والطلب إلى البحث — على مستوى المنتج، لا الطلب كلّه.
 *
 * ─── النموذج ───
 *
 *   إضافةٌ إلى السلّة  تُسند إلى آخر بحثٍ **في الجلسة نفسها** (٢٤ ساعة) ظهر فيه
 *                      المنتج — والبحث الذي نُقر فيه يسبق ما ظهر فيه فقط.
 *                      منتجٌ لم يظهر في بحثٍ لهذه الجلسة ⇒ اكتُشف بغير البحث: لا إسناد.
 *   إزالةٌ من السلّة   تُسجَّل على البحث الذي أُسندت إليه آخرُ إضافة، مرّةً لكلّ بحث.
 *   الطلب (checkout)   كلُّ سطرٍ على حدة: يُسند إلى بحث آخر إضافةٍ لهذا المنتج من الزائر
 *                      نفسه (٧ أيّام) لم تتبعها إزالة؛ وإن لم يُضف عبر السلّة (شراءٌ مباشر)
 *                      فإلى آخر نقرةٍ عليه في الجلسة. وقيمتُه قيمةُ السطر.
 *   الدفع والاسترداد   لا يُنسخان: يُقرآن من حالة الطلب واستردادته وقت التحليل.
 *
 * فسلّةٌ فيها منتجان من بحثين مختلفين تُسند كلَّ منتجٍ إلى بحثه، وثالثٌ اكتُشف
 * بالتصفّح لا يُسند إلى شيء — لا «آخر بحث» يأخذ الطلب كلّه.
 *
 * ─── الضوابط ───
 *
 *   · بموافقة القياس وحدها (`SearchIdentity`): الإسناد يربط شراءَ شخصٍ بأسئلته.
 *   · `idempotency_key` فريد: الإضافةُ المكرّرة من البحث نفسه، وإعادةُ إرسال الطلب،
 *     لا تُضاعف شيئًا.
 *   · لا يُسقط شيءٌ هنا سلّةً ولا طلبًا: أيُّ فشلٍ يُسجَّل ويُبتلع.
 */
final class SearchAttribution
{
    public const SESSION_WINDOW_HOURS = 24;

    public const CART_WINDOW_DAYS = 7;

    public function __construct(private readonly SearchIdentity $identity) {}

    public function onCartAdd(Request $request, int $productId): void
    {
        $this->guard('cart_add', function () use ($request, $productId) {
            $who = $this->identity->of($request);
            if ($who === null || $who['session'] === null) {
                return;
            }
            $searchId = $this->searchInSession($who['session'], $productId);
            if ($searchId === null) {
                return;
            }
            $this->write('add_to_cart', $searchId, $productId, $who, null, $this->price($productId), "atc:{$searchId}:{$productId}");
        });
    }

    public function onCartRemove(Request $request, int $productId): void
    {
        $this->guard('cart_remove', function () use ($request, $productId) {
            $who = $this->identity->of($request);
            if ($who === null) {
                return;
            }
            $add = $this->lastAdd($who['visitor'], $productId);
            if ($add !== null) {
                $this->write('remove_from_cart', (int) $add->search_query_id, $productId, $who, null, null, "rfc:{$add->search_query_id}:{$productId}");
            }
        });
    }

    public function onOrder(Order $order, Request $request): void
    {
        $this->guard('order', function () use ($order, $request) {
            $who = $this->identity->of($request);
            if ($who === null) {
                return;
            }
            $lines = DB::table('order_items')->where('order_id', $order->id)
                ->get(['product_id', 'price_snapshot_minor', 'quantity', 'discount_minor']);
            foreach ($lines as $line) {
                $productId = (int) $line->product_id;
                $searchId = $this->lastAdd($who['visitor'], $productId)?->search_query_id
                    ?? ($who['session'] !== null ? $this->searchInSession($who['session'], $productId, clicksOnly: true) : null);
                if ($searchId === null) {
                    continue;
                }
                $value = max(0, (int) $line->price_snapshot_minor * max(1, (int) $line->quantity) - (int) $line->discount_minor);
                $this->write('checkout', (int) $searchId, $productId, $who, (int) $order->id, $value, "co:{$order->id}:{$productId}");
            }
        });
    }

    /** آخرُ بحثٍ في الجلسة ظهر فيه المنتج (النقرُ أوّلًا). */
    private function searchInSession(string $sessionHash, int $productId, bool $clicksOnly = false): ?int
    {
        $row = DB::table('search_interactions as i')
            ->join('search_queries as q', 'q.id', '=', 'i.search_query_id')
            ->where('q.session_hash', $sessionHash)
            ->where('i.product_id', $productId)
            ->where('q.created_at', '>=', now()->subHours(self::SESSION_WINDOW_HOURS))
            ->when($clicksOnly, fn ($q) => $q->where('i.kind', 'click'))
            ->orderByRaw("CASE WHEN i.kind = 'click' THEN 0 ELSE 1 END")
            ->orderByDesc('q.created_at')
            ->orderByDesc('q.id')
            ->first(['q.id']);

        return $row === null ? null : (int) $row->id;
    }

    /** آخرُ إضافةٍ مُسندة لهذا المنتج من الزائر لم تتبعها إزالةٌ من البحث نفسه. */
    private function lastAdd(string $visitorHash, int $productId): ?object
    {
        $add = DB::table('search_attributions')
            ->where('visitor_hash', $visitorHash)
            ->where('product_id', $productId)
            ->where('stage', 'add_to_cart')
            ->where('created_at', '>=', now()->subDays(self::CART_WINDOW_DAYS))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->first(['search_query_id', 'created_at']);
        if ($add === null) {
            return null;
        }
        $removed = DB::table('search_attributions')
            ->where('search_query_id', $add->search_query_id)
            ->where('product_id', $productId)
            ->where('stage', 'remove_from_cart')
            ->where('created_at', '>=', $add->created_at)
            ->exists();

        return $removed ? null : $add;
    }

    /** @param array{visitor: string, session: ?string} $who */
    private function write(string $stage, int $searchId, int $productId, array $who, ?int $orderId, ?int $value, string $key): void
    {
        DB::table('search_attributions')->insertOrIgnore([
            'search_query_id' => $searchId,
            'product_id' => $productId,
            'visitor_hash' => $who['visitor'],
            'session_hash' => $who['session'],
            'stage' => $stage,
            'order_id' => $orderId,
            'value_minor' => $value,
            'idempotency_key' => $key,
            'created_at' => now(),
        ]);
    }

    private function price(int $productId): ?int
    {
        $price = Product::query()->with('price')->find($productId)?->price;

        return $price === null ? null : (PricingService::activeSaleMinor($price) ?? (int) $price->regular_minor);
    }

    private function guard(string $what, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            Log::warning('search.attribution_failed', ['stage' => $what, 'error' => $e->getMessage()]);
        }
    }
}
