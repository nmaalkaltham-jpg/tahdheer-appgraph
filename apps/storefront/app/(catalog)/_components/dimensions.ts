/**
 * The five catalog dimensions, described ONCE.
 *
 * The store publishes five taxonomies — مرحلة · صف · مادة · فصل دراسي · نوع
 * ملف — and each needs the same pair of surfaces: a hub that lists its terms
 * and an archive per term. Writing that pair five times would ship five
 * dialects of one page; this table is the single description they all render
 * from, so a copy change lands in every dimension at once.
 *
 * `filterKey` is the important column: it names the query parameter the SEARCH
 * contract accepts for this dimension. Hub, archive, facet rail and canonical
 * URL all read it, so the storefront cannot drift from what the API filters on.
 */
import type { PluralForms } from '@tahdheer/design-system/format';
import { decodeSlug, encodeSlug } from '../../../lib/api';
import type { CatalogDimension, TermDto } from './catalog-api';
import type { CatalogIconName } from './icons';
import type { FacetKey } from './query';

export interface DimensionCopy {
  /** Taxonomy endpoint: GET /api/v1/catalog/{taxonomy}. */
  taxonomy: CatalogDimension;
  /** The search query parameter that pins one term of this dimension. */
  filterKey: FacetKey;
  /** Clean hub path — the archives hang beneath it. */
  hubPath: string;
  /** Hub title, and the facet-rail heading for this dimension. */
  hubTitle: string;
  railTitle: string;
  hubDescription: string;
  hubIntro: string;
  /** Agreement forms for the term noun («صف واحد» / «٣ صفوف»). */
  forms: PluralForms;
  icon: CatalogIconName;
  /** Hub state when the taxonomy is genuinely empty. */
  emptyTitle: string;
  emptyText: string;
  /** Archive description when the term carries no editorial intro. */
  archiveDescription: (name: string) => string;
  /** Completes the archive's count phrases, e.g. «في الرياضيات». */
  archiveScope: (name: string) => string;
  archiveEmptyTitle: (name: string) => string;
}

export type HubDimension = 'stages' | 'grades' | 'subjects' | 'semesters' | 'kinds';

