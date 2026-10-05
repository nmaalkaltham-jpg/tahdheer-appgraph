<?php

namespace App\Domain\Redirects;

use App\Domain\Privacy\CredentialPaths;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * خدمة التحويلات — الكاتب الوحيد لقواعد التحويل (MIP §7.7).
 *
 * Two guarantees, both enforced at SAVE time rather than at request time:
 *
 *   1. SINGLE HOP. `curl -I` must return exactly one 301. When a new rule's
 *      target is itself a redirect source, the chain is resolved before the row
 *      is written; when existing rules point at the new source, they are
 *      collapsed onto the new final target in the same transaction.
 *   2. TYPED REFUSAL. Self-redirects, closed loops and targets known to be 404
 *      are rejected with {@see RedirectRuleError} and never persisted.
 */
final class RedirectService
{
    /** Depth limit while resolving a chain; beyond this a cycle is assumed. */
    private const MAX_HOPS = 10;

    /**
     * Find the redirect for an incoming path.
     *
     * Exact rules win over wildcards; among wildcards the LONGEST prefix wins,
     * so `/old/deep/*` beats `/old/*` for `/old/deep/x`.
     */
    public function match(string $path): ?RedirectMatch
    {
        $path = $this->normalizePath($path);

        $exact = RedirectRule::query()
            ->where('enabled', true)
            ->where('from_path', $path)
            ->first();

        if ($exact !== null) {
            return new RedirectMatch(
                (int) $exact->id,
                (string) $exact->from_path,
                (string) $exact->to_path,
                (int) $exact->status_code,
            );
        }

        $best = null;
        $bestPrefixLength = -1;

        foreach (RedirectRule::query()->where('enabled', true)->where('from_path', 'like', '%*')->get() as $rule) {
            $prefix = rtrim((string) $rule->from_path, '*');

            // `/old/*` must match `/old/x` and `/old`, but never `/older`.
            $matches = str_starts_with($path, $prefix)
                || rtrim($prefix, '/') === $path;

            if (! $matches || mb_strlen($prefix) <= $bestPrefixLength) {
                continue;
            }

            $best = $rule;
            $bestPrefixLength = mb_strlen($prefix);
        }

        if ($best === null) {
            return null;
        }

        return new RedirectMatch(
            (int) $best->id,
            (string) $best->from_path,
            $this->applyCapture((string) $best->from_path, (string) $best->to_path, $path),
            (int) $best->status_code,
        );
    }

    /**
     * Substitute the wildcard capture into the target.
     *
     * `/old/*` → `/new/*` turns `/old/a/b` into `/new/a/b`. A target without a
     * trailing `*` is a static destination and the capture is dropped.
     */
    private function applyCapture(string $source, string $target, string $path): string
    {
        if (! str_ends_with($source, '*') || ! str_ends_with($target, '*')) {
            return $target;
        }

        $prefix = rtrim($source, '*');
        $capture = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : '';

        return rtrim($target, '*').$capture;
    }

