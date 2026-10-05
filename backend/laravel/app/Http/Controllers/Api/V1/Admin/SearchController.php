<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\AdminValidation;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Search\Models\SearchSynonym;
use App\Domain\Search\ZeroResultDiagnosis;
use App\Http\Controllers\Controller;
use App\Models\SearchQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Search Intelligence backend (MIP §6.5–6.6, §8.1).
 *
 * `queries` are pure aggregates over the append-only `search_queries` log —
 * an empty log yields empty lists, never sample rows. Synonym edits keep the
 * `source` tag (seed | learned | manual) untouched, and rollback restores the
 * previous state from the audit log rather than guessing a default.
 */
class SearchController extends Controller
{
    private const STATES = ['active', 'pending', 'negative'];

    /** مصادرُ المرادف كما يقيّدها قيدُ القاعدة (2026_09_15_000036) — قائمةٌ واحدة للتحقّق والعرض. */
    private const SOURCES = ['manual', 'learned', 'seed', 'ai_suggested'];

    public function __construct(private readonly AdminAuditLogger $audit) {}

    public function queries(Request $request, ZeroResultDiagnosis $diagnosis): JsonResponse
    {
        $filters = $request->validate([
            'days' => 'sometimes|integer|min:1|max:365',
            'limit' => 'sometimes|integer|min:1|max:100',
        ], AdminValidation::MESSAGES);

        $days = (int) ($filters['days'] ?? 30);
        $limit = (int) ($filters['limit'] ?? 20);
        $from = now()->subDays($days);

        $base = fn () => SearchQuery::query()->where('created_at', '>=', $from);

        $top = $base()
            ->selectRaw('normalized_query, count(*) as hits, max(created_at) as last_seen_at, sum(case when zero_result then 1 else 0 end) as zero_hits')
            ->groupBy('normalized_query')
            ->orderByDesc('hits')
            ->limit($limit)
            ->get();

        $zero = $base()->where('zero_result', true)
            ->selectRaw('normalized_query, count(*) as hits, max(created_at) as last_seen_at')
            ->groupBy('normalized_query')
            ->orderByDesc('hits')
            ->limit($limit)
            ->get();

        return response()->json([
            'window' => ['days' => $days, 'from' => $from->toIso8601String(), 'to' => now()->toIso8601String()],
            'totals' => [
                'queriesLogged' => $base()->count(),
                'zeroResultQueries' => $base()->where('zero_result', true)->count(),
                'distinctQueries' => $base()->distinct()->count('normalized_query'),
            ],
            /*
             * مقاييس محسوبة من السجلّ نفسه، لا من عدّاد منفصل قد ينحرف.
             * null يعني «لا بيانات كافية للحساب» — لا صفر يُقرأ أداءً سيّئًا:
             * CTR على صفر استعلام ليس 0٪، هو غير معرَّف.
             */
            'metrics' => $this->metrics($base),
            'topQueries' => $top->map(fn ($r) => [
                'query' => $r->normalized_query,
                'count' => (int) $r->hits,
                'zeroResultCount' => (int) $r->zero_hits,
                'lastSeenAt' => $r->last_seen_at === null ? null : Carbon::parse($r->last_seen_at)->toIso8601String(),
            ])->values(),
            /*
             * كلُّ سؤالٍ فاشل بسببه وفعله (`ZeroResultDiagnosis`): أُصلح منذ
             * سُجّل، أو منتجٌ ناقص، أو مرادفٌ ناقص، أو لا صلة بالمتجر.
             */
            'zeroResultQueries' => $zero->map(fn ($r) => [
                'query' => $r->normalized_query,
                'count' => (int) $r->hits,
                'lastSeenAt' => $r->last_seen_at === null ? null : Carbon::parse($r->last_seen_at)->toIso8601String(),
                /*
                 * السؤال الفارغ تصفّحٌ بمرشِّحاتٍ لا يحفظها السجلّ: إعادتُه بلا
                 * مرشِّحات تُعيد الكتالوج كلّه فتقول «أُصلح» كذبًا. لا تشخيص.
                 */
                'diagnosis' => $r->normalized_query === '' ? null : $diagnosis->diagnose((string) $r->normalized_query),
            ])->values(),
        ]);
    }

    /**
     * @param  callable(): Builder  $base
     * @return array{zeroResultRate: float|null, ctr: float|null, meanClickPosition: float|null, clickedQueries: int}
     */
    private function metrics(callable $base): array
    {
        $total = $base()->count();
        $clicked = $base()->whereNotNull('clicked_product_id')->count();
        $meanPosition = $clicked === 0
            ? null
            : (float) $base()->whereNotNull('position')->avg('position');

        return [
            'zeroResultRate' => $total === 0
                ? null
                : round($base()->where('zero_result', true)->count() / $total, 4),
            // النقرات تُروى عبر POST /search/click؛ قبل نشره كل القيم null بحقّ.
            'ctr' => $total === 0 ? null : round($clicked / $total, 4),
            'meanClickPosition' => $meanPosition === null ? null : round($meanPosition, 2),
            'clickedQueries' => $clicked,
        ];
    }

