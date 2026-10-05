/**
 * Catalog query law — ONE place that turns filter state into a URL and one
 * place that decides whether a catalog URL may be indexed.
 *
 * Facets are LINK-BASED GET filters (crawlable, shareable, JS-free), so the
 * href builder is the only thing standing between a shareable archive and a
 * combinatorial crawl trap: it emits parameters in a FIXED order and drops
 * every default (page 1, relevance sort), so one filter state has exactly one
 * URL.
 */
import type { Metadata } from 'next';
import type { PluralForms } from '@tahdheer/design-system/format';
import type { SearchSort } from '../../../lib/search-api';

/** The five catalog dimensions, in the fixed order they appear in a URL. */
export const FACET_KEYS = ['stage', 'grade', 'subject', 'semester', 'kind'] as const;

export type FacetKey = (typeof FACET_KEYS)[number];

/** Selected slug per dimension; an absent/empty value means «not filtered». */
export type FacetState = Partial<Record<FacetKey, string | undefined>>;

export const SORT_LABELS: Record<SearchSort, string> = {
  relevance: 'الأكثر مطابقة',
  bestselling: 'الأكثر مبيعًا',
  newest: 'الأحدث',
  price_asc: 'السعر تصاعديًا',
  price_desc: 'السعر تنازليًا',
};

export const SORT_KEYS = Object.keys(SORT_LABELS) as SearchSort[];

/** The API's own default sort — omitted from hrefs so it has no second URL. */
export const DEFAULT_SORT: SearchSort = 'relevance';

/**
 * Load-more ceiling. Every step re-fetches pages 1..n server-side, so the
 * depth is bounded to keep one request from fanning out without limit.
 */
export const MAX_PAGES = 10;

/** Arabic agreement for «منتج» — the ONE plural source (design-system qty). */
export const PRODUCT_FORMS: PluralForms = {
  zero: 'لا منتجات',
  one: 'منتج واحد',
  two: 'منتجان',
  few: 'منتجات',
  many: 'منتجًا',
};

export const FILE_FORMS: PluralForms = {
  zero: 'لا ملفات',
  one: 'ملف واحد',
  two: 'ملفان',
  few: 'ملفات',
  many: 'ملفًا',
};

export const OFFER_FORMS: PluralForms = {
  zero: 'لا عروض سارية',
  one: 'عرض واحد ساري',
  two: 'عرضان ساريان',
  few: 'عروض سارية',
  many: 'عرضًا ساريًا',
};

export interface CatalogHrefParams {
  facets?: FacetState;
  sort?: string;
  page?: number;
}

/**
 * Build a catalog URL preserving the given filter state.
 * Defaults are omitted (page 1, relevance) so the clean archive URL is the
 * one canonical address for the unfiltered view.
 */
export function catalogHref(basePath: string, params: CatalogHrefParams = {}): string {
  const qs = new URLSearchParams();
  for (const key of FACET_KEYS) {
    const value = params.facets?.[key];
    if (value) qs.set(key, value);
  }
  if (params.sort && params.sort !== DEFAULT_SORT) qs.set('sort', params.sort);
  if (params.page && params.page > 1) qs.set('page', String(params.page));
  const query = qs.toString();
  return query ? `${basePath}?${query}` : basePath;
}

/** Clamp an incoming ?page= to a real load-more depth. */
export function parsePage(raw: string | undefined): number {
  return Math.min(MAX_PAGES, Math.max(1, Number.parseInt(raw ?? '1', 10) || 1));
}

/**
 * Accept only a sort the API contract knows. A hand-typed `?sort=xyz` is
 * dropped here rather than forwarded, so a bad URL falls back to the default
 * ordering instead of tripping API validation and rendering a failure card.
 */
export function parseSort(raw: string | undefined): SearchSort | undefined {
  return SORT_KEYS.includes(raw as SearchSort) ? (raw as SearchSort) : undefined;
}

/**
 * §7.2 of the indexation matrix, mirrored from the server-side authority
 * (App\Domain\SEO\IndexabilityPolicy::forCollection): a programmatic hub is
 * indexable only once it holds real inventory.
 */
export const HUB_MIN_PRODUCTS = 3;

/**
 * Robots directive for a catalog collection.
 *
 * · a filtered/sorted/paged URL is NEVER indexed — it canonicalises to its
 *   clean parent (IDX-FACET);
 * · a clean hub under the inventory threshold is held back (IDX-THIN-INVENTORY)
 *   rather than published as a near-empty page;
 * · links are always followed, so crawl paths stay intact either way.
 */
export function collectionRobots(count: number, filtered: boolean): Metadata['robots'] {
  return { index: !filtered && count >= HUB_MIN_PRODUCTS, follow: true };
}
