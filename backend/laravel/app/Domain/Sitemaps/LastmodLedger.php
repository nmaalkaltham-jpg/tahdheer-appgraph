<?php

namespace App\Domain\Sitemaps;

use Illuminate\Support\Facades\DB;

/**
 * lastmod صادق (MIP §7.3.7): «truthful lastmod = content-hash of
 * title/description/price/availability — NEVER regeneration time».
 *
 * The ledger compares the incoming content hash with the stored one:
 *   · hash unchanged ⇒ the stored lastmod is returned untouched. Rebuilding the
 *     sitemap a thousand times moves nothing.
 *   · hash changed (or first sight) ⇒ lastmod becomes now, because the content
 *     genuinely changed at that moment.
 *
 * This is the difference between a lastmod a crawler can trust and one it
 * learns to ignore.
 */
final class LastmodLedger
{
    /**
     * Stamp a batch of entries with their true lastmod, in place.
     *
     * @param  list<SitemapEntry>  $entries
     * @param  bool  $commit  false = report what lastmod WOULD be without writing
     * @return list<SitemapEntry>
     */
    public function stamp(string $provider, array $entries, bool $commit = true): array
    {
        if ($entries === []) {
            return [];
        }

        $now = now();
        $locs = array_map(static fn (SitemapEntry $e) => $e->loc, $entries);

        $states = SitemapUrlState::query()
            ->whereIn('loc', $locs)
            ->get()
            ->keyBy('loc');

        $inserts = [];
        $updates = [];

        foreach ($entries as $entry) {
            $state = $states->get($entry->loc);

            if ($state !== null && (string) $state->content_hash === $entry->contentHash) {
                // Content is byte-identical to the last build: do not touch it.
                $entry->lastmod = $state->lastmod_at;

                continue;
            }

            $entry->lastmod = $now;

            if ($state === null) {
                $inserts[] = [
                    'provider' => $provider,
                    'loc' => $entry->loc,
                    'content_hash' => $entry->contentHash,
                    'lastmod_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            } else {
                $updates[] = [$state->getKey(), $entry->contentHash];
            }
        }

        if ($commit && $inserts !== []) {
            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table('sitemap_url_states')->insert($chunk);
            }
        }

        if ($commit && $updates !== []) {
            foreach ($updates as [$id, $hash]) {
                SitemapUrlState::query()->whereKey($id)->update([
                    'content_hash' => $hash,
                    'lastmod_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        return $entries;
    }

    /**
     * Drop ledger rows for URLs a provider no longer produces, so a retired URL
     * cannot resurrect an old lastmod if the slug is ever reused.
     *
     * @param  list<string>  $keptLocs
     */
    public function pruneMissing(string $provider, array $keptLocs): int
    {
        return SitemapUrlState::query()
            ->where('provider', $provider)
            ->when($keptLocs !== [], fn ($q) => $q->whereNotIn('loc', $keptLocs))
            ->delete();
    }
}