    /**
     * إنشاءُ قاعدةٍ أو تحريرُها — بلا سحقٍ صامت.
     *
     * ─── لماذا نسخةٌ لا «آخر كاتبٍ يفوز» ───
     *
     * كان الحفظ يقرأ الصفّ ثمّ يكتب فوقه. مشغّلان يفتحان القاعدة نفسها،
     * يُصحّح الأوّل الهدف ويُصحّح الثاني الرمز — فيختفي تصحيح الأوّل بلا
     * أثرٍ ولا رسالة. والعطل لا يظهر يومها: يظهر حين تهبط زيارات صفحةٍ
     * مُفهرَسة بعد أسابيع ولا يعرف أحد لماذا.
     *
     * فالنسخة المُرسلة تُقارن بالمحفوظة **تحت قفل الصفّ**، واختلافُها رفضٌ
     * مصنَّف يحمل الحاليّ ليُقارن — لا إعادة محاولةٍ عمياء تُعيد السحق.
     *
     * ─── ولماذا فحص التكرار انتقل إلى داخل المعاملة ───
     *
     * «افحص ثمّ أدرج» خارج المعاملة سباقٌ بعينه: طلبان بالمصدر نفسه يمرّان
     * بالفحص معًا. القيد الفريد على `from_path` هو ما يحسم، فيُلتقط
     * اصطدامُه ويُترجَم إلى الرفض نفسه.
     *
     * @param  array{from_path: string, to_path: string, status_code?: int, enabled?: bool, reason?: string|null}  $attributes
     * @param  int|null  $expectedVersion  نسخة الصفّ كما رآها المحرِّر؛ مطلوبةٌ للتحرير.
     *
     * @throws RedirectRuleError
     */
    public function save(array $attributes, ?int $updatingId = null, ?int $expectedVersion = null): RedirectRule
    {
        $source = $this->normalizeSource($attributes['from_path'] ?? '');
        $target = $this->normalizeTarget($attributes['to_path'] ?? '');
        $status = (int) ($attributes['status_code'] ?? 301);

        if ($status !== 301 && $status !== 302) {
            throw RedirectRuleError::invalidStatus($status);
        }

        if ($this->sameDestination($source, $target)) {
            throw RedirectRuleError::selfRedirect($source);
        }

        try {
            return DB::transaction(function () use ($source, $target, $status, $attributes, $updatingId, $expectedVersion) {
                $rule = $updatingId !== null
                    // القفل أوّلًا: القراءة بلا قفلٍ تُقارن نسخةً قد تتغيّر قبل الكتابة.
                    ? RedirectRule::query()->whereKey($updatingId)->lockForUpdate()->firstOrFail()
                    : new RedirectRule;

                if ($updatingId !== null) {
                    $this->assertFreshVersion($rule, $expectedVersion);
                }

                $duplicate = RedirectRule::query()
                    ->where('from_path', $source)
                    ->when($updatingId !== null, fn ($q) => $q->whereKeyNot($updatingId))
                    ->exists();

                if ($duplicate) {
                    throw RedirectRuleError::duplicateSource($source);
                }

                // تُحلّ السلسلة الآن ليُخزَّن الهدف نهائيًّا — فلا قفزتان لزائر.
                $finalTarget = $this->resolveChain($source, $target, $updatingId);

                if ($this->isKnown404($finalTarget)) {
                    throw RedirectRuleError::targetNotFound($finalTarget);
                }

                $rule->fill([
                    'from_path' => $source,
                    'to_path' => $finalTarget,
                    'status_code' => $status,
                    'enabled' => (bool) ($attributes['enabled'] ?? true),
                    'reason' => $attributes['reason'] ?? null,
                ]);

                if ($rule->exists === false) {
                    $rule->hits = 0;
                    $rule->version = 1;
                } else {
                    $rule->version = (int) $rule->version + 1;
                }

                $rule->save();

                $this->collapseInboundChains($source, $finalTarget, (int) $rule->id);

                return $rule;
            });
        } catch (QueryException $e) {
            /*
             * خسر السباق على المصدر نفسه: القيد الفريد حسمه لا الفحص.
             * الرفض هو رفض التكرار عينه — والمشغّل لا يحتاج أن يعرف أنّ
             * السبب كان تزامنًا.
             */
            if ($this->isUniqueViolation($e)) {
                throw RedirectRuleError::duplicateSource($source);
            }

            throw $e;
        }
    }

    /**
     * النسخة المُرسلة تطابق المحفوظة — وإلّا فتحريرٌ آخر سبق هذا.
     *
     * الغياب مرفوض للتحرير: «بلا نسخة» يعني «اكتب فوق ما لا أعرفه»، وهو
     * السلوك القديم نفسه تحت اسمٍ آخر.
     */
    private function assertFreshVersion(RedirectRule $rule, ?int $expectedVersion): void
    {
        $actual = (int) $rule->version;

        if ($expectedVersion === null || $expectedVersion !== $actual) {
            throw RedirectRuleError::staleVersion($expectedVersion ?? 0, $actual, [
                'id' => (int) $rule->id,
                'fromPath' => (string) $rule->from_path,
                'toPath' => (string) $rule->to_path,
                'statusCode' => (int) $rule->status_code,
                'enabled' => (bool) $rule->enabled,
                'version' => $actual,
            ]);
        }
    }

