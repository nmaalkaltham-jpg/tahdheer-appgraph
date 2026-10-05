import type { MetadataRoute } from 'next';
import { apiGet } from '../lib/api';
import { canonicalUrl, siteOrigin } from '../lib/seo';

/** GET /api/v1/sitemap/index — which providers exist and which are dormant. */
interface SitemapIndexDto {
  providers: {
    provider: string;
    label: string;
    enabled: boolean;
    /** Arabic reason a dormant provider yields nothing. */
    reason: string | null;
    url: string;
  }[];
}

/** GET /api/v1/sitemap/{provider} — one URL set with truthful lastmod. */
interface SitemapProviderDto {
  provider: string;
  enabled: boolean;
  urls: { loc: string; lastmod: string | null; changefreq?: string; priority?: number }[];
  count: number;
}

/**
 * The sitemap, rendered from the REAL API (MIP §7.3).
 *
 * HONESTY DOCTRINE — every failure mode returns an EMPTY sitemap, never an
 * invented URL list:
 *   · no NEXT_PUBLIC_SITE_URL     ⇒ []  (a sitemap needs absolute URLs)
 *   · API unconfigured/unreachable ⇒ []
 *   · a provider that errors        ⇒ that provider contributes nothing
 *
 * `lastmod` is passed through untouched: the API derives it from a content
 * hash, so a rebuild that changed nothing must not move it here either. This
 * file never calls Date.now() for a lastmod.
 */
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  if (!siteOrigin()) return [];

  const { data: index } = await apiGet<SitemapIndexDto>('/api/v1/sitemap/index');
  if (!index?.providers) return [];

  const enabled = index.providers.filter((provider) => provider.enabled);

  const sets = await Promise.all(
    enabled.map((provider) => apiGet<SitemapProviderDto>(`/api/v1/sitemap/${provider.provider}`)),
  );

  const entries: MetadataRoute.Sitemap = [];

  for (const { data } of sets) {
    for (const url of data?.urls ?? []) {
      entries.push({
        url: canonicalUrl(url.loc),
        // Absent lastmod stays absent — never backfilled with "now".
        ...(url.lastmod ? { lastModified: new Date(url.lastmod) } : {}),
        ...(url.changefreq
          ? { changeFrequency: url.changefreq as MetadataRoute.Sitemap[number]['changeFrequency'] }
          : {}),
        ...(typeof url.priority === 'number' ? { priority: url.priority } : {}),
      });
    }
  }

  return entries;
}