export const DIMENSIONS: Record<HubDimension, DimensionCopy> = {
  stages: {
    taxonomy: 'stages',
    filterKey: 'stage',
    hubPath: '/stages',
    hubTitle: 'المراحل التعليمية',
    railTitle: 'المرحلة',
    hubDescription:
      'تصفّح ملفات التحاضير الحديثة حسب المرحلة التعليمية، ثم رشّح داخل المرحلة حسب المادة والفصل الدراسي ونوع الملف.',
    hubIntro:
      'اختر المرحلة لعرض ملفاتها كاملة، ثم ضيّق النتائج داخلها حسب المادة والفصل الدراسي ونوع الملف.',
    forms: {
      zero: 'لا مراحل',
      one: 'مرحلة واحدة',
      two: 'مرحلتان',
      few: 'مراحل',
      many: 'مرحلة',
    },
    icon: 'blocks',
    emptyTitle: 'لا توجد مراحل تعليمية منشورة بعد',
    emptyText: 'ستظهر المراحل هنا فور نشر أول ملف فيها.',
    archiveDescription: (name) =>
      `ملفات ${name} الجاهزة للتحميل — مرتّبة حسب الصف والمادة والفصل الدراسي ونوع الملف.`,
    archiveScope: (name) => `في ${name}`,
    archiveEmptyTitle: (name) => `لا توجد ملفات منشورة في ${name} بعد`,
  },

  grades: {
    taxonomy: 'grades',
    filterKey: 'grade',
    hubPath: '/grades',
    hubTitle: 'الصفوف الدراسية',
    railTitle: 'الصف',
    hubDescription:
      'ملفات التحاضير مرتّبة حسب الصف الدراسي — من الأول الابتدائي إلى الثالث الثانوي، بمناهج الفصول الثلاثة.',
    hubIntro:
      'اختر صفّك لعرض ملفاته كاملة، ثم ضيّق النتائج داخله حسب المادة والفصل الدراسي ونوع الملف.',
    forms: {
      zero: 'لا صفوف',
      one: 'صف واحد',
      two: 'صفّان',
      few: 'صفوف',
      many: 'صفًّا',
    },
    icon: 'cap',
    emptyTitle: 'لا توجد صفوف دراسية منشورة بعد',
    emptyText: 'ستظهر الصفوف هنا فور نشر أول ملف فيها.',
    archiveDescription: (name) =>
      `ملفات ${name} الجاهزة للتحميل — مرتّبة حسب المادة والفصل الدراسي ونوع الملف.`,
    archiveScope: (name) => `في ${name}`,
    archiveEmptyTitle: (name) => `لا توجد ملفات منشورة في ${name} بعد`,
  },

  subjects: {
    taxonomy: 'subjects',
    filterKey: 'subject',
    hubPath: '/subjects',
    hubTitle: 'المواد الدراسية',
    railTitle: 'المادة',
    hubDescription:
      'كل مادة دراسية وملفاتها في صفحة واحدة — تحاضير وعروض وأوراق عمل واختبارات لكل مرحلة وفصل.',
    hubIntro:
      'اختر المادة لعرض ملفاتها كاملة، ثم ضيّق النتائج داخلها حسب المرحلة والصف والفصل الدراسي ونوع الملف.',
    forms: {
      zero: 'لا مواد',
      one: 'مادة واحدة',
      two: 'مادّتان',
      few: 'مواد',
      many: 'مادة',
    },
    icon: 'book',
    emptyTitle: 'لا توجد مواد دراسية منشورة بعد',
    emptyText: 'ستظهر المواد هنا فور نشر أول ملف فيها.',
    archiveDescription: (name) =>
      `ملفات ${name} الجاهزة للتحميل — مرتّبة حسب المرحلة والفصل الدراسي ونوع الملف.`,
    archiveScope: (name) => `في ${name}`,
    archiveEmptyTitle: (name) => `لا توجد ملفات منشورة في ${name} بعد`,
  },

  semesters: {
    taxonomy: 'semesters',
    filterKey: 'semester',
    hubPath: '/semesters',
    hubTitle: 'الفصول الدراسية',
    railTitle: 'الفصل الدراسي',
    hubDescription:
      'ملفات كل فصل دراسي في مكانها — الفصل الأول والثاني والثالث، لكل مرحلة ومادة.',
    hubIntro: 'اختر الفصل الدراسي لعرض ملفاته كاملة، ثم ضيّق النتائج حسب المرحلة والمادة ونوع الملف.',
    forms: {
      zero: 'لا فصول',
      one: 'فصل واحد',
      two: 'فصلان',
      few: 'فصول',
      many: 'فصلًا',
    },
    icon: 'clock',
    emptyTitle: 'لا توجد فصول دراسية منشورة بعد',
    emptyText: 'ستظهر الفصول هنا فور نشر أول ملف فيها.',
    archiveDescription: (name) =>
      `ملفات ${name} الجاهزة للتحميل — مرتّبة حسب المرحلة والمادة ونوع الملف.`,
    archiveScope: (name) => `في ${name}`,
    archiveEmptyTitle: (name) => `لا توجد ملفات منشورة في ${name} بعد`,
  },

  kinds: {
    taxonomy: 'kinds',
    filterKey: 'kind',
    hubPath: '/kinds',
    hubTitle: 'أنواع الملفات',
    railTitle: 'نوع الملف',
    hubDescription:
      'تحاضير وعروض بوربوينت وأوراق عمل واختبارات وتوزيعات ووسائل وحلول — كل نوع وملفاته.',
    hubIntro: 'اختر نوع الملف لعرض ما نُشر منه، ثم ضيّق النتائج حسب المرحلة والصف والمادة والفصل.',
    forms: {
      zero: 'لا أنواع',
      one: 'نوع واحد',
      two: 'نوعان',
      few: 'أنواع',
      many: 'نوعًا',
    },
    icon: 'files',
    emptyTitle: 'لا توجد أنواع ملفات منشورة بعد',
    emptyText: 'ستظهر الأنواع هنا فور نشر أول ملف منها.',
    archiveDescription: (name) =>
      `ملفات ${name} الجاهزة للتحميل — مرتّبة حسب المرحلة والصف والمادة والفصل الدراسي.`,
    archiveScope: (name) => `من نوع ${name}`,
    archiveEmptyTitle: (name) => `لا توجد ملفات من نوع ${name} بعد`,
  },
};

/**
 * The term's path segment, which is NOT always its filter value.
 *
 * Taxonomy slugs are not all path-safe: TWELVE of eighteen subjects and three
 * of seven kinds carry a space («اللغة الإنجليزية», «عرض بوربوينت»). A space
 * in a path segment can only be written `%20`, which would put an escape
 * sequence in the canonical URL and in every sitemap entry — and these are
 * precisely the long-tail URLs the store's visibility rests on.
 *
 * The space becomes a hyphen in the PATH only; the filter keeps the real
 * value, because that is the vocabulary the search engine indexed.
 *
 * The mirror on the server is `App\Domain\SEO\TaxonomyPath::segment()`. The
 * two rules must stay identical, or the sitemap advertises a URL that 404s.
 */
export function termSegment(_dimension: HubDimension, slug: string): string {
  return slug.replace(/ /g, '-');
}

export function termPath(dimension: HubDimension, slug: string): string {
  // `encodeSlug`, not `encodeURIComponent`: Next hands route params through
  // already percent-encoded, and re-encoding one would double-escape it.
  return `${DIMENSIONS[dimension].hubPath}/${encodeSlug(termSegment(dimension, slug))}`;
}

/**
 * Find the term a path segment names — by LOOKUP, never by inverting the
 * transform above. Inverting would turn every hyphen back into a space and
 * quietly break any future kind whose slug legitimately holds one.
 */
export function findTerm(
  dimension: HubDimension,
  terms: TermDto[],
  segment: string,
): TermDto | undefined {
  const decoded = decodeSlug(segment);

  /*
   * Matched on EITHER form: the hyphenated path segment, or the raw slug as
   * the storefront used to emit it. The older `%20` address still resolves —
   * and the archive then redirects it to the one canonical path — so no URL
   * that has already been served turns into a 404.
   */
  return terms.find(
    (term) => termSegment(dimension, term.slug) === decoded || term.slug === decoded,
  );
}
