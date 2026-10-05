// UNIT: FEEDS
// FILE: feeds.ts
// STYLE: none
// PARENT: DERIVE, LIB-ACTIONS, PAGE-ANALYTICS-OS, PAGE-ANALYTICS-OS-ACTIONS
// USES: LIB-ADMIN-API, RESOURCE, _FINDINGS, _KIT
// API: /admin/audit, /admin/crawl, /admin/dashboard, /admin/demand, /admin/products, /admin/search/index +2
// MAP: docs/PROJECT_MAP.md

/**
 * مغذّيات Analytics Pro OS — كل نوع هنا مرآة حرفية لردّ نقطة نهاية قائمة،
 * وكل خطّاف يجمع فقط ما تحتاجه شاشته (لا جلب احتياطيًا).
 *
 * المتحقَّق منه في routes/api-admin.php:
 *   GET /admin/dashboard · GET /admin/crawl (مع previousRun) · GET
 *   /admin/search/queries · GET /admin/search/synonyms · GET
 *   /admin/search/index · GET /admin/demand · GET /admin/audit (audit.read)
 * وما لا مسار له لا يظهر هنا أصلًا — غيابه يُسمّى في الشاشات.
 */

import { useMemo, useState } from 'react';
import type {
  Dashboard,
  Paginated,
  ProductRow,
  SearchIndexStatus,
  SearchQueriesReport,
  SynonymRow,
} from '../../../../../lib/admin-api';
import { useResource, type ResourceState } from '../../../../../components/resource';
import { deriveFindings, type Finding } from '../../../seo/_findings';
import type { LlmsCatalog, RobotsPolicy, SitemapIndex } from '../../../seo/_kit';

/** بيانات المورد إن جهزت وإلا null — لا قيمة افتراضية تسدّ الفراغ. */
export function ready<T>(state: ResourceState<T>): T | null {
  return state.status === 'ready' ? state.data : null;
}

/* ── تقرير الزحف كما يعيده CrawlController::show حرفيًا ────────────── */

export interface OsCrawlTotals {
  pages: number;
  links: number;
  broken: number;
  orphans: number;
  maxDepth: number;
}

export interface OsCrawlReport {
  runId: number;
  startedAt: string | null;
  finishedAt: string | null;
  totals: OsCrawlTotals;
  /** الجولة المكتملة السابقة — null يعني «لا جولة سابقة» لا «لا تغيير». */
  previousRun: {
    runId: number;
    finishedAt: string | null;
    totals: OsCrawlTotals;
    deltas: OsCrawlTotals;
  } | null;
  orphans: { path: string; title: string | null; discovered_from: string }[];
  broken: { path: string; status: number }[];
  deep: { path: string; depth: number; inbound: number }[];
  redirects: { path: string; status: number; redirect_to: string | null }[];
  genericAnchors: { from_path: string; to_path: string; anchor: string }[];
}

export interface OsCrawlPayload {
  data: OsCrawlReport | null;
  message?: string;
}

/* ── الطلب الفائت كما يعيده DemandController::index ────────────────── */

export interface DemandRow {
  id: number;
  stage: string | null;
  grade: string | null;
  subject: string | null;
  semester: string | null;
  kind: string | null;
  note: string | null;
  query: string | null;
  source: string;
  status: string;
  decisionNote: string | null;
  hits: number;
  contactKind: string | null;
  contact: string | null;
  firstSeenAt: string | null;
  lastSeenAt: string | null;
}

export interface DemandReport {
  data: DemandRow[];
  counts: Record<string, number>;
  statuses: string[];
}

/* ── سجلّ التدقيق كما يعيده AuditController::index ─────────────────── */

export interface AuditEntry {
  id: number;
  action: string;
  entityType: string;
  entityId: number;
  actorEmail: string | null;
  before: unknown;
  after: unknown;
  reason: string | null;
  ip: string | null;
  createdAt: string | null;
}

export interface AuditPage {
  data: AuditEntry[];
  meta: { page: number; perPage: number; lastPage: number; total: number };
}

