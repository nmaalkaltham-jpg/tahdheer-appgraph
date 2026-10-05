// UNIT: LIB-ACTIONS
// FILE: actions.ts
// STYLE: none
// PARENT: PAGE-ANALYTICS-OS-ACTIONS
// USES: FEEDS, LEVEL, LIB-ADMIN-API, LIB-FORMAT
// API: PATCH /admin/demand, PATCH /admin/search/synonyms, POST /admin/crawl/run, POST /admin/search/index/reindex, POST /admin/search/index/rollback
// MAP: docs/PROJECT_MAP.md

/**
 * سجلّ إجراءات مركز الإجراءات — إجراء واحد لكل نقطة نهاية كتابة حقيقية،
 * لا أكثر: POST /admin/search/synonyms و POST /admin/crawl/run و POST
 * /admin/search/index/reindex (بتراجعه الحقيقي rollback) و PATCH
 * /admin/demand/{id}. كل مسار متحقَّق منه في routes/api-admin.php.
 *
 * وما لا نقطة نهاية له يُعرض بطاقةً معطَّلة بسببها المكتوب — لا زرّ يبدو
 * صالحًا ولا يفعل شيئًا، ولا نسبة ثقة لأي بند: لا نموذج يقيسها.
 */

import {
  api,
  type ApiResult,
  type SearchIndexStatus,
  type SearchQueriesReport,
  type SynonymRow,
} from '../../../../../lib/admin-api';
import { count, dateTime } from '../../../../../lib/format';
import type { OsRisk } from './level';
import { demandLabel, type DemandRow, type OsCrawlPayload } from './feeds';

export interface DryRow {
  label: string;
  value: string;
}

export interface OsActionCard {
  id: string;
  kind: 'synonym' | 'crawl' | 'reindex' | 'demand';
  title: string;
  risk: OsRisk;
  /** الأثر وصفًا صادقًا — لا رقم متوقَّع؛ لا نموذج يقدّره. */
  impact: string;
  /** جُمل دليل كل رقم فيها جاء من ردّ خادم محمَّل فعلًا. */
  evidence: string;
  /** شريحة المسار (ltr). */
  urls: string;
  permission: string;
  method: 'POST' | 'PATCH';
  endpoint: string;
  /** صفوف «الصفوف المتأثرة» في المعاينة — من بيانات محمَّلة فقط. */
  affected: DryRow[];
  rollback: { kind: 'real'; note: string } | { kind: 'none'; reason: string };
  /** مدخل حيّ تُكمله الشاشة قبل اكتمال الحمولة. */
  needsInput?: 'synonym' | 'demand-status';
  /** مصطلح الاستعلام الفاشل (بطاقات المرادف). */
  term?: string;
  /** معرّف الطلب الفائت (بطاقة الطلب). */
  demandId?: number;
}

export interface BlockedCard {
  id: string;
  title: string;
  reason: string;
  evidence: string | null;
  linkOut: { label: string; href: string } | null;
}

export interface OsActionsInput {
  queries: SearchQueriesReport | null;
  synonyms: SynonymRow[] | null;
  crawl: OsCrawlPayload | null;
  index: SearchIndexStatus | null;
  demand: DemandRow[] | null;
}

const SERVING_LABELS: Record<SearchIndexStatus['servingPath'], string> = {
  meilisearch: 'Meilisearch',
  postgres_fallback: 'احتياطي Postgres',
};

