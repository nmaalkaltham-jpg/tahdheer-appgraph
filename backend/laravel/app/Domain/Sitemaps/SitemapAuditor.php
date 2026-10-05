<?php

namespace App\Domain\Sitemaps;

use App\Domain\Redirects\RedirectService;
use App\Domain\SEO\IndexabilityPolicy;
use App\Models\Product;

/**
 * تدقيق نقاء خريطة الموقع (MIP §7.3.6) — الهدف نظافة ١٠٠٪.
 *
 * Expels any URL that fails IndexabilityPolicy or that is itself a redirect
 * source (a sitemap must list destinations, never sources). Reports coverage
 * as a real percentage of URLs offered vs URLs kept — and reports «غير مقيس»
 * rather than 100% when nothing was offered at all (§7.3.20: unmeasured is
 * never 0 and never a flattering default).
 */
final class SitemapAuditor
{
    public function __construct(
        private readonly SitemapRegistry $registry,
        private readonly LastmodLedger $ledger,
        private readonly IndexabilityPolicy $policy,
        private readonly RedirectService $redirects,
    ) {}

    /**
     * @return array{
     *   providers: list<array<string, mixed>>,
     *   dormant: list<array<string, string>>,
     *   offered: int, kept: int, expelled: int,
     *   coveragePct: float|null, clean: bool
     * }
     */
    public function audit(): array
    {
        $providers = [];
        $dormant = [];
        $offered = 0;
        $kept = 0;
        $expelledAll = [];
        /** @var list<string> كل loc عرضه أي مولِّد — مُدخل المصالحة. */
        $emittedLocs = [];

        foreach ($this->registry->all() as $provider) {
            if (! $provider->isEnabled()) {
                $dormant[] = [
                    'provider' => $provider->name(),
                    'label' => $provider->label(),
                    'reason' => $provider->dormantReason() ?? 'غير مفعَّل',
                ];

                continue;
            }

            $providerOffered = 0;
            $providerKept = 0;
            $expelled = [];

            foreach ($provider->entries() as $entry) {
                $providerOffered++;

                $reason = $this->expulsionReason($entry->loc);

                if ($reason !== null) {
                    $expelled[] = ['loc' => $entry->loc, 'reason' => $reason];

                    continue;
                }

                $providerKept++;
                // ما بقي بعد الطرد هو ما ستحمله الخريطة فعلًا.
                $emittedLocs[] = $entry->loc;
            }

            $offered += $providerOffered;
            $kept += $providerKept;
            $expelledAll = [...$expelledAll, ...$expelled];

            $providers[] = [
                'provider' => $provider->name(),
                'label' => $provider->label(),
                'offered' => $providerOffered,
                'kept' => $providerKept,
                'expelled' => count($expelled),
                'coveragePct' => $providerOffered === 0
                    ? null
                    : round($providerKept / $providerOffered * 100, 2),
                'samples' => array_slice($expelled, 0, 10),
            ];
        }

        /*
         * المصالحة مع القاعدة — الفحص الذي كان غائبًا.
         *
         * `coveragePct` تُقاس من المولِّد إلى نفسه: مقبولة ÷ معروضة. منتجٌ
         * منشور يفشل المولِّد في عرضه أصلًا غير مرئي في هذا الحساب، وتبقى
         * التغطية 100٪ بينما هو غائب عن الخريطة. النقاء ليس التغطية.
         */
        $missing = $this->missingPublicEntities($emittedLocs);

        return [
            'providers' => $providers,
            'dormant' => $dormant,
            'offered' => $offered,
            'kept' => $kept,
            'expelled' => count($expelledAll),
            // null = «غير مقيس»: no URLs were offered, so purity is unknown.
            'coveragePct' => $offered === 0 ? null : round($kept / $offered * 100, 2),
            // كيانات عامّة مؤهَّلة لا تظهر في أي خريطة — يتيمة الاكتشاف.
            'missing' => $missing,
            'clean' => $expelledAll === [] && $missing === [],
        ];
    }

    /**
     * كيانات منشورة ومؤهَّلة للفهرسة لا يعرضها أي مولِّد.
     *
     * تُقارَن المسارات لا الروابط المطلقة: الأصل قد يكون غير مضبوط فتخرج
     * الخريطة بمسارات نسبية، والمقارنة على الأصل تُبلغ عن غياب كاذب.
     *
     * @param  list<string>  $emitted
     * @return list<array{type: string, id: int, path: string}>
     */
    private function missingPublicEntities(array $emitted): array
    {
        $paths = [];
        foreach ($emitted as $loc) {
            $paths[parse_url($loc, PHP_URL_PATH) ?: $loc] = true;
        }

        $missing = [];

        foreach (Product::query()->published()->get(['id', 'slug']) as $product) {
            $path = '/products/'.$product->slug;
            if (isset($paths[$path]) || isset($paths[rawurlencode($path)])) {
                continue;
            }
            // المسار العربي قد يظهر مُرمَّزًا في الخريطة وغير مُرمَّز هنا.
            $encoded = '/products/'.rawurlencode((string) $product->slug);
            if (isset($paths[$encoded])) {
                continue;
            }
            if (! $this->policy->forPath($path)->isEligible()) {
                continue;
            }
            $missing[] = ['type' => 'product', 'id' => (int) $product->id, 'path' => $path];
        }

        return $missing;
    }

    /**
     * Why a URL must be expelled, or null when it is pure.
     *
     * Providers already consult IndexabilityPolicy, so a hit here means a
     * provider regressed — which is precisely what this audit exists to catch.
     */
    private function expulsionReason(string $loc): ?string
    {
        $verdict = $this->policy->forPath($loc);

        if (! $verdict->isEligible()) {
            return $verdict->reason;
        }

        if ($this->redirects->match($loc) !== null) {
            return 'الرابط مصدر تحويل — تُدرَج الوجهة لا المصدر';
        }

        return null;
    }

    /**
     * Ledger drift: entries whose stored lastmod would move on the next build.
     * Read-only — it never commits, so asking does not change the answer.
     *
     * @return list<array{provider: string, loc: string, storedLastmod: ?string}>
     */
    public function pendingLastmodChanges(): array
    {
        $pending = [];

        foreach ($this->registry->enabled() as $provider) {
            $entries = iterator_to_array($provider->entries(), false);

            if ($entries === []) {
                continue;
            }

            $states = SitemapUrlState::query()
                ->whereIn('loc', array_map(static fn (SitemapEntry $e) => $e->loc, $entries))
                ->get()
                ->keyBy('loc');

            foreach ($this->ledger->stamp($provider->name(), $entries, commit: false) as $entry) {
                $state = $states->get($entry->loc);

                if ($state === null || (string) $state->content_hash !== $entry->contentHash) {
                    $pending[] = [
                        'provider' => $provider->name(),
                        'loc' => $entry->loc,
                        'storedLastmod' => $state?->lastmod_at?->toIso8601String(),
                    ];
                }
            }
        }

        return $pending;
    }
}