/**
 * وصف الطلب الفائت في سطر واحد للعرض — من حقوله الحقيقية فقط.
 *
 * **المصدر الوحيد.** كانت هذه الدالة مكتوبة مرّتين داخل الغلاف نفسه
 * (هنا وفي `derive.ts`)، وقد **افترقتا فعلًا**: ثلاثة حقول مقابل خمسة،
 * واحتياطيّ `note`→`طلب #id` مقابل «بلا تصنيف». فكان الصفّ الواحد يُطبع
 * بنصّين مختلفين في تبويبَين من شاشة واحدة — وهو بعينه ما حذّر منه توثيق
 * `/demand`: «جدولان لرقم واحد يفترقان أوّل مرة يُحدَّث أحدهما».
 *
 * وُحِّدت على **الاتّحاد** لا التقاطع: خمسة حقول (أوسع تمييزًا)، واحتياطيّ
 * `note` ثمّ `طلب #id` (لا يترك الصفّ بلا هويّة، بخلاف «بلا تصنيف»).
 *
 * وتُعيد النصّ **مجرّدًا من علامات الاقتباس**: التزيين قرار موضع النداء،
 * وإبقاؤه هنا هو ما جعل النسخة القديمة تقتبس الاستعلام دون التقاطع.
 *
 * الوسيط بنيويّ لا اسميّ كي يقبل `DemandRow` و`DemandRowOs` معًا.
 */
export function demandLabel(row: {
  id: number;
  query: string | null;
  subject: string | null;
  grade: string | null;
  stage: string | null;
  semester: string | null;
  kind: string | null;
  note: string | null;
}): string {
  if (row.query) return row.query;
  const parts = [row.subject, row.grade, row.stage, row.semester, row.kind].filter(
    (part): part is string => part !== null && part !== '',
  );
  if (parts.length > 0) return parts.join(' · ');
  return row.note ? row.note.slice(0, 40) : `طلب #${row.id}`;
}

/* ── خطّاف غرفة التحكم ─────────────────────────────────────────────── */

export function useHomeFeeds() {
  const dashboard = useResource<Dashboard>('/admin/dashboard');
  const crawl = useResource<OsCrawlPayload>('/admin/crawl');
  const queries = useResource<SearchQueriesReport>('/admin/search/queries?days=7');
  const demand = useResource<DemandReport>('/admin/demand?status=new');
  // مغذّيات الاشتقاق السبعة نفسها التي تقرأها منصة SEO (deriveFindings).
  const sitemap = useResource<SitemapIndex>('/sitemap/index');
  const llms = useResource<LlmsCatalog>('/llms-data');
  const robots = useResource<RobotsPolicy>('/robots');
  const synonyms = useResource<Paginated<SynonymRow>>('/admin/search/synonyms?perPage=100');
  const products = useResource<Paginated<ProductRow>>('/admin/products?perPage=100');

  const findings: Finding[] = useMemo(
    () =>
      deriveFindings({
        sitemap: ready(sitemap.state),
        llms: ready(llms.state),
        robots: ready(robots.state),
        queries: ready(queries.state),
        synonyms: ready(synonyms.state)?.data ?? null,
        crawl: ready(crawl.state),
        products: ready(products.state),
      }),
    [sitemap.state, llms.state, robots.state, queries.state, synonyms.state, crawl.state, products.state],
  );

  return { dashboard, crawl, queries, demand, sitemap, llms, robots, synonyms, products, findings };
}

export type HomeFeeds = ReturnType<typeof useHomeFeeds>;

/* ── خطّاف مركز الإجراءات ──────────────────────────────────────────── */

export function useActionFeeds() {
  const queries = useResource<SearchQueriesReport>('/admin/search/queries?days=30');
  const synonyms = useResource<Paginated<SynonymRow>>('/admin/search/synonyms?perPage=100');
  const crawl = useResource<OsCrawlPayload>('/admin/crawl');
  const index = useResource<SearchIndexStatus>('/admin/search/index');
  const demand = useResource<DemandReport>('/admin/demand?status=new');

  /*
   * «نُفذ هذا الأسبوع»: نافذة سبعة أيام تُثبَّت عند التركيب كي لا يتغيّر
   * مسار الطلب في كل تصيير. الردّ 403 حالة معلنة في الشاشة لا خطأ صامت —
   * المسار محروس بـ audit.read لا analytics.read (قرار الواجهة الخلفية).
   */
  const [auditFrom] = useState(() => {
    const from = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000);
    return from.toISOString().slice(0, 10);
  });
  const audit = useResource<AuditPage>(`/admin/audit?from=${auditFrom}&perPage=100`);

  return { queries, synonyms, crawl, index, demand, audit, auditFrom };
}

export type ActionFeeds = ReturnType<typeof useActionFeeds>;

/** أفعال سجلّ التدقيق التي تكتبها نقاط نهاية هذا المركز نفسها. */
export const OS_AUDIT_ACTIONS = [
  'search.synonym.created',
  'search.synonym.updated',
  'crawl.requested',
  'search.index.reindex_requested',
  'search.index.rolled_back',
] as const;
