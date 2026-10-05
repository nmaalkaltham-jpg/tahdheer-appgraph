/**
 * /shop — the full catalog (MIP §3.2 route matrix «المتجر»).
 *
 * Design: «Tahdheer - Category» — the archive shell, with all FIVE dimensions
 * on the rail (stage · grade · subject · semester · kind) because nothing is
 * fixed by the route here. Data comes from GET /api/v1/search in BROWSE mode: no `q`,
 * filters only, which the contract explicitly allows.
 *
 * Indexation: the clean path is self-canonical and index-eligible once the
 * catalog clears the §7.2 inventory threshold; every filtered/sorted/paged URL
 * canonicalises back to /shop and is noindex,follow.
 */
import type { Metadata } from 'next';
import { fetchSearch, type SearchResponse } from '../../lib/search-api';
import { routes } from '../../lib/routes';
import { Archive, type ArchiveFacetGroup } from '../(catalog)/_components/Archive';
import { CatalogFailure } from '../(catalog)/_components/CatalogFailure';
import { fetchTerms, toFacetOptions } from '../(catalog)/_components/catalog-api';
import {
  catalogHref,
  collectionRobots,
  parsePage,
  parseSort,
  type FacetState,
} from '../(catalog)/_components/query';

const TITLE = 'المتجر';
const DESCRIPTION =
  'كل ملفات التحاضير الحديثة في مكان واحد — رشّح حسب المرحلة والصف والمادة والفصل الدراسي ونوع الملف.';

type Search = Promise<{
  stage?: string;
  grade?: string;
  subject?: string;
  semester?: string;
  kind?: string;
  sort?: string;
  page?: string;
}>;

/** True when the URL carries any facet/sort/page parameter (IDX-FACET). */
function isFiltered(sp: Awaited<Search>): boolean {
  return Boolean(
    sp.stage || sp.grade || sp.subject || sp.semester || sp.kind || sp.sort || sp.page,
  );
}

const CRUMBS = [{ label: 'الرئيسية', href: routes.home }, { label: TITLE }];

export async function generateMetadata({
  searchParams,
}: {
  searchParams: Search;
}): Promise<Metadata> {
  const sp = await searchParams;
  const filtered = isFiltered(sp);
  // A filtered URL is noindex regardless of stock, so it costs no extra call.
  // Same request shape the page itself issues for its first page, so Next's
  // per-request fetch memoisation answers both from one round trip.
  const total = filtered ? 0 : ((await fetchSearch({ page: 1 })).data?.results.total ?? 0);

  return {
    title: TITLE,
    description: DESCRIPTION,
    alternates: { canonical: routes.shop },
    robots: collectionRobots(total, filtered),
  };
}

export default async function ShopPage({ searchParams }: { searchParams: Search }) {
  const sp = await searchParams;
  const page = parsePage(sp.page);
  const sort = parseSort(sp.sort);
  const facets: FacetState = {
    stage: sp.stage,
    grade: sp.grade,
    subject: sp.subject,
    semester: sp.semester,
    kind: sp.kind,
  };

  const [terms, pagesRes] = await Promise.all([
    Promise.all([
      fetchTerms('stages'),
      fetchTerms('grades'),
      fetchTerms('subjects'),
      fetchTerms('semesters'),
      fetchTerms('kinds'),
    ]),
    // Load-more is cumulative: pages 1..n are fetched so the grid holds every
    // product the reader has asked for, and the URL stays shareable.
    Promise.all(
      Array.from({ length: page }, (_, index) => fetchSearch({ ...facets, sort, page: index + 1 })),
    ),
  ]);

  const [stagesRes, gradesRes, subjectsRes, semestersRes, kindsRes] = terms;
  const pages = pagesRes.map((response) => response.data);
  const last = pages.at(-1);

  // One failed page means an INCOMPLETE list — say so instead of quietly
  // rendering a shorter grid that looks like the whole catalog.
  if (pages.some((data) => data === null) || !last) {
    return (
      <CatalogFailure
        crumbs={CRUMBS}
        title={TITLE}
        retryHref={catalogHref(routes.shop, { facets, sort, page })}
      />
    );
  }

  const loaded = pages.filter((data): data is SearchResponse => data !== null);
  const groups: ArchiveFacetGroup[] = [
    { key: 'stage', title: 'المرحلة', options: toFacetOptions(stagesRes.data, 'stage', last) },
    { key: 'grade', title: 'الصف', options: toFacetOptions(gradesRes.data, 'grade', last) },
    { key: 'subject', title: 'المادة', options: last.facets.subjects ?? toFacetOptions(subjectsRes.data, 'subject', last) },
    { key: 'semester', title: 'الفصل الدراسي', options: last.facets.semesters ?? toFacetOptions(semestersRes.data, 'semester', last) },
    { key: 'kind', title: 'نوع الملف', options: last.facets.kinds ?? toFacetOptions(kindsRes.data, 'kind', last) },
  ];

  return (
    <Archive
      basePath={routes.shop}
      crumbs={CRUMBS}
      title={TITLE}
      // «في صفحة واحدة» حُذفت: الصفحة نفسها تحمل مُرقِّمًا يعلن «24 من 26».
      intro="كل ما نُشر في المتجر. رشّح حسب المرحلة أو الصف أو المادة أو الفصل أو نوع الملف، ورتّب النتائج كما يناسبك — كل مرشّح له رابط ثابت يمكنك مشاركته."
      scopeLabel="في المتجر"
      facets={facets}
      sort={sort}
      page={page}
      groups={groups}
      items={loaded.flatMap((response) => response.results.items)}
      total={last.results.total}
      activeSort={last.sort}
      emptyTitle="لا توجد منتجات منشورة بعد"
      emptyText="سيظهر كل ملف هنا فور نشره — لا نملأ الصفحة بعناصر تجريبية."
    />
  );
}
