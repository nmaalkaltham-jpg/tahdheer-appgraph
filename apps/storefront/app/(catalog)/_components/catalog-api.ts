/**
 * Catalog taxonomy access (GET /api/v1/catalog/{dimension}).
 *
 * The dimension endpoints answer with Laravel's `{ data: [...] }` envelope and
 * emit the Arabic label as `name`. Editorial fields (`description_ar`,
 * `seo_text_ar`) and `productsCount` are OPTIONAL by design: a surface
 * renders them only when the API actually sends them, so nothing on screen is
 * ever a stand-in for a field the catalog does not have.
 *
 * Successor: the generated @tahdheer/api-sdk DTO replaces this hand-written one.
 */
import { apiGetCollection } from '../../../lib/api';
import type { SearchResponse } from '../../../lib/search-api';
import type { FacetKey } from './query';

export type CatalogDimension = 'stages' | 'grades' | 'subjects' | 'semesters' | 'kinds';

export interface TermDto {
  slug: string;
  /** Arabic label — the catalog resource emits it as `name`. */
  name: string;
  productsCount?: number;
  /** Editorial intro; rendered only when the API supplies it. */
  description_ar?: string;
  /** Bottom SEO paragraph; rendered only when the API supplies it. */
  seo_text_ar?: string;
}

export function fetchTerms(dimension: CatalogDimension) {
  return apiGetCollection<TermDto>(`/api/v1/catalog/${dimension}`);
}

/**
 * مفردات البُعد خياراتِ ترشيح — والعدد بجانب كلٍّ منها **فقط** حين قاله محرّك البحث.
 *
 * المحرّك يشحن أربعة أبعاد في `facets.taxonomy` وبُعد النوع في `facets.kinds`،
 * وعدده عددُ النتائج تحت المرشّحات الحاليّة. مفردةٌ لم يقل عنها شيئًا تُرسم بلا
 * رقم — لا صفرًا لم يُبلَّغ به. وبلا `key`/`search` تبقى تسمياتٍ مجرّدة.
 */
export function toFacetOptions(
  terms: TermDto[],
  key?: FacetKey,
  search?: SearchResponse | null,
): { slug: string; label: string; count?: number }[] {
  const counts = new Map<string, number>();

  if (key && search) {
    for (const facet of search.facets.taxonomy ?? []) {
      if (facet.key === key) for (const value of facet.values) counts.set(value.value, value.count);
    }
    if (key === 'kind') {
      for (const kind of search.facets.kinds ?? []) {
        if (typeof kind.count === 'number') counts.set(kind.slug, kind.count);
      }
    }
  }

  return terms.map((term) => {
    const count = counts.get(term.slug);

    return { slug: term.slug, label: term.name, ...(typeof count === 'number' ? { count } : {}) };
  });
}