export function deriveOsActions(input: OsActionsInput): {
  real: OsActionCard[];
  blocked: BlockedCard[];
} {
  const real: OsActionCard[] = [];

  /* ── مرادف لاستعلام بلا نتائج ولا مرادف (أعلى تكرارين) ───────────── */
  if (input.queries && input.synonyms) {
    const mapped = new Set(input.synonyms.map((row) => row.term.trim()));
    const unmapped = input.queries.zeroResultQueries.filter((row) => !mapped.has(row.query.trim()));
    for (const row of unmapped.slice(0, 2)) {
      real.push({
        id: `synonym-${row.query}`,
        kind: 'synonym',
        title: `استعلام بلا نتائج ولا مرادف: «${row.query}»`,
        risk: 'review',
        impact: 'يفتح استرجاعًا حقيقيًا لبحث فاشل متكرر',
        evidence: `تكرّر ${count(row.count)} مرة في نافذة ${count(input.queries.window.days)} يومًا (سجل الاستعلامات) · آخر ظهور ${dateTime(row.lastSeenAt)} · لا مدخل مرادفات يطابقه بين ${count(input.synonyms.length)} مدخلًا محمَّلًا.`,
        urls: 'POST /api/v1/admin/search/synonyms',
        permission: 'search.synonyms.manage',
        method: 'POST',
        endpoint: '/admin/search/synonyms',
        affected: [
          { label: 'الاستعلام الفاشل', value: row.query },
          { label: 'مرات الفشل في النافذة', value: count(row.count) },
          { label: 'آخر ظهور', value: dateTime(row.lastSeenAt) },
        ],
        rollback: {
          kind: 'real',
          note: 'إيقاف المرادف: PATCH /admin/search/synonyms/{id} بحالة «موقوف»',
        },
        needsInput: 'synonym',
        term: row.query,
      });
    }
  }

  /* ── جولة زحف تحقّق ──────────────────────────────────────────────── */
  {
    const report = input.crawl?.data ?? null;
    const previous = report?.previousRun ?? null;
    real.push({
      id: 'crawl-run',
      kind: 'crawl',
      title: report === null ? 'أول جولة زحف للتحقق' : 'جولة زحف تحقّق جديدة',
      risk: 'safe',
      impact: 'قراءة لا كتابة — يعيد قياس الروابط جولةً مقابل جولة',
      evidence:
        report === null
          ? (input.crawl?.message ?? 'لم تُشغَّل جولة زحف بعد.')
          : `آخر جولة #${count(report.runId)} انتهت ${dateTime(report.finishedAt)}: ${count(report.totals.pages)} صفحة · ${count(report.totals.broken)} مكسورًا · ${count(report.totals.orphans)} يتيمة${
              previous === null
                ? ' · لا جولة سابقة تُقارن بها'
                : ` · فرق المكسور عن الجولة السابقة ${previous.deltas.broken >= 0 ? '+' : ''}${count(previous.deltas.broken)}`
            }.`,
      urls: 'POST /api/v1/admin/crawl/run',
      permission: 'search.index.manage',
      method: 'POST',
      endpoint: '/admin/crawl/run',
      affected:
        report === null
          ? [{ label: 'جولة مرجعية', value: 'لا توجد — هذه ستكون الأولى' }]
          : [
              { label: 'الجولة المرجعية', value: `#${count(report.runId)}` },
              { label: 'صفحاتها', value: count(report.totals.pages) },
              { label: 'مكسورها', value: count(report.totals.broken) },
            ],
      rollback: { kind: 'none', reason: 'الزحف قراءة — لا شيء يُتراجع عنه' },
    });
  }

  /* ── إعادة بناء فهرس البحث (بتراجع حقيقي) ────────────────────────── */
  if (input.index) {
    const index = input.index;
    real.push({
      id: 'reindex',
      kind: 'reindex',
      title: 'إعادة بناء فهرس البحث',
      risk: 'review',
      impact: 'يبني جيلًا جديدًا — والتراجع إلى الجيل السابق نقطة نهاية حقيقية',
      evidence: `الجيل الحالي ${count(index.generation)} · الحالة «${index.status}» · آخر بناء ${dateTime(index.lastBuiltAt)} · مسار الخدمة ${SERVING_LABELS[index.servingPath]} · ${count(index.publishedProducts)} منتجًا منشورًا يدخل الفهرس.`,
      urls: 'POST /api/v1/admin/search/index/reindex',
      permission: 'search.index.manage',
      method: 'POST',
      endpoint: '/admin/search/index/reindex',
      affected: [
        { label: 'الجيل قبل التنفيذ', value: count(index.generation) },
        { label: 'مسار الخدمة قبل التنفيذ', value: SERVING_LABELS[index.servingPath] },
        { label: 'حالة المحرك', value: index.engineHealthy ? 'سليم' : 'متعطّل' },
      ],
      rollback: { kind: 'real', note: 'POST /admin/search/index/rollback — عودة للجيل السابق' },
    });
  }

  /* ── قرار في أعلى طلب فائت ───────────────────────────────────────── */
  const top = input.demand?.[0] ?? null;
  if (top) {
    real.push({
      id: `demand-${top.id}`,
      kind: 'demand',
      title: `قرار في أعلى طلب فائت: «${demandLabel(top)}»`,
      risk: 'safe',
      impact: 'قرار حالة قابل للعكس — لا يمسّ الكتالوج',
      evidence: `${count(top.hits)} طلبًا متطابقًا (المصدر: ${top.source === 'zero_result' ? 'بحث بلا نتائج' : 'نموذج الطلب'}) · آخر ظهور ${dateTime(top.lastSeenAt)} · الحالة الحالية «جديد».`,
      urls: `PATCH /api/v1/admin/demand/${top.id}`,
      permission: 'products.create',
      method: 'PATCH',
      endpoint: `/admin/demand/${top.id}`,
      affected: [
        { label: 'الطلب', value: `«${demandLabel(top)}»` },
        { label: 'عدد الطلبات المتطابقة', value: count(top.hits) },
        { label: 'الحالة قبل التنفيذ', value: 'new' },
      ],
      rollback: { kind: 'real', note: 'PATCH نفسه يعيد الحالة إلى «جديد»' },
      needsInput: 'demand-status',
      demandId: top.id,
    });
  }

  /* ── ما لا نقطة نهاية له — معطَّل بسبب مكتوب ─────────────────────── */
  const redirects = input.crawl?.data?.redirects ?? null;
  const blocked: BlockedCard[] = [
    {
      id: 'alt-text',
      title: 'توليد النص البديل لصور المنتجات',
      reason: 'لا نقطة نهاية تكتب النص البديل',
      evidence: 'الزاحف لا يفحص سمات alt أصلًا — لا عدد صور ناقصة يُزعم.',
      linkOut: null,
    },
    {
      id: 'redirect-chains',
      title: 'تسوية سلاسل التحويل',
      reason:
        'قواعد التحويل للقراءة فقط (GET /redirects/rules) — لا مسار كتابة يسوّي سلسلة.',
      evidence:
        redirects === null
          ? 'عدد التحويلات غير مقيس قبل أول جولة زحف.'
          : `آخر جولة رصدت ${count(redirects.length)} صفحة تحويل (3xx، بسقف 50 في الردّ) — الرصد حقيقي والتسوية بلا نقطة نهاية.`,
      linkOut: { label: 'افتح خريطة الروابط', href: '/crawl' },
    },
    {
      id: 'bulk-meta',
      title: 'تعديل العناوين وcanonical لصفحات عشوائية',
      reason:
        'لا إجراء جماعي: الموجود PATCH /admin/products/{id}/seo لكل منتج على حدة من صفحته.',
      evidence: null,
      linkOut: { label: 'افتح استوديو المنتج', href: '/studio/catalog' },
    },
    {
      id: 'content-snapshots',
      title: 'لقطات تراجع لإجراءات المحتوى',
      reason:
        'لا مخزن لقطات في النظام (حدود التشغيل المعلنة في الحاكمية) — تراجع الفهرس وحده حقيقي.',
      evidence: null,
      linkOut: { label: 'افتح الحاكمية الذاتية', href: '/growth' },
    },
  ];

  return { real, blocked };
}

