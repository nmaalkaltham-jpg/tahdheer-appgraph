/**
 * A taxonomy archive: one dimension fixed by the route, the rest on the rail.
 *
 * The mirror of `/shop`, except the term in the path is pinned — so the rail
 * carries the four REMAINING dimensions instead of repeating the one already
 * chosen. Five dimensions render from this one component (see `dimensions.ts`).
 *
 * A slug the taxonomy does not know is a REAL 404 — but only once the taxonomy
 * has actually answered: an API outage renders the retry card instead, because
 * «the server did not answer» must never be served as «this term does not
 * exist».
 */
import type { Metadata } from 'next';
import { notFound, permanentRedirect } from 'next/navigation';
import { decodeSlug } from '../../../lib/api';
import { fetchSearch, type SearchParams, type SearchResponse } from '../../../lib/search-api';
import { routes } from '../../../lib/routes';
import { Archive, type ArchiveFacetGroup } from './Archive';
import { CatalogFailure } from './CatalogFailure';
import type { CatalogHeroArt } from './CatalogHero';
import { STAGE_ART, subjectArt } from '../../../lib/art';
import { stageKeyOf } from '../../../lib/homepage';
import { fetchTerms, toFacetOptions } from './catalog-api';
import { fetchHubs, hubsForTerm } from '../../../lib/curriculum-api';
import { DIMENSIONS, findTerm, termPath, termSegment, type HubDimension } from './dimensions';
import {
  catalogHref,
  collectionRobots,
  FACET_KEYS,
  parsePage,
  parseSort,
  type FacetKey,
  type FacetState,
} from './query';

/**
 * رسم الترويسة للأبعاد التي لها رسمٌ صادق: المرحلة برسمها ولهجتها، والصفّ
 * برسم مرحلته (من اسمه: «الصف الأول المتوسط» ⇒ المتوسطة)، والمادّة برسمها أو
 * حرفها. الفصول والأنواع تبقى على زخرفة الأوراق.
 */
function heroArtFor(dimension: HubDimension, name: string, slug: string): CatalogHeroArt | undefined {
  if (dimension === 'stages' || dimension === 'grades') {
    const stage = stageKeyOf(name) ?? stageKeyOf(slug);
    return { art: stage ? STAGE_ART[stage] : null, name, stage };
  }
  if (dimension === 'subjects') return { art: subjectArt(name) ?? subjectArt(slug), name };
  return undefined;
}

/** Query parameters an archive reads — the four rail dimensions plus paging. */
export type ArchiveSearch = Partial<Record<FacetKey, string>> & {
  sort?: string;
  page?: string;
};

/** Which dimension owns each rail key — used to fetch its labels. */
const DIMENSION_OF: Record<FacetKey, HubDimension> = {
  stage: 'stages',
  grade: 'grades',
  subject: 'subjects',
  semester: 'semesters',
  kind: 'kinds',
};

/** The rail: every dimension except the one the route already pinned. */
function railKeys(dimension: HubDimension): FacetKey[] {
  return FACET_KEYS.filter((key) => key !== DIMENSIONS[dimension].filterKey);
}

function facetStateFrom(dimension: HubDimension, sp: ArchiveSearch): FacetState {
  const state: FacetState = {};
  for (const key of railKeys(dimension)) state[key] = sp[key];

  return state;
}

/** A URL carrying any facet/sort/page parameter is never indexed (IDX-FACET). */
function isFiltered(dimension: HubDimension, sp: ArchiveSearch): boolean {
  return Boolean(railKeys(dimension).some((key) => sp[key]) || sp.sort || sp.page);
}

/** The search request for one page of this archive, with the term pinned. */
function requestFor(
  dimension: HubDimension,
  termSlug: string,
  facets: FacetState,
  sort: string | undefined,
  page: number,
): SearchParams {
  return { ...facets, [DIMENSIONS[dimension].filterKey]: termSlug, sort, page };
}

/**
 * قرارُ الوجود من الصفحة نفسها، قبل أن تُعيد أيّ عنصر.
 *
 * `TermArchive` مكوّنٌ خادميّ غير متزامن يُصيَّر تحت الصفحة، فحدودُه تُبثّ
 * بعد إرسال الرؤوس؛ و`notFound()` بداخله يرسم صفحة «لم نجد» بحالة 200 (404
 * ناعم). أمّا هنا — داخل دالّة الصفحة وقبل أوّل بايت — فالحالة 404 حقيقيّة.
 * نداءُ المفردات يُكرَّر ويُجيب عنه ذاكرةُ `fetch` لكلّ طلب.
 */
export async function assertTermRoute(dimension: HubDimension, segment: string): Promise<void> {
  const termsRes = await fetchTerms(DIMENSIONS[dimension].taxonomy);
  if (termsRes.status === 200 && !findTerm(dimension, termsRes.data, segment)) notFound();
}

