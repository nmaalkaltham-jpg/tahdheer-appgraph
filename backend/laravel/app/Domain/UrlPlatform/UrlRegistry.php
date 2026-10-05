<?php

namespace App\Domain\UrlPlatform;

use App\Domain\Outbox\OutboxWriter;
use App\Domain\Redirects\NotFoundHit;
use App\Domain\Redirects\RedirectRule;
use App\Domain\Redirects\RedirectRuleError;
use App\Domain\Redirects\RedirectService;
use App\Domain\UrlPlatform\Models\UrlHistory;
use App\Domain\UrlPlatform\Models\UrlRegistryEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * سجلّ العناوين — الكاتب الوحيد لهويّة الرابط ودورة حياته.
 *
 * ─── الترتيب الملزم ───
 *
 * `LOCK → VALIDATE → MUTATE → HISTORY → REDIRECT → OUTBOX → COMMIT`
 * في معاملةٍ واحدة: لا يصحّ أن يتغيّر الرابط ولا يُكتب تحويله، ولا أن يُكتب
 * تحويلٌ لرابطٍ لم يتغيّر. والتصادم يُلتقط بقيد القاعدة الجزئيّ لا بفحصٍ
 * قبل الإدراج، فالفحص سباقٌ بعينه.
 *
 * ─── ما لا يفعله ───
 *
 * لا يولّد الرابط من العنوان تلقائيًّا بعد النشر: العنوان يتغيّر كثيرًا
 * والرابط المُفهرَس لا. تغييرُ رابطٍ منشور فعلٌ صريح باسمه وسببه، ويُرفض
 * على المقفل ما لم يُمرَّر تجاوزٌ مُصرَّحٌ به.
 */
final class UrlRegistry
{
    /** أقصى لواحق تُجرَّب قبل الاستسلام — بعدها الاسم مشغولٌ فعلًا لا صدفةً. */
    private const MAX_SUFFIX = 50;

    public function __construct(
        private readonly SlugEngine $slugs,
        private readonly RedirectService $redirects,
        private readonly CanonicalEngine $canonical,
        private readonly OutboxWriter $outbox,
    ) {}

    /* ───────────── الحجز والنشر ───────────── */

    /**
     * يحجز رابطًا لكيان — بالرابط المطلوب إن كان حرًّا، وإلّا بلاحقةٍ حتميّة.
     *
     * @param  array{store?: string, actorId?: int|null, source?: string, indexable?: bool, allowSuffix?: bool, correlationId?: string|null}  $options
     */
    public function claim(string $entityType, int $entityId, string $locale, string $requestedSlug, array $options = []): UrlRegistryEntry
    {
        $store = $options['store'] ?? 'default';
        $allowSuffix = $options['allowSuffix'] ?? true;
        $this->assertSlugAcceptable(RouteBuilders::segment($entityType, $requestedSlug));

        $existing = $this->activeFor($entityType, $entityId, $locale, $store);
        if ($existing !== null) {
            if ($existing->slug === $requestedSlug) {
                return $existing;
            }

            return $this->changeSlug($existing, $requestedSlug, [
                'actorId' => $options['actorId'] ?? null,
                'reason' => 'claim',
                'source' => $options['source'] ?? 'system',
                'correlationId' => $options['correlationId'] ?? null,
            ]);
        }

        $limit = $allowSuffix ? self::MAX_SUFFIX : 1;
        for ($n = 1; $n <= $limit; $n++) {
            $slug = $this->slugs->withSuffix($requestedSlug, $n);
            $path = RouteBuilders::path($entityType, $slug, $locale);
            $this->assertHistoryNotProtected($path, $entityType, $entityId);

            try {
                return DB::transaction(function () use ($entityType, $entityId, $locale, $store, $slug, $path, $options) {
                    $entry = UrlRegistryEntry::query()->create([
                        'entity_type' => $entityType,
                        'entity_id' => $entityId,
                        'store' => $store,
                        'locale' => $locale,
                        'slug' => $slug,
                        'slug_key' => $this->slugs->uniquenessKey(RouteBuilders::segment($entityType, $slug)),
                        'path' => $path,
                        'path_key' => PathPolicy::foldSegment($path),
                        'canonical_url' => $this->canonical->build($path),
                        'route_owner' => RouteBuilders::owner($entityType),
                        'status' => UrlStatus::Reserved->value,
                        'indexable' => $options['indexable'] ?? true,
                    ]);
                    $this->history($entry, 'reserved', null, $slug, $options);

                    return $entry;
                });
            } catch (UniqueConstraintViolationException) {
                // المسار مشغول — اللاحقة التالية. القيد هو الحكم، لا فحصٌ سابق.
                continue;
            }
        }

        throw UrlPlatformError::collision(RouteBuilders::path($entityType, $requestedSlug, $locale));
    }

