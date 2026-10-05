<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Redirects\NotFoundHit;
use App\Domain\Redirects\RedirectRule;
use App\Domain\Redirects\RedirectRuleError;
use App\Domain\UrlPlatform\Models\UrlHistory;
use App\Domain\UrlPlatform\Models\UrlRegistryEntry;
use App\Domain\UrlPlatform\SlugEngine;
use App\Domain\UrlPlatform\UrlPlatformError;
use App\Domain\UrlPlatform\UrlRegistry;
use App\Domain\UrlPlatform\UrlStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * منصّة العناوين — القراءة والتفتيش والصحّة وتغيير الروابط.
 *
 * وحدة تحكّمٍ رقيقة: التحقّق والترجمة وإحالة الأخطاء المسمّاة إلى رموز
 * حالة. الثوابت كلّها في `UrlRegistry` تحت قفل الصفّ.
 */
final class UrlPlatformAdminController extends Controller
{
    private const HTTP_FOR = [
        'COLLISION' => 409, 'PROTECTED_HISTORY' => 409, 'LOCKED' => 409, 'STALE_VERSION' => 409,
        'INVALID_TRANSITION' => 409, 'NOT_FOUND' => 404,
    ];

    public function __construct(
        private readonly UrlRegistry $registry,
        private readonly SlugEngine $slugs,
        private readonly AdminAuditLogger $audit,
    ) {}

    /** GET /admin/url/health */
    public function health(): JsonResponse
    {
        return response()->json(['data' => $this->registry->health(), 'generatedAt' => now()->toIso8601String()]);
    }

    /** GET /admin/url/inspect?path= */
    public function inspect(Request $request): JsonResponse
    {
        $validated = $request->validate(['path' => 'required|string|max:2048']);

        return response()->json(['data' => $this->registry->inspect($validated['path'])]);
    }

    /** GET /admin/url/registry — مرشَّحٌ ومُصفَّح. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'sometimes|nullable|string|max:200',
            'status' => 'sometimes|nullable|string|in:'.implode(',', array_map(fn (UrlStatus $s) => $s->value, UrlStatus::cases())),
            'entityType' => 'sometimes|nullable|string|max:40',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:5|max:200',
        ]);

        $query = UrlRegistryEntry::query()->orderByDesc('updated_at');
        if (($validated['q'] ?? '') !== '' && $validated['q'] !== null) {
            $needle = '%'.mb_strtolower($validated['q']).'%';
            $query->where(fn ($q) => $q->where('path_key', 'like', $needle)->orWhere('slug_key', 'like', $needle));
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['entityType'])) {
            $query->where('entity_type', $validated['entityType']);
        }

        $page = $query->paginate((int) ($validated['perPage'] ?? 25), ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json([
            'data' => array_map(fn (UrlRegistryEntry $e) => $e->toPublicArray(), $page->items()),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** GET /admin/url/registry/{id}/history */
    public function history(int $id): JsonResponse
    {
        $entry = UrlRegistryEntry::query()->findOrFail($id);

        return response()->json([
            'data' => [
                'entry' => $entry->toPublicArray(),
                'history' => UrlHistory::query()->where('registry_id', $entry->id)->orderByDesc('id')->limit(200)->get()->map(fn ($h) => $h->toPublicArray())->all(),
            ],
        ]);
    }