export async function termArchiveMetadata(
  dimension: HubDimension,
  segment: string,
  sp: ArchiveSearch,
): Promise<Metadata> {
  const copy = DIMENSIONS[dimension];
  const filtered = isFiltered(dimension, sp);
  const termsRes = await fetchTerms(copy.taxonomy);
  const term = findTerm(dimension, termsRes.data, segment);

  /*
   * 404 حقيقيّ لا ناعم: مع `loading.tsx` يُبثّ الهيكل بحالة 200 قبل أن تصل
   * الصفحة إلى `notFound()`، فالحالة تُقرَّر هنا — قبل أوّل بايت — والصفحة
   * تعيد القرار نفسه احتياطًا. المفردات أجابت ولا تعرف المقطع ⇒ لا صفحة.
   */
  if (termsRes.status === 200 && !term) notFound();

  const name = term?.name ?? copy.hubTitle;

  /*
   * A filtered URL is noindex regardless of stock, so it costs no extra call.
   * Otherwise this is the same request shape the page itself issues for its
   * first page, and Next's per-request fetch memoisation answers both from one
   * round trip.
   */
  const total =
    filtered || !term
      ? 0
      : ((await fetchSearch(requestFor(dimension, term.slug, {}, undefined, 1))).data?.results
          .total ?? 0);

  return {
    title: name,
    description: term?.description_ar ?? copy.archiveDescription(name),
    alternates: { canonical: termPath(dimension, term?.slug ?? segment) },
    robots: collectionRobots(total, filtered),
  };
}

export async function TermArchive({
  dimension,
  segment,
  sp,
}: {
  dimension: HubDimension;
  segment: string;
  sp: ArchiveSearch;
}) {
  const copy = DIMENSIONS[dimension];
  const page = parsePage(sp.page);
  const sort = parseSort(sp.sort);
  const facets = facetStateFrom(dimension, sp);
  const keys = railKeys(dimension);

  const termsRes = await fetchTerms(copy.taxonomy);
  const term = findTerm(dimension, termsRes.data, segment);

  // The taxonomy answered and does not know this slug ⇒ the page does not exist.
  if (termsRes.status === 200 && !term) notFound();

  const name = term?.name ?? copy.hubTitle;
  const basePath = termPath(dimension, term?.slug ?? segment);

  /*
   * ONE address per term. A slug carrying a space used to be served at its
   * `%20` path; that address still resolves, but it redirects here instead of
   * standing beside this page as a second URL for the same archive. Filter
   * state rides along — a redirect that drops `?kind=` sends the reader
   * somewhere they did not ask for.
   */
  if (term && decodeSlug(segment) !== termSegment(dimension, term.slug)) {
    permanentRedirect(catalogHref(basePath, { facets, sort, page }));
  }

  const crumbs = [
    { label: 'الرئيسية', href: routes.home },
    { label: copy.hubTitle, href: copy.hubPath },
    { label: name },
  ];

  const [railTerms, hubsRes, pagesRes] = await Promise.all([
    Promise.all(keys.map((key) => fetchTerms(DIMENSIONS[DIMENSION_OF[key]].taxonomy))),
    fetchHubs(),
    // Load-more is cumulative: pages 1..n are fetched so the grid holds every
    // product the reader asked for, and the URL stays shareable.
    Promise.all(
      Array.from({ length: page }, (_, index) =>
        fetchSearch(requestFor(dimension, term?.slug ?? segment, facets, sort, index + 1)),
      ),
    ),
  ]);

  const pages = pagesRes.map((response) => response.data);
  const last = pages.at(-1);

  // One failed page means an INCOMPLETE list — say so instead of quietly
  // rendering a shorter grid that looks like the whole archive.
  if (termsRes.status !== 200 || pages.some((data) => data === null) || !last) {
    return (
      <CatalogFailure
        crumbs={crumbs}
        title={name}
        retryHref={catalogHref(basePath, { facets, sort, page })}
      />
    );
  }

  const loaded = pages.filter((data): data is SearchResponse => data !== null);
  const groups: ArchiveFacetGroup[] = keys.map((key, index) => ({
    key,
    title: DIMENSIONS[DIMENSION_OF[key]].railTitle,
    options: toFacetOptions(railTerms[index]?.data ?? [], key, last),
  }));

  return (
    <Archive
      basePath={basePath}
      crumbs={crumbs}
      title={name}
      heroArt={heroArtFor(dimension, term?.name ?? name, term?.slug ?? segment)}
      intro={term?.description_ar}
      scopeLabel={copy.archiveScope(name)}
      facets={facets}
      sort={sort}
      page={page}
      groups={groups}
      items={loaded.flatMap((response) => response.results.items)}
      total={last.results.total}
      perPage={last.results.perPage}
      activeSort={last.sort}
      related={{
        title: 'صفحات المنهج',
        /* السجلّ غير متاح ⇒ لا قسم. الغياب لا يُسقط الأرشيف ولا يُخترع رابط. */
        links: hubsForTerm(dimension, term?.slug ?? segment, hubsRes.data?.data ?? []),
      }}
      emptyTitle={copy.archiveEmptyTitle(name)}
      emptyText="نضيف الملفات أولًا بأول — تصفّح بقية الأقسام في الأثناء."
      emptyActions={[
        { label: copy.hubTitle, href: copy.hubPath, primary: true },
        { label: 'تصفّح كل المنتجات', href: routes.shop },
      ]}
      seo={term?.seo_text_ar ? { title: `عن ملفات ${name}`, text: term.seo_text_ar } : undefined}
    />
  );
}