    public function synonyms(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'state' => ['sometimes', Rule::in(self::STATES)],
            'source' => ['sometimes', Rule::in(self::SOURCES)],
            'q' => 'sometimes|string|max:200',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
        ], AdminValidation::MESSAGES);

        $query = SearchSynonym::query();

        if (isset($filters['state'])) {
            $query->where('state', $filters['state']);
        }
        if (isset($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $needle = '%'.trim($filters['q']).'%';
            $query->where(fn ($q) => $q->where('term', 'like', $needle)->orWhere('synonym', 'like', $needle));
        }

        $paginator = $query->orderByDesc('id')->paginate(
            perPage: (int) ($filters['perPage'] ?? 25),
            page: (int) ($filters['page'] ?? 1),
        );

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (SearchSynonym $s) => $this->row($s))->values(),
            'meta' => AdminValidation::meta($paginator),
            'filters' => ['states' => self::STATES, 'sources' => self::SOURCES],
        ]);
    }

    public function storeSynonym(Request $request, AdminUser $actor): JsonResponse
    {
        $data = $request->validate([
            'term' => 'required|string|max:255',
            'synonym' => 'required|string|max:255',
            'direction' => ['sometimes', Rule::in(['one_way', 'two_way'])],
            'scope' => 'sometimes|nullable|string|max:255',
            'state' => ['sometimes', Rule::in(self::STATES)],
        ], AdminValidation::MESSAGES);

        $exists = SearchSynonym::query()
            ->where('term', $data['term'])->where('synonym', $data['synonym'])->exists();

        if ($exists) {
            return response()->json([
                'message' => 'هذا المرادف مسجَّل مسبقًا لنفس المصطلح.',
                'code' => 'SYNONYM_DUPLICATE',
            ], 422);
        }

        // Operator-created rules are always tagged `manual`; the tag is never
        // client-controlled, so learner/seed provenance can't be forged.
        $synonym = SearchSynonym::query()->create([
            'term' => $data['term'],
            'synonym' => $data['synonym'],
            'direction' => $data['direction'] ?? 'one_way',
            'scope' => $data['scope'] ?? null,
            'state' => $data['state'] ?? 'active',
            'source' => 'manual',
        ]);

        $this->audit->log($actor, 'search.synonym.created', 'search_synonym', $synonym->id, null, $this->row($synonym), null, $request);

        return response()->json(['data' => $this->row($synonym)], 201);
    }

    /**
     * Activate / deactivate / rollback. `source` is never accepted from the
     * request, so a seeded or learned rule keeps its provenance forever.
     */
    public function updateSynonym(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $synonym = SearchSynonym::query()->findOrFail($id);

        $data = $request->validate([
            'action' => ['sometimes', Rule::in(['rollback'])],
            'state' => ['sometimes', Rule::in(self::STATES)],
            'direction' => ['sometimes', Rule::in(['one_way', 'two_way'])],
            'scope' => 'sometimes|nullable|string|max:255',
        ], AdminValidation::MESSAGES);

        $before = $this->row($synonym);

        if (($data['action'] ?? null) === 'rollback') {
            $previous = $this->audit->lastFor('search_synonym', $synonym->id, 'search.synonym.updated');

            if ($previous === null || ! is_array($previous->before)) {
                return response()->json([
                    'message' => 'لا توجد نسخة سابقة مسجَّلة لهذا المرادف — لا شيء يمكن استرجاعه.',
                    'code' => 'NO_PREVIOUS_VERSION',
                ], 422);
            }

            $synonym->update([
                'state' => $previous->before['state'],
                'direction' => $previous->before['direction'],
                'scope' => $previous->before['scope'],
            ]);
        } else {
            $changes = [];
            foreach (['state', 'direction', 'scope'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $data[$field];
                }
            }

            if ($changes === []) {
                return response()->json([
                    'message' => 'لم تُرسل أي قيمة قابلة للتعديل (state أو direction أو scope أو action=rollback).',
                    'code' => 'NOTHING_TO_UPDATE',
                ], 422);
            }

            $synonym->update($changes);
        }

        $fresh = $synonym->fresh();
        $this->audit->log(
            $actor,
            ($data['action'] ?? null) === 'rollback' ? 'search.synonym.rolled_back' : 'search.synonym.updated',
            'search_synonym', $synonym->id, $before, $this->row($fresh), null, $request,
        );

        return response()->json(['data' => $this->row($fresh)]);
    }

    private function row(SearchSynonym $synonym): array
    {
        return [
            'id' => $synonym->id,
            'term' => $synonym->term,
            'synonym' => $synonym->synonym,
            'direction' => $synonym->direction,
            'scope' => $synonym->scope,
            'state' => $synonym->state,
            'source' => $synonym->source,
            'evidenceCount' => (int) $synonym->evidence_count,
            'updatedAt' => $synonym->updated_at?->toIso8601String(),
        ];
    }
}