    public function publish(UrlRegistryEntry $entry, array $options = []): UrlRegistryEntry
    {
        return $this->transition($entry, UrlStatus::Published, 'published', $options + ['stamp' => true]);
    }

    public function archive(UrlRegistryEntry $entry, array $options = []): UrlRegistryEntry
    {
        return $this->transition($entry, UrlStatus::Archived, 'archived', $options);
    }

    public function lock(UrlRegistryEntry $entry, array $options = []): UrlRegistryEntry
    {
        return $this->transition($entry, UrlStatus::Locked, 'locked', $options);
    }

    public function unlock(UrlRegistryEntry $entry, array $options = []): UrlRegistryEntry
    {
        return $this->transition($entry, UrlStatus::Published, 'unlocked', $options);
    }

    /* ───────────── تغيير الرابط ───────────── */

    /**
     * يغيّر رابط كيان: تاريخٌ + تحويل ٣٠١ + حدث — أو لا شيء.
     *
     * @param  array{actorId?: int|null, reason?: string|null, source?: string, force?: bool, expectedVersion?: int|null, correlationId?: string|null}  $options
     */
    public function changeSlug(UrlRegistryEntry $entry, string $newSlug, array $options = []): UrlRegistryEntry
    {
        $this->assertSlugAcceptable(RouteBuilders::segment($entry->entity_type, $newSlug));

        return DB::transaction(function () use ($entry, $newSlug, $options) {
            $row = UrlRegistryEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $this->assertFreshVersion($row, $options['expectedVersion'] ?? null);

            if ($row->status() === UrlStatus::Locked && ! ($options['force'] ?? false)) {
                throw UrlPlatformError::locked($row->path);
            }
            if (! $row->status()->isActive()) {
                throw UrlPlatformError::invalidTransition($row->status(), UrlStatus::Replaced);
            }
            if ($row->slug === $newSlug) {
                throw UrlPlatformError::sameSlug($newSlug);
            }

            $oldPath = $row->path;
            $oldSlug = $row->slug;
            $newPath = RouteBuilders::path($row->entity_type, $newSlug, $row->locale);
            $this->assertHistoryNotProtected($newPath, $row->entity_type, $row->entity_id);

            try {
                $row->fill([
                    'slug' => $newSlug,
                    'slug_key' => $this->slugs->uniquenessKey(RouteBuilders::segment($row->entity_type, $newSlug)),
                    'path' => $newPath,
                    'path_key' => PathPolicy::foldSegment($newPath),
                    'canonical_url' => $this->canonical->build($newPath),
                    'version' => $row->version + 1,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw UrlPlatformError::collision($newPath);
            }

            /*
             * التحويل لا يُكتب للمسودّة: لا زائر ولا محرّك بحثٍ يعرف رابطًا لم
             * يُنشر، وقاعدةٌ له ضجيجٌ في جدول التحويلات.
             */
            $rule = $row->status()->isPublic() ? $this->redirectFor($oldPath, $newPath) : null;

            $history = $this->history($row, 'slug_changed', $oldSlug, $newSlug, $options + ['redirectRuleId' => $rule?->id]);

            $this->emit($row, 'url.slug_changed', [
                'previousPath' => $oldPath,
                'newPath' => $newPath,
                'redirectRuleId' => $rule?->id,
                'historyId' => $history->id,
            ], "url:{$row->id}:v{$row->version}:slug", $options['correlationId'] ?? null);

            if ($rule !== null) {
                $this->emit($row, 'url.redirect_created', [
                    'ruleId' => $rule->id, 'from' => $oldPath, 'to' => $newPath, 'statusCode' => 301,
                ], "url:{$row->id}:v{$row->version}:redirect", $options['correlationId'] ?? null);
            }

            return $row;
        });
    }

    /** الرجوع إلى رابطٍ سابق من التاريخ — تغييرٌ عاديّ بسببٍ مسمّى. */
    public function rollback(UrlRegistryEntry $entry, int $historyId, array $options = []): UrlRegistryEntry
    {
        $history = UrlHistory::query()->whereKey($historyId)->where('registry_id', $entry->id)->first();
        if ($history === null || $history->previous_slug === null) {
            throw UrlPlatformError::notFound('صفّ التاريخ');
        }

        return $this->changeSlug($entry, $history->previous_slug, $options + ['reason' => "rollback:{$historyId}"]);
    }

    /* ───────────── الحذف بسياسة ───────────── */

    /**
     * كلّ حذفٍ يختار مصيرًا صريحًا: أرشيف، استبدال، تحويل، ٤١٠، أو ٤٠٤.
     *
     * @param  array{policy: 'keep'|'replace'|'redirect'|'gone'|'not_found', target?: string|null, actorId?: int|null, reason?: string|null, source?: string, correlationId?: string|null}  $options
     */
    public function retire(UrlRegistryEntry $entry, array $options): UrlRegistryEntry
    {
        $policy = $options['policy'];

        return DB::transaction(function () use ($entry, $policy, $options) {
            $row = UrlRegistryEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            [$status, $rule] = match ($policy) {
                'keep' => [UrlStatus::Archived, null],
                'replace', 'redirect' => [UrlStatus::Replaced, $this->redirectFor($row->path, (string) ($options['target'] ?? ''))],
                'gone' => [UrlStatus::Tombstoned, null],
                'not_found' => [UrlStatus::Deleted, null],
                default => throw UrlPlatformError::invalidSlug("سياسة حذفٍ مجهولة: {$policy}"),
            };

            if (! $row->status()->canTransitionTo($status)) {
                throw UrlPlatformError::invalidTransition($row->status(), $status);
            }

            $row->fill(['status' => $status->value, 'redirect_rule_id' => $rule?->id, 'version' => $row->version + 1])->save();
            $this->history($row, "retired:{$policy}", $row->slug, null, $options + ['redirectRuleId' => $rule?->id]);
            $this->emit($row, 'url.deleted', ['path' => $row->path, 'policy' => $policy, 'redirectRuleId' => $rule?->id], "url:{$row->id}:v{$row->version}:retire", $options['correlationId'] ?? null);

            return $row;
        });
    }

    /* ───────────── الحلّ والشرح ───────────── */

    /**
     * يشرح كيف يُحلّ مسار — خطوةً خطوة (§49).
     *
     * @return array<string, mixed>
     */
    public function resolve(string $rawPath): array
    {
        $steps = [];
        try {
            $path = PathPolicy::normalize($rawPath);
        } catch (UrlPlatformError $e) {
            return ['input' => $rawPath, 'normalized' => null, 'outcome' => 'invalid', 'httpStatus' => 400, 'steps' => [$e->getMessage()], 'entity' => null, 'redirect' => null, 'canonical' => null, 'suggestions' => []];
        }
        if ($path !== $rawPath) {
            $steps[] = "طُبِّع المسار «{$rawPath}» إلى «{$path}»";
        }

        $first = ReservedRoutes::firstSegment($path);
        if (ReservedRoutes::isReserved($first)) {
            $steps[] = "المقطع «{$first}» محجوزٌ للنظام — ليس كيانًا";

            return $this->outcome($rawPath, $path, 'reserved', 200, $steps);
        }

        $entry = $this->activeByPath($path);
        if ($entry !== null) {
            $steps[] = "طابق سجلّ العناوين: {$entry->entity_type} #{$entry->entity_id} · اللغة {$entry->locale} · الحالة {$entry->status}";
            $status = $entry->status()->isPublic() ? 200 : 404;
            if ($status === 404) {
                $steps[] = 'الكيان غير منشور — يُخدم ٤٠٤ للعموم';
            }
            $steps[] = "القانونيّ {$entry->canonical_url}";

            return $this->outcome($rawPath, $path, $status === 200 ? 'entity' : 'unpublished', $status, $steps, $entry);
        }

        $match = $this->redirects->match($path);
        if ($match !== null) {
            $steps[] = "طابق قاعدة تحويل #{$match->ruleId} → {$match->target} · HTTP {$match->statusCode}";
            $target = $this->activeByPath($this->pathOnly($match->target));
            if ($target !== null) {
                $steps[] = "الوجهة كيان: {$target->entity_type} #{$target->entity_id} · القانونيّ {$target->canonical_url}";
            }

            return $this->outcome($rawPath, $path, 'redirect', $match->statusCode, $steps, $target, $match->toArray());
        }

        $historical = UrlHistory::query()->where('previous_path', $path)->orderByDesc('id')->first();
        if ($historical !== null) {
            $current = UrlRegistryEntry::query()->whereKey($historical->registry_id)->first();
            $steps[] = "طابق رابطًا تاريخيًّا (تاريخ #{$historical->id}) لـ{$historical->entity_type} #{$historical->entity_id}";
            if ($current !== null && $current->status() === UrlStatus::Tombstoned) {
                $steps[] = 'الكيان مُزال بسياسة ٤١٠';

                return $this->outcome($rawPath, $path, 'gone', 410, $steps, $current);
            }
            if ($current !== null && $current->status()->isPublic()) {
                $steps[] = "الرابط الحاليّ {$current->path} — لا قاعدة تحويل مسجّلة (تاريخٌ قبل الأتمتة)";

                return $this->outcome($rawPath, $path, 'historical', 301, $steps, $current, ['ruleId' => null, 'source' => $path, 'target' => $current->path, 'statusCode' => 301]);
            }
        }

        $tomb = UrlRegistryEntry::query()->where('path_key', PathPolicy::foldSegment($path))->where('status', UrlStatus::Tombstoned->value)->first();
        if ($tomb !== null) {
            $steps[] = 'المسار لموردٍ مُزال بسياسة ٤١٠';

            return $this->outcome($rawPath, $path, 'gone', 410, $steps, $tomb);
        }

        $steps[] = 'لا كيان ولا تحويل ولا تاريخ — ٤٠٤';

        return $this->outcome($rawPath, $path, 'not_found', 404, $steps, null, null, $this->suggestionsFor($path));
    }

    /**
     * التفتيش الإداريّ: الحلّ + علل القانونيّ + خريطة الموقع + الأسماء المستعارة.
     *
     * @return array<string, mixed>
     */
    public function inspect(string $rawPath): array
    {
        $resolution = $this->resolve($rawPath);
        $entry = $resolution['entity'] !== null ? UrlRegistryEntry::query()->find($resolution['entity']['id']) : null;

        return $resolution + [
            'canonicalIssues' => $entry !== null ? $this->canonical->issues($entry) : [],
            'sitemap' => $entry !== null ? ['included' => $entry->status()->isPublic() && $entry->indexable] : ['included' => false],
            'indexability' => $entry !== null ? ($entry->indexable && $entry->status()->isPublic() ? 'index' : 'noindex') : null,
            'aliases' => $entry !== null
                ? UrlHistory::query()->where('registry_id', $entry->id)->whereNotNull('previous_path')->orderByDesc('id')->limit(50)->get()->map(fn ($h) => $h->toPublicArray())->all()
                : [],
            'notFound' => NotFoundHit::query()->where('path', $resolution['normalized'] ?? '')->first()?->only(['hits', 'first_seen_at', 'last_seen_at']),
            'searchIndex' => ['state' => 'NOT_MEASURED', 'reason' => 'حالة الفهرس تُقرأ من شاشة البحث لا من هنا.'],
            'cache' => ['state' => 'NOT_MEASURED', 'reason' => 'لا مخزنَ نتائج للمسارات بعد.'],
            'internalLinks' => ['state' => 'NOT_MEASURED', 'reason' => 'لا رسم روابط داخليّة بعد.'],
        ];
    }

    /**
     * صحّة المنصّة — عدّادات مقيسة من الجداول، لا درجة مُخترعة.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $byStatus = UrlRegistryEntry::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        $rules = RedirectRule::query()->where('enabled', true)->get(['id', 'from_path', 'to_path']);
        $sources = $rules->pluck('from_path')->flip();
        $chains = $rules->filter(fn ($r) => $sources->has($r->to_path))->values();
        $loops = $chains->filter(function ($r) use ($rules) {
            $seen = [$r->from_path => true];
            $next = $r->to_path;
            for ($i = 0; $i < 10; $i++) {
                $hop = $rules->firstWhere('from_path', $next);
                if ($hop === null) {
                    return false;
                }
                if (isset($seen[$hop->to_path])) {
                    return true;
                }
                $seen[$hop->to_path] = true;
                $next = $hop->to_path;
            }

            return false;
        })->values();
        $to404 = $rules->filter(fn ($r) => NotFoundHit::query()->where('path', $r->to_path)->exists())->values();

        $activeEntries = UrlRegistryEntry::query()->whereIn('status', UrlStatus::activeValues())->get();
        $canonicalIssues = [];
        foreach ($activeEntries as $entry) {
            $issues = $this->canonical->issues($entry);
            if ($issues !== []) {
                $canonicalIssues[] = ['path' => $entry->path, 'issues' => $issues];
            }
        }
        $reservedConflicts = $activeEntries->filter(fn ($e) => ReservedRoutes::isReserved(ReservedRoutes::firstSegment($e->path)))->map(fn ($e) => $e->path)->values()->all();
        $duplicateKeys = UrlRegistryEntry::query()->whereIn('status', UrlStatus::activeValues())
            ->selectRaw('store, locale, path_key, count(*) as n')->groupBy('store', 'locale', 'path_key')->havingRaw('count(*) > 1')->get()->map(fn ($r) => $r->path_key)->all();

        return [
            'registry' => ['total' => array_sum($byStatus), 'byStatus' => $byStatus, 'indexable' => UrlRegistryEntry::query()->where('indexable', true)->whereIn('status', [UrlStatus::Published->value, UrlStatus::Locked->value])->count()],
            'redirects' => [
                'total' => $rules->count(),
                'chains' => $chains->map(fn ($r) => ['id' => $r->id, 'from' => $r->from_path, 'to' => $r->to_path])->all(),
                'loops' => $loops->map(fn ($r) => ['id' => $r->id, 'from' => $r->from_path, 'to' => $r->to_path])->all(),
                'to404' => $to404->map(fn ($r) => ['id' => $r->id, 'from' => $r->from_path, 'to' => $r->to_path])->all(),
            ],
            'canonical' => ['issues' => array_slice($canonicalIssues, 0, 100), 'count' => count($canonicalIssues)],
            'collisions' => ['paths' => $duplicateKeys, 'reserved' => $reservedConflicts],
            'notFound' => [
                'distinct' => NotFoundHit::query()->count(),
                'hits' => (int) NotFoundHit::query()->sum('hits'),
                'top' => NotFoundHit::query()->orderByDesc('hits')->limit(10)->get(['path', 'hits', 'last_seen_at'])->all(),
            ],
            'history' => ['total' => UrlHistory::query()->count(), 'slugChanges' => UrlHistory::query()->where('event', 'slug_changed')->count()],
            'hreflang' => ['state' => 'NOT_MEASURED', 'reason' => 'يُقاس في شاشة SEO الدوليّ من مدقّق hreflang.'],
            'orphans' => ['state' => 'NOT_MEASURED', 'reason' => 'لا رسم روابط داخليّة بعد.'],
        ];
    }

    /* ───────────── قراءات ───────────── */

    public function activeFor(string $entityType, int $entityId, string $locale = 'ar', string $store = 'default'): ?UrlRegistryEntry
    {
        return UrlRegistryEntry::query()
            ->where(['entity_type' => $entityType, 'entity_id' => $entityId, 'locale' => $locale, 'store' => $store])
            ->whereIn('status', UrlStatus::activeValues())
            ->first();
    }

    public function activeByPath(string $path, string $store = 'default'): ?UrlRegistryEntry
    {
        return UrlRegistryEntry::query()
            ->where('store', $store)
            ->where('path_key', PathPolicy::foldSegment($path))
            ->whereIn('status', UrlStatus::activeValues())
            ->first();
    }

    /* ───────────── داخليّ ───────────── */

    private function transition(UrlRegistryEntry $entry, UrlStatus $to, string $event, array $options): UrlRegistryEntry
    {
        return DB::transaction(function () use ($entry, $to, $event, $options) {
            $row = UrlRegistryEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $this->assertFreshVersion($row, $options['expectedVersion'] ?? null);
            if ($row->status() === $to) {
                return $row;
            }
            if (! $row->status()->canTransitionTo($to)) {
                throw UrlPlatformError::invalidTransition($row->status(), $to);
            }
            $attributes = ['status' => $to->value, 'version' => $row->version + 1];
            if (($options['stamp'] ?? false) && $row->published_at === null) {
                $attributes['published_at'] = now();
            }
            $row->fill($attributes)->save();
            $this->history($row, $event, $row->slug, $row->slug, $options);
            $this->emit($row, 'url.'.$event, ['path' => $row->path, 'status' => $to->value], "url:{$row->id}:v{$row->version}:{$event}", $options['correlationId'] ?? null);

            return $row;
        });
    }

    private function redirectFor(string $from, string $to): RedirectRule
    {
        $safe = RedirectSafety::assertSafe($to, (array) config('seo.redirect_allowed_hosts', []));
        /*
         * الوجهة صارت صفحةً حيّة: قاعدةٌ قديمة تنطلق منها (رجوعٌ إلى رابطٍ
         * سابق) تُحذف وإلّا صارت الحلقة `أ → ب → أ`. الحذف لا التعطيل: القاعدة
         * كانت تقول «ب انتقل» وهو لم يعد صحيحًا، والتاريخ في `url_history`.
         */
        RedirectRule::query()->where('from_path', $this->pathOnly($safe))->delete();
        try {
            return $this->redirects->save(['from_path' => $from, 'to_path' => $safe, 'status_code' => 301, 'reason' => 'url-platform']);
        } catch (RedirectRuleError $e) {
            if ($e->errorCode === 'DUPLICATE_SOURCE') {
                // القاعدة قائمة من تغييرٍ سابق — تُحدَّث لا تُكرَّر (خمول).
                $existing = RedirectRule::query()->where('from_path', $from)->firstOrFail();

                return $this->redirects->save(['from_path' => $from, 'to_path' => $safe, 'status_code' => 301, 'reason' => 'url-platform'], $existing->id, $existing->version);
            }
            throw $e;
        }
    }

    private function history(UrlRegistryEntry $row, string $event, ?string $previousSlug, ?string $newSlug, array $options): UrlHistory
    {
        return UrlHistory::query()->create([
            'registry_id' => $row->id,
            'entity_type' => $row->entity_type,
            'entity_id' => $row->entity_id,
            'store' => $row->store,
            'locale' => $row->locale,
            'previous_slug' => $previousSlug,
            'previous_path' => $previousSlug === null ? null : RouteBuilders::path($row->entity_type, $previousSlug, $row->locale),
            'new_slug' => $newSlug,
            'new_path' => $newSlug === null ? null : RouteBuilders::path($row->entity_type, $newSlug, $row->locale),
            'event' => $event,
            'actor_id' => $options['actorId'] ?? null,
            'reason' => isset($options['reason']) ? mb_substr((string) $options['reason'], 0, 500) : null,
            'change_source' => $options['source'] ?? 'admin',
            'redirect_rule_id' => $options['redirectRuleId'] ?? null,
            'approval_state' => $options['approvalState'] ?? 'auto',
            'correlation_id' => $options['correlationId'] ?? null,
            'created_at' => now(),
        ]);
    }

    private function emit(UrlRegistryEntry $row, string $event, array $payload, string $key, ?string $correlationId): void
    {
        $this->outbox->write('url', (string) $row->id, $event, $payload + [
            'entityType' => $row->entity_type, 'entityId' => $row->entity_id, 'locale' => $row->locale, 'path' => $row->path,
        ], $key, $correlationId);
    }

    private function assertSlugAcceptable(string $slug): void
    {
        $errors = $this->slugs->validate($slug);
        if ($errors !== []) {
            if (ReservedRoutes::isReserved($slug)) {
                throw UrlPlatformError::reserved($slug);
            }
            throw UrlPlatformError::invalidSlug(implode('؛ ', $errors));
        }
    }

    /** رابطٌ تاريخيّ لكيانٍ آخر محميٌّ: إعادة استعماله تسرق تحويله وترتيبه. */
    private function assertHistoryNotProtected(string $path, string $entityType, int $entityId): void
    {
        $owner = UrlHistory::query()->where('previous_path', $path)
            ->where(fn ($q) => $q->where('entity_type', '!=', $entityType)->orWhere('entity_id', '!=', $entityId))
            ->orderByDesc('id')->first();
        if ($owner !== null) {
            throw UrlPlatformError::protectedHistory($path, "{$owner->entity_type} #{$owner->entity_id}");
        }
    }

    private function assertFreshVersion(UrlRegistryEntry $row, ?int $expected): void
    {
        if ($expected !== null && $expected !== $row->version) {
            throw UrlPlatformError::staleVersion($expected, $row->version, $row->toPublicArray());
        }
    }

    private function pathOnly(string $target): string
    {
        return (string) (parse_url($target, PHP_URL_PATH) ?? $target);
    }

    /** @return list<array{path: string, entityType: string, entityId: int, score: float, why: string}> */
    private function suggestionsFor(string $path): array
    {
        $segments = explode('/', trim($path, '/'));
        $owner = $segments[0] ?? '';
        $needle = end($segments) ?: '';
        if ($needle === '') {
            return [];
        }
        $candidates = UrlRegistryEntry::query()
            ->where('route_owner', $owner)
            ->whereIn('status', [UrlStatus::Published->value, UrlStatus::Locked->value])
            ->limit(500)->get(['entity_type', 'entity_id', 'slug', 'path']);

        $scored = [];
        foreach ($candidates as $c) {
            similar_text(mb_strtolower($needle), mb_strtolower($c->slug), $pct);
            if ($pct >= 60) {
                $scored[] = ['path' => $c->path, 'entityType' => $c->entity_type, 'entityId' => $c->entity_id, 'score' => round($pct / 100, 2), 'why' => 'رابطٌ مشابه في المالك نفسه'];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, 5);
    }

    /** @return array<string, mixed> */
    private function outcome(string $input, string $path, string $outcome, int $status, array $steps, ?UrlRegistryEntry $entry = null, ?array $redirect = null, array $suggestions = []): array
    {
        return [
            'input' => $input,
            'normalized' => $path,
            'outcome' => $outcome,
            'httpStatus' => $status,
            'steps' => $steps,
            'entity' => $entry?->toPublicArray(),
            'redirect' => $redirect,
            'canonical' => $entry?->canonical_url,
            'suggestions' => $suggestions,
        ];
    }
}
