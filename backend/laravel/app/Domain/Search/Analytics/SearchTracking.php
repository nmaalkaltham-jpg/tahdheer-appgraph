<?php

declare(strict_types=1);

namespace App\Domain\Search\Analytics;

use App\Models\SearchQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ظهورُ النتائج ونقرُها — مرتبطان بحدث البحث لا بالشخص.
 *
 * ─── سياسة الظهور ───
 *
 * منتجٌ أعاده الخادم ليس «ظهورًا». الظهور = بطاقةٌ بلغ نصفُها على الأقلّ مجالَ
 * الرؤية في المتصفّح (IntersectionObserver ≥ 0.5)، تُرسَل دفعاتٍ من صفحة
 * النتائج. ويُقبل من الدفعة ما كان ضمن نتائج هذا البحث فعلًا (`result_ids`)،
 * والرتبةُ تُحسب هنا من موضعه فيها — لا يُصدَّق ما يرسله العميل.
 *
 * ─── عدم التكرار ───
 *
 * قيدٌ فريد على (بحث · منتج · نوع): إعادةُ التصيير، والرجوع، والتحديث، والنقرُ
 * المكرّر كلُّها تُدرج صفًّا موجودًا فتُتجاهل. فالظهور والنقر «مرّةٌ لكلّ بحث».
 *
 * ─── الجلسة ───
 *
 * البحث يُطلب من خادم المتجر (لا كعكات المتصفّح)، فتُربط جلسته هنا عند أوّل
 * إشارةٍ من المتصفّح نفسه — بموافقة القياس وحدها (`SearchIdentity`).
 */
final class SearchTracking
{
    /** نافذةُ قبول الإشارات بعد البحث: رجوعٌ إلى نتائج الأمس ليس ظهورًا جديدًا لبحث اليوم. */
    public const WINDOW_HOURS = 24;

    public const MAX_BATCH = 48;

    public function __construct(private readonly SearchIdentity $identity) {}

    public static function token(int $queryId): string
    {
        return hash_hmac('sha256', 'search-click:'.$queryId, (string) config('app.key'));
    }

    /** البحثُ المُوقَّع نفسه، حديثًا — أو null لحمولةٍ مُلفَّقة أو قديمة. */
    public function verified(int $queryId, string $token): ?SearchQuery
    {
        if (! hash_equals(self::token($queryId), $token)) {
            return null;
        }

        return SearchQuery::query()->whereKey($queryId)->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))->first();
    }

    /**
     * @param  list<int>  $productIds
     * @return int ظهوراتٌ جديدة سُجّلت
     */
    public function impressions(Request $request, SearchQuery $query, array $productIds): int
    {
        $this->bind($request, $query);
        $ranks = $this->ranks($query);
        $rows = [];
        foreach (array_slice(array_values(array_unique($productIds)), 0, self::MAX_BATCH) as $id) {
            if (isset($ranks[$id])) {
                $rows[] = ['search_query_id' => $query->id, 'product_id' => $id, 'kind' => 'impression', 'rank' => $ranks[$id], 'created_at' => now()];
            }
        }

        return $rows === [] ? 0 : DB::table('search_interactions')->insertOrIgnore($rows);
    }

    /** @return bool هل سُجّلت نقرةٌ جديدة */
    public function click(Request $request, SearchQuery $query, int $productId, int $clientPosition, string $destination): bool
    {
        $this->bind($request, $query);
        $ranks = $this->ranks($query);
        $rank = $ranks[$productId] ?? ($query->result_ids === null ? $clientPosition : null);
        if ($rank === null) {
            return false;
        }

        $new = DB::table('search_interactions')->insertOrIgnore([
            'search_query_id' => $query->id, 'product_id' => $productId, 'kind' => 'click',
            'rank' => $rank, 'destination' => $destination, 'created_at' => now(),
        ]) > 0;

        /* النقرةُ نقرةُ ظهور: ما نُقر رُئي، وإن سبق النقرُ إشارةَ الظهور. */
        DB::table('search_interactions')->insertOrIgnore([
            'search_query_id' => $query->id, 'product_id' => $productId, 'kind' => 'impression', 'rank' => $rank, 'created_at' => now(),
        ]);

        return $new;
    }

    /** يربط جلسةَ المتصفّح وفئةَ جهازه بالبحث مرّةً واحدة. */
    public function bind(Request $request, SearchQuery $query): void
    {
        $updates = [];
        if ($query->device === null) {
            $updates['device'] = $this->identity->device($request);
        }
        if ($query->session_hash === null && ($who = $this->identity->of($request)) !== null && $who['session'] !== null) {
            $updates['session_hash'] = $who['session'];
        }
        if ($updates !== []) {
            SearchQuery::query()->whereKey($query->id)->update($updates);
            $query->forceFill($updates);
        }
    }

    /**
     * رتبةُ كلّ منتجٍ في نتائج البحث (١-مبنيّة، مع إزاحة الصفحة).
     *
     * @return array<int, int>
     */
    private function ranks(SearchQuery $query): array
    {
        $ids = is_array($query->result_ids) ? $query->result_ids : [];
        $filters = is_array($query->filters) ? $query->filters : [];
        $offset = max(0, ((int) ($filters['page'] ?? 1) - 1) * (int) ($filters['perPage'] ?? 24));
        $ranks = [];
        foreach (array_values($ids) as $i => $id) {
            $ranks[(int) $id] = $offset + $i + 1;
        }

        return $ranks;
    }
}