    /** POST /admin/url/slug/suggest */
    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:500',
            'locale' => 'sometimes|string|max:12',
            'candidate' => 'sometimes|nullable|string|max:255',
        ]);
        $locale = $validated['locale'] ?? 'ar';
        $candidate = $validated['candidate'] ?? null;

        return response()->json(['data' => [
            'suggestions' => $this->slugs->suggestions($validated['title'], $locale, 4),
            'candidate' => $candidate === null ? null : ['slug' => $candidate, 'errors' => $this->slugs->validate($candidate)],
        ]]);
    }

    /** POST /admin/url/registry/{id}/slug — تغييرٌ صريح بسببه. */
    public function changeSlug(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $validated = $request->validate([
            'slug' => 'required|string|max:255',
            'reason' => 'required|string|max:500',
            'expectedVersion' => 'required|integer|min:1',
            'force' => 'sometimes|boolean',
        ]);
        $entry = UrlRegistryEntry::query()->findOrFail($id);
        $force = (bool) ($validated['force'] ?? false);
        if ($force && ! $actor->allows('slug.override')) {
            return response()->json(['message' => 'تجاوز القفل يحتاج صلاحيّة slug.override.', 'code' => 'FORBIDDEN'], 403);
        }

        try {
            $updated = $this->registry->changeSlug($entry, $validated['slug'], [
                'actorId' => $actor->id, 'reason' => $validated['reason'], 'source' => 'admin',
                'expectedVersion' => (int) $validated['expectedVersion'], 'force' => $force,
                'correlationId' => (string) ($request->header('X-Correlation-Id') ?? ''),
            ]);
        } catch (UrlPlatformError|RedirectRuleError $e) {
            return $this->refuse($e);
        }

        $this->audit->log($actor, 'url.slug.changed', 'url_registry', $entry->id, ['path' => $entry->path], ['path' => $updated->path], $validated['reason'], $request);

        return response()->json(['data' => $updated->toPublicArray()]);
    }

    /** POST /admin/url/registry/{id}/lock · /unlock */
    public function lock(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        return $this->toggleLock($request, $actor, $id, true);
    }

    public function unlock(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        return $this->toggleLock($request, $actor, $id, false);
    }

    /** POST /admin/url/registry/{id}/rollback */
    public function rollback(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $validated = $request->validate(['historyId' => 'required|integer|min:1', 'reason' => 'sometimes|nullable|string|max:500']);
        $entry = UrlRegistryEntry::query()->findOrFail($id);

        try {
            $updated = $this->registry->rollback($entry, (int) $validated['historyId'], ['actorId' => $actor->id, 'source' => 'admin', 'force' => $actor->allows('slug.override')]);
        } catch (UrlPlatformError|RedirectRuleError $e) {
            return $this->refuse($e);
        }

        $this->audit->log($actor, 'url.slug.rolled_back', 'url_registry', $entry->id, ['path' => $entry->path], ['path' => $updated->path], $validated['reason'] ?? null, $request);

        return response()->json(['data' => $updated->toPublicArray()]);
    }

    /**
     * GET /admin/seo/not-found — تقرير ٤٠٤ الذي كانت شاشة الروابط المكسورة
     * تناديه ولا يجيبها أحد.
     */
    public function notFound(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sort' => 'sometimes|string|in:hits,last,first',
            'window' => 'sometimes|string|max:8',
            'page' => 'sometimes|integer|min:1',
            'q' => 'sometimes|nullable|string|max:200',
        ]);
        $sort = $validated['sort'] ?? 'hits';
        $window = $validated['window'] ?? 'all';
        $days = ctype_digit((string) $window) ? (int) $window : null;

        $query = NotFoundHit::query();
        if ($days !== null) {
            $query->where('last_seen_at', '>=', now()->subDays($days));
        }
        if (! empty($validated['q'])) {
            $query->where('path', 'like', '%'.$validated['q'].'%');
        }
        $query->orderByDesc(match ($sort) {
            'last' => 'last_seen_at', 'first' => 'first_seen_at', default => 'hits'
        });

        $page = $query->paginate(50, ['*'], 'page', (int) ($validated['page'] ?? 1));
        $paths = array_map(fn ($h) => $h->path, $page->items());
        $rules = RedirectRule::query()->whereIn('from_path', $paths)->get()->keyBy('from_path');

        return response()->json([
            'data' => array_map(function (NotFoundHit $hit) use ($rules) {
                $rule = $rules->get($hit->path);

                return [
                    'path' => $hit->path,
                    'hits' => (int) $hit->hits,
                    'firstSeenAt' => $hit->first_seen_at?->toIso8601String(),
                    'lastSeenAt' => $hit->last_seen_at?->toIso8601String(),
                    'firstReferrer' => $hit->referrer,
                    'redirectRule' => $rule === null ? null : ['id' => $rule->id, 'fromPath' => $rule->from_path, 'toPath' => $rule->to_path, 'statusCode' => (int) $rule->status_code],
                ];
            }, $page->items()),
            'meta' => [
                'page' => $page->currentPage(), 'perPage' => $page->perPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(),
                'sort' => $sort, 'window' => $days ?? 'all', 'retentionDays' => $this->notFoundRetentionDays(),
            ],
        ]);
    }

    /**
     * مدّة الاحتفاظ من سياسةٍ مكتوبة لا من رقمٍ في سطر الاستدعاء.
     *
     * كانت `config('seo.not_found_retention_days', 90)` — ومفتاحٌ **غير
     * معرَّف في `config/seo.php`**، فالرقم كان الافتراض المثبَّت هنا،
     * والشاشة تَعِد به المشغّل («يُحذف بعد ٩٠ يومًا») ولا كنّاس يمسّ الجدول.
     * الآن يقرأ الصفّ الذي ينفّذه `analytics:prune` نفسه، فالمعروض هو
     * المطبَّق. وبلا سياسةٍ فعّالة لا وعد: `null` تُسقِط السطر من الشاشة.
     */
    private function notFoundRetentionDays(): ?int
    {
        $policy = DB::table('retention_policies')
            ->where('data_class', 'not_found_hits')
            ->where('active', true)
            ->where('on_expiry', 'DELETE')
            ->value('retain_days');

        return $policy === null ? null : (int) $policy;
    }

    private function toggleLock(Request $request, AdminUser $actor, int $id, bool $lock): JsonResponse
    {
        $validated = $request->validate(['expectedVersion' => 'required|integer|min:1', 'reason' => 'sometimes|nullable|string|max:500']);
        $entry = UrlRegistryEntry::query()->findOrFail($id);
        $options = ['actorId' => $actor->id, 'source' => 'admin', 'expectedVersion' => (int) $validated['expectedVersion'], 'reason' => $validated['reason'] ?? null];

        try {
            $updated = $lock ? $this->registry->lock($entry, $options) : $this->registry->unlock($entry, $options);
        } catch (UrlPlatformError $e) {
            return $this->refuse($e);
        }

        $this->audit->log($actor, $lock ? 'url.locked' : 'url.unlocked', 'url_registry', $entry->id, ['status' => $entry->status], ['status' => $updated->status], $validated['reason'] ?? null, $request);

        return response()->json(['data' => $updated->toPublicArray()]);
    }

    private function refuse(UrlPlatformError|RedirectRuleError $e): JsonResponse
    {
        $code = $e->errorCode;

        return response()->json([
            'message' => $e->getMessage(),
            'code' => $code,
            'context' => $e->context,
        ], self::HTTP_FOR[$code] ?? 422);
    }
}