/* ── التنفيذ: نداءات النقاط الحقيقية نفسها ────────────────────────── */

export function createSynonym(term: string, synonym: string): Promise<ApiResult<{ data: SynonymRow }>> {
  return api.post<{ data: SynonymRow }>('/admin/search/synonyms', {
    term,
    synonym,
    direction: 'one_way',
  });
}

/** التراجع الحقيقي عن مرادف أُنشئ للتوّ: حالة «موقوف» (negative). */
export function stopSynonym(id: number): Promise<ApiResult<{ data: SynonymRow }>> {
  return api.patch<{ data: SynonymRow }>(`/admin/search/synonyms/${id}`, { state: 'negative' });
}

export function runCrawl(pages: number): Promise<ApiResult<{ message: string }>> {
  return api.post<{ message: string }>('/admin/crawl/run', { pages });
}

export function reindexSearch(): Promise<ApiResult<{ message: string }>> {
  return api.post<{ message: string }>('/admin/search/index/reindex');
}

export function rollbackSearchIndex(): Promise<ApiResult<{ data: unknown }>> {
  return api.post<{ data: unknown }>('/admin/search/index/rollback');
}

/**
 * الحالات الأربع هي `MaterialRequest::STATUSES` نفسها، وهي ما يتحقّق منه
 * `DemandController@update`. كان الاتحاد ثلاثيًّا يُسقط `published` فيَعِد بعقدٍ
 * أضيق ممّا يقبله الخادم. التسميات في `demand/_vocabulary.ts` لا هنا.
 */
export function setDemandStatus(
  id: number,
  status: 'new' | 'planned' | 'published' | 'declined',
): Promise<ApiResult<{ data: DemandRow }>> {
  return api.patch<{ data: DemandRow }>(`/admin/demand/${id}`, { status });
}