    /** اصطدامُ قيدٍ فريد في PostgreSQL — رمز الحالة ٢٣٥٠٥. */
    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505';
    }

    /**
     * Walk the existing rules from `$target` to its final destination.
     *
     * @throws RedirectRuleError when the walk returns to the source or cycles
     */
    private function resolveChain(string $source, string $target, ?int $updatingId): string
    {
        $seen = [$source];
        $current = $target;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            if ($this->sameDestination($current, $source)) {
                throw RedirectRuleError::loop([...$seen, $current]);
            }

            if (in_array($current, $seen, true)) {
                throw RedirectRuleError::loop([...$seen, $current]);
            }

            $seen[] = $current;

            $next = RedirectRule::query()
                ->where('enabled', true)
                ->where('from_path', $current)
                ->when($updatingId !== null, fn ($q) => $q->whereKeyNot($updatingId))
                ->first();

            if ($next === null) {
                return $current;
            }

            $current = (string) $next->to_path;
        }

        throw RedirectRuleError::loop($seen);
    }

    /**
     * Any rule that pointed at the new source now points two hops away.
     * Collapse it onto the final target (MIP §7.3.11).
     */
    private function collapseInboundChains(string $source, string $finalTarget, int $ruleId): void
    {
        /*
         * القفل هنا ليس زينة: الطيّ يكتب على صفوفٍ لم يقفلها المستدعي، وهي
         * صفوفٌ قد يُحرّرها مشغّلٌ آخر في اللحظة نفسها. والترتيب بالمعرّف
         * يجعل معاملتين متزامنتين تطلبان الأقفال بالتسلسل نفسه — فلا جمود.
         */
        $inbound = RedirectRule::query()
            ->whereKeyNot($ruleId)
            ->where('to_path', $source)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($inbound as $rule) {
            // الطيّ سيجعلها تحويلًا إلى نفسها: تُعطَّل ولا تُكتب قاعدةٌ ممنوعة.
            $collapsesOntoItself = $this->sameDestination((string) $rule->from_path, $finalTarget);

            $rule->forceFill($collapsesOntoItself
                ? [
                    'enabled' => false,
                    'reason' => 'عُطِّلت تلقائيًا: طيّ السلسلة جعلها تحويلًا إلى نفسها',
                    'version' => (int) $rule->version + 1,
                ]
                : [
                    'to_path' => $finalTarget,
                    'version' => (int) $rule->version + 1,
                ])->save();
        }
    }

    /** True when the target is a path already recorded as a real 404. */
    private function isKnown404(string $target): bool
    {
        if (str_starts_with($target, 'http')) {
            return false;
        }

        return NotFoundHit::query()
            ->where('url_hash', NotFoundHit::hashFor($target))
            ->exists();
    }

    /** Increment the hit counter for a rule that actually fired. */
    public function recordHit(int $ruleId): void
    {
        RedirectRule::query()->whereKey($ruleId)->increment('hits');
    }

    /**
     * Record a 404 as an AGGREGATE: one row per path, counter incremented,
     * first-seen preserved. Never a per-request log row.
     */
    public function recordNotFound(string $path, ?string $referer = null): NotFoundHit
    {
        // Admins read this table; an order token here is a key to someone's order and files.
        $path = $this->normalizePath(CredentialPaths::redactPath($path));
        $referer = CredentialPaths::redactUrl($referer);
        $hash = NotFoundHit::hashFor($path);
        $now = now();

        $hit = NotFoundHit::query()->where('url_hash', $hash)->first();

        if ($hit === null) {
            // `path` is unique in the platform schema; a row may predate the
            // url_hash column, so adopt it instead of colliding.
            $hit = NotFoundHit::query()->where('path', $path)->first();
        }

        if ($hit === null) {
            return NotFoundHit::query()->create([
                'path' => $path,
                'url_hash' => $hash,
                'referrer' => $referer,
                'hits' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        }

        $hit->forceFill([
            'url_hash' => $hash,
            'hits' => (int) $hit->hits + 1,
            'last_seen_at' => $now,
            'first_seen_at' => $hit->first_seen_at ?? $now,
            // Keep the first referer seen; the latest is noisier than the origin.
            'referrer' => $hit->referrer ?? $referer,
        ])->save();

        return $hit;
    }

    /** A source may end in `*`; everything else follows path normalisation. */
    private function normalizeSource(string $value): string
    {
        $value = trim($value);

        if ($value === '' || $value === '*') {
            throw RedirectRuleError::invalidSource('المصدر مطلوب ولا يمكن أن يكون `*` وحده');
        }

        if (str_starts_with($value, 'http')) {
            throw RedirectRuleError::invalidSource('المصدر يجب أن يكون مسارًا داخليًا لا رابطًا مطلقًا');
        }

        if (substr_count($value, '*') > 1 || (str_contains($value, '*') && ! str_ends_with($value, '*'))) {
            throw RedirectRuleError::invalidSource('يُسمح بحرف بدل واحد فقط وفي نهاية المصدر');
        }

        $wildcard = str_ends_with($value, '*');
        $path = $this->normalizePath(rtrim($value, '*'));

        return $wildcard ? rtrim($path, '/').'/*' : $path;
    }

    /** A target is an internal path or an absolute URL. */
    private function normalizeTarget(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw RedirectRuleError::invalidTarget('الهدف مطلوب');
        }

        /*
         * وجهة خارجية: مرفوضة ما لم يكن مضيفها معلَنًا في قائمة السماح.
         *
         * قبولُ أي «http…» يجعل قاعدة تحويل واحدة كافية لإرسال زوّار المتجر
         * إلى موقع آخر بثقة النطاق — إعادة توجيه مفتوحة. ومن يملك اللوحة
         * ليس بالضرورة من يُفترض أن يملك هذه القدرة.
         */
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $value) === 1) {
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

            if ($scheme !== 'http' && $scheme !== 'https') {
                throw RedirectRuleError::invalidTarget("مخطَّط غير مسموح: {$scheme}");
            }

            $host = strtolower((string) parse_url($value, PHP_URL_HOST));
            $allowed = array_map('strtolower', (array) config('seo.redirect_allowed_hosts', []));

            if ($host === '' || ! in_array($host, $allowed, true)) {
                throw RedirectRuleError::invalidTarget(
                    "وجهة خارجية غير مسموحة: {$host}. أضف المضيف إلى seo.redirect_allowed_hosts إن كان مقصودًا.",
                );
            }

            return $value;
        }

        // «//host» بروتوكول-نسبي: يبدأ بشرطة فيبدو داخليًّا ويخرج من الموقع.
        if (str_starts_with($value, '//')) {
            throw RedirectRuleError::invalidTarget('وجهة بروتوكول-نسبية تخرج من الموقع.');
        }

        if (substr_count($value, '*') > 1 || (str_contains($value, '*') && ! str_ends_with($value, '*'))) {
            throw RedirectRuleError::invalidTarget('يُسمح بحرف بدل واحد فقط وفي نهاية الهدف');
        }

        $wildcard = str_ends_with($value, '*');
        $path = $this->normalizePath(rtrim($value, '*'));

        return $wildcard ? rtrim($path, '/').'/*' : $path;
    }

    /** Leading slash, no trailing slash, query string dropped. */
    /** Also the key of `not_found_hits.path` — privacy:scrub-order-tokens merges rows by it. */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        $path = explode('?', $path, 2)[0];
        $path = '/'.ltrim($path, '/');
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /** Compare two destinations ignoring an equivalent wildcard suffix. */
    private function sameDestination(string $a, string $b): bool
    {
        return rtrim(rtrim($a, '*'), '/') === rtrim(rtrim($b, '*'), '/');
    }
}
