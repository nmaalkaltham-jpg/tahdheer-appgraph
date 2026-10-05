// UNIT: DERIVE
// FILE: derive.ts
// STYLE: none
// PARENT: PAGE-ANALYTICS-OS-AI, PAGE-ANALYTICS-OS-CAUSE, PAGE-ANALYTICS-OS-FLOWS, PAGE-ANALYTICS-OS-OPPS, PAGE-ANALYTICS-OS-SIM
// USES: FEEDS, LIB-ADMIN-API, LIB-FORMAT, MEASUREMENT, SCREENS, _FINDINGS +2
// API: /admin/crawl/run, /admin/demand/{id}, /admin/search/index/reindex, /admin/search/index/rollback, /admin/search/synonyms
// MAP: docs/PROJECT_MAP.md

/**
 * اشتقاقات Analytics Pro OS — شاشات التحليل (السبب الجذري · الفرص · المحاكاة
 * · الأتمتة · المساعد).
 *
 * عقيدة الصدق (MIP §1.3) كما في seo/_findings.ts: كل رقم هنا جاء من نقطة
 * نهاية قائمة تحقّقنا من وجودها في backend/laravel/routes، وكل ما لا مصدر له
 * يُعلن غيابه بالاسم — لا صفر يُقرأ «سليم» ولا نطاق يُقرأ «توقع».
 *
 * لا درجة مُركَّبة ولا نسبة إسناد: ترجيح الأثر يحتاج نقرات وإيرادًا لكل
 * صفحة وهما غير موصولين (analytics/_shared/measurement.ts MISSING_FEEDS).
 */

import Link from 'next/link';
import { createElement as h, type CSSProperties, type ReactElement } from 'react';
import type { Dashboard, SearchIndexStatus, SearchQueriesReport } from '../../../../../lib/admin-api';
import { count, dateTime } from '../../../../../lib/format';
import type { FloorMetric } from '../../../growth/_governance';
import type { CrawlReport, LlmsCatalog, RobotsPolicy, SitemapIndex } from '../../../seo/_kit';
import { demandLabel } from './feeds';
import { OS_SCREENS, type OsScreenKey } from './screens';
import { MISSING_FEEDS, type Feed } from '../../_shared/measurement';
import type { Finding, ScoreFactor } from '../../../seo/_findings';
import '../../../../../../../.design-sync/tokens-3p/agent-B (analysis screens).css';

/* ───────────── الشاشات السبع — التنقّل مسارات حقيقية لا حالة محلية ───────────── */

export { OS_SCREENS, type OsScreenKey } from './screens';

const NAV: Record<string, CSSProperties> = {
  bar: {
    display: 'flex',
    alignItems: 'center',
    gap: 8,
    flexWrap: 'wrap',
    background: 'var(--chrome-deep)',
    color: 'var(--chrome-text)',
    borderRadius: 'var(--radius-card)',
    paddingBlock: 9,
    paddingInline: 14,
  },
  brand: { fontSize: 13, fontWeight: 900 },
  chip: {
    paddingBlock: 2,
    paddingInline: 9,
    borderRadius: 6,
    background: 'var(--tint-gold-16)',
    border: '1px solid var(--apb-gold-chip-border)',
    /* خُمسٌ نحو حبر السمة: الذهبيّ النصّيّ فوق صبغته كان 3.9:1 في الفاتح. */
    color: 'color-mix(in srgb, var(--apb-gold-chip-text) 80%, var(--cc-text))',
    fontSize: 10,
    fontWeight: 900,
  },
  on: {
    display: 'inline-flex',
    alignItems: 'center',
    minBlockSize: 44,
    paddingInline: 13,
    border: '1px solid var(--chrome-text)',
    borderRadius: 999,
    background: 'var(--chrome-text)',
    /* الحبر المعكوس سطحٌ مُعتِم: ‎--chrome-deep‎ بئرٌ شفيفة فكان النصّ يذوب في خلفيّته. */
    color: 'var(--cc-bg1)',
    fontSize: 11.5,
    fontWeight: 900,
  },
  off: {
    display: 'inline-flex',
    alignItems: 'center',
    minBlockSize: 44,
    paddingInline: 13,
    border: '1px solid var(--chrome-14)',
    borderRadius: 999,
    background: 'transparent',
    color: 'var(--chrome-muted)',
    fontSize: 11.5,
    fontWeight: 900,
  },
};

/**
 * شريط شاشات النظام — Link حقيقي لكل شاشة (مسارات فعلية تحت /analytics/os)،
 * لا setState يوهم بتعدد صفحات. مبني بـcreateElement لأن هذا ملف .ts مشترك.
 */
export function OsNav({ current }: { current: OsScreenKey }): ReactElement {
  return h(
    'nav',
    { 'aria-label': 'شاشات Analytics Pro OS', style: NAV.bar },
    h('b', { style: NAV.brand }, 'Analytics Pro — نظام تشغيل نمو البحث'),
    h('span', { style: NAV.chip }, 'طبقة تنفيذية فوق المحرك الحالي — لا شيء أُزيل'),
    h('span', { style: { flex: 1 } }),
    ...OS_SCREENS.map((screen) =>
      h(
        Link,
        {
          key: screen.key,
          href: screen.href,
          style: screen.key === current ? NAV.on : NAV.off,
          'aria-current': screen.key === current ? ('page' as const) : undefined,
        },
        screen.label,
      ),
    ),
  );
}

/* ───────────── أنواع الردود القائمة فعلًا ───────────── */

/** الصفوف الإضافية التي يعيدها CrawlController::show ولا يغطيها نوع _kit. */
export interface CrawlDeepRow {
  path: string;
  depth: number;
  inbound: number;
}

export interface CrawlRedirectRow {
  path: string;
  status: number;
  redirect_to: string | null;
}

/** الجولة المكتملة السابقة + الفروق — أُضيفت فعلًا إلى GET /admin/crawl. */
export interface CrawlPreviousRun {
  runId: number;
  finishedAt: string | null;
  totals: CrawlReport['totals'];
  /** موجب = زاد عن الجولة السابقة؛ في المكسورة واليتامى الموجب تدهور. */
  deltas: CrawlReport['totals'];
}

export interface CrawlReportOs extends CrawlReport {
  deep: CrawlDeepRow[];
  redirects: CrawlRedirectRow[];
  genericAnchors: { from_path: string; to_path: string; anchor: string }[];
  previousRun: CrawlPreviousRun | null;
}

export interface CrawlPayloadOs {
  data: CrawlReportOs | null;
  message?: string;
}

/** صفّ الطلب الفائت كما يعيده GET /admin/demand (DemandController::present). */
export interface DemandRowOs {
  id: number;
  stage: string | null;
  grade: string | null;
  subject: string | null;
  semester: string | null;
  kind: string | null;
  note: string | null;
  query: string | null;
  source: 'form' | 'zero_result';
  status: string;
  hits: number;
  firstSeenAt: string | null;
  lastSeenAt: string | null;
}

export interface DemandPayloadOs {
  data: DemandRowOs[];
  counts: Record<string, number>;
  statuses: string[];
}

/* ───────────── الإجراءات المسنودة بنقطة نهاية فعلية اليوم ───────────── */

export interface EndpointAction {
  key: string;
  label: string;
  method: 'POST' | 'PATCH';
  endpoint: string;
  permission: string;
  note: string;
}

/**
 * القائمة الصادقة لما يمكن تنفيذه فعلًا: كل سطر تحقّقنا من مساره في
 * backend/laravel/routes/api-admin.php. لا سطر «سيُبنى لاحقًا» هنا.
 */
export const ENDPOINT_ACTIONS: EndpointAction[] = [
  {
    key: 'synonym',
    label: 'إضافة مرادف لاستعلام بلا نتائج',
    method: 'POST',
    endpoint: '/admin/search/synonyms',
    permission: 'search.synonyms.manage',
    note: 'يوسّع الاسترجاع لكل الزوار ويُدرَج في سجل التدقيق.',
  },
  {
    key: 'crawl-run',
    label: 'تشغيل جولة زحف تحقّق',
    method: 'POST',
    endpoint: '/admin/crawl/run',
    permission: 'search.index.manage',
    note: 'تُقبل 202 وتقيس المكسور واليتيم من جديد.',
  },
  {
    key: 'reindex',
    label: 'إعادة بناء فهرس البحث',
    method: 'POST',
    endpoint: '/admin/search/index/reindex',
    permission: 'search.index.manage',
    note: 'جيل جديد يُبنى ثم يُنشر.',
  },
  {
    key: 'rollback',
    label: 'التراجع إلى جيل الفهرس السابق',
    method: 'POST',
    endpoint: '/admin/search/index/rollback',
    permission: 'search.index.manage',
    note: 'التراجع الحقيقي الوحيد في النظام — للفهرس فقط.',
  },
  {
    key: 'demand',
    label: 'تقرير مصير طلب فائت (مخطَّط/مرفوض)',
    method: 'PATCH',
    endpoint: '/admin/demand/{id}',
    permission: 'products.create',
    note: 'قرار إنتاج صريح على طلب حقيقي مسجَّل.',
  },
];

/* ───────────── مسح الإشارات — نواة شاشة السبب الجذري ───────────── */

export type SweepStatus = 'clear' | 'flagged' | 'unavailable';

export interface SweepCheck {
  id: string;
  name: string;
  /** نقطة النهاية التي نفّذت هذا الفحص فعلًا — تُعرض على الشارة. */
  endpoint: string;
  status: SweepStatus;
  /** جُمل قياس حقيقية؛ في «تعذّر» سببُ التعذّر كما وصل. */
  evidence: string[];
  /** الإجراء المتاح فعلًا — يُذكر فقط حين توجد وجهة أو نقطة نهاية قائمة. */
  fix?: string;
}

export function sweep(input: {
  robots: RobotsPolicy | null;
  sitemap: SitemapIndex | null;
  llms: LlmsCatalog | null;
  index: SearchIndexStatus | null;
  crawl: CrawlPayloadOs | null;
  queries: SearchQueriesReport | null;
}): SweepCheck[] {
  const out: SweepCheck[] = [];

  if (input.robots === null) {
    out.push({
      id: 'robots',
      name: 'سياسة robots وأصل الموقع',
      endpoint: 'GET /robots',
      status: 'unavailable',
      evidence: ['لم يصل ردّ نقطة النهاية — الفحص لم يُنفَّذ، فلا حكم يُعلن.'],
    });
  } else if (input.robots.sitemap === null) {
    out.push({
      id: 'robots',
      name: 'سياسة robots وأصل الموقع',
      endpoint: 'GET /robots',
      status: 'flagged',
      evidence: [
        'الردّ يعلن sitemap = null و host = null — أصل الموقع العام غير مضبوط.',
        'كل رابط مطلق يصدره نطاق SEO يعود نسبيًا حتى يُضبط seo.site_url.',
      ],
      fix: 'يُضبط seo.site_url من إعدادات الخادم — لا حقل في اللوحة يكتبه.',
    });
  } else {
    out.push({
      id: 'robots',
      name: 'سياسة robots وأصل الموقع',
      endpoint: 'GET /robots',
      status: 'clear',
      evidence: [
        `الردّ يعلن خريطة موقع${input.robots.host ? ' ومضيفًا' : ''} و${count(input.robots.disallow.length)} مسار Disallow.`,
      ],
    });
  }

  if (input.sitemap === null) {
    out.push({
      id: 'sitemap',
      name: 'مزوّدو خريطة الموقع',
      endpoint: 'GET /sitemap/index',
      status: 'unavailable',
      evidence: ['لم يصل ردّ نقطة النهاية — الفحص لم يُنفَّذ.'],
    });
  } else {
    const dormant = input.sitemap.providers.filter((provider) => !provider.enabled);
    if (dormant.length > 0) {
      out.push({
        id: 'sitemap',
        name: 'مزوّدو خريطة الموقع',
        endpoint: 'GET /sitemap/index',
        status: 'flagged',
        evidence: [
          `${count(dormant.length)} مزوّد خامل من ${count(input.sitemap.providers.length)}: ${dormant
            .slice(0, 3)
            .map((provider) => `${provider.label} (${provider.reason ?? 'سبب غير مصرَّح'})`)
            .join(' · ')}`,
        ],
        fix: 'المزوّد يفعّل نفسه حين تُبنى مسارات نطاقه — عالج السبب المعلن، لا مفتاح يقلبه.',
      });
    } else {
      out.push({
        id: 'sitemap',
        name: 'مزوّدو خريطة الموقع',
        endpoint: 'GET /sitemap/index',
        status: 'clear',
        evidence: [`كل المزوّدين (${count(input.sitemap.providers.length)}) يعلنون enabled = true.`],
      });
    }
  }

  if (input.llms === null) {
    out.push({
      id: 'llms',
      name: 'تغطية فهرس الكتالوج',
      endpoint: 'GET /llms-data',
      status: 'unavailable',
      evidence: ['لم يصل ردّ نقطة النهاية — الفحص لم يُنفَّذ.'],
    });
  } else {
    const coverage = input.llms.coverage;
    const excluded = coverage.published - coverage.included;
    if (coverage.published > 0 && excluded > 0) {
      out.push({
        id: 'llms',
        name: 'تغطية فهرس الكتالوج',
        endpoint: 'GET /llms-data',
        status: 'flagged',
        evidence: [
          `منشور ${count(coverage.published)} · مُدرَج ${count(coverage.included)} — ${count(excluded)} منتجًا خارج الفهرس بقرار سياسة الفهرسة.`,
        ],
        fix: 'افحص كل منتج مستبعَد في مفتّش الفهرسة (منصة SEO ← الفهرسة والزحف).',
      });
    } else {
      out.push({
        id: 'llms',
        name: 'تغطية فهرس الكتالوج',
        endpoint: 'GET /llms-data',
        status: 'clear',
        evidence: [`منشور ${count(coverage.published)} · مُدرَج ${count(coverage.included)} — لا استبعاد.`],
      });
    }
  }

  if (input.index === null) {
    out.push({
      id: 'index',
      name: 'صحّة فهرس البحث',
      endpoint: 'GET /admin/search/index',
      status: 'unavailable',
      evidence: ['لم يصل ردّ نقطة النهاية — الفحص لم يُنفَّذ.'],
    });
  } else {
    const problems: string[] = [];
    if (!input.index.everBuilt) problems.push('لم يُبنَ الفهرس قط.');
    if (!input.index.engineHealthy) problems.push('محرك البحث يعلن أنه غير سليم.');
    if (input.index.servingPath === 'postgres_fallback')
      problems.push('التقديم عبر مسار الطوارئ postgres_fallback لا عبر المحرك.');
    if (problems.length > 0) {
      out.push({
        id: 'index',
        name: 'صحّة فهرس البحث',
        endpoint: 'GET /admin/search/index',
        status: 'flagged',
        evidence: [...problems, `الجيل ${count(input.index.generation)} · الحالة «${input.index.status}».`],
        fix: 'إعادة البناء والتراجع نقطتا نهاية قائمتان: POST /admin/search/index/reindex و /rollback.',
      });
    } else {
      out.push({
        id: 'index',
        name: 'صحّة فهرس البحث',
        endpoint: 'GET /admin/search/index',
        status: 'clear',
        evidence: [
          `الجيل ${count(input.index.generation)} · آخر بناء ${dateTime(input.index.lastBuiltAt)} · التقديم عبر المحرك.`,
        ],
      });
    }
  }

  const report = input.crawl?.data ?? null;
  if (input.crawl === null) {
    out.push({
      id: 'crawl',
      name: 'الزحف الداخلي (مكسور · يتيم · تحويلات)',
      endpoint: 'GET /admin/crawl',
      status: 'unavailable',
      evidence: ['لم يصل ردّ نقطة النهاية — الفحص لم يُنفَّذ.'],
    });
  } else if (report === null) {
    out.push({
      id: 'crawl',
      name: 'الزحف الداخلي (مكسور · يتيم · تحويلات)',
      endpoint: 'GET /admin/crawl',
      status: 'unavailable',
      evidence: [
        `${input.crawl.message ?? 'لم تُشغَّل جولة زحف بعد.'} المخزن موصول والفحص ممكن — تشغيله هو الخطوة.`,
      ],
      fix: 'POST /admin/crawl/run نقطة نهاية قائمة تبدأ الجولة الأولى.',
    });
  } else {
    const flaggedCrawl = report.totals.broken > 0 || report.totals.orphans > 0 || report.redirects.length > 0;
    out.push({
      id: 'crawl',
      name: 'الزحف الداخلي (مكسور · يتيم · تحويلات)',
      endpoint: 'GET /admin/crawl',
      status: flaggedCrawl ? 'flagged' : 'clear',
      evidence: [
        `جولة #${report.runId} قاست ${count(report.totals.pages)} صفحة و${count(report.totals.links)} رابطًا.`,
        `مكسورة ${count(report.totals.broken)} · يتيمة ${count(report.totals.orphans)} · تحويلات مرصودة ${count(report.redirects.length)}${report.redirects.length >= 50 ? ' (سقف القائمة 50)' : ''}.`,
      ],
      fix: flaggedCrawl
        ? 'الإصلاح تحريري من مساحة الروابط الداخلية، والتحقق بعده جولة جديدة عبر POST /admin/crawl/run.'
        : undefined,
    });
  }

  if (input.queries === null) {
    out.push({
      id: 'queries',
      name: 'البحث الداخلي بلا نتائج',
      endpoint: 'GET /admin/search/queries',
      status: 'unavailable',
      evidence: ['لم يصل ردّ نقطة النهاية — الفحص لم يُنفَّذ.'],
    });
  } else if (input.queries.totals.zeroResultQueries > 0) {
    out.push({
      id: 'queries',
      name: 'البحث الداخلي بلا نتائج',
      endpoint: 'GET /admin/search/queries',
      status: 'flagged',
      evidence: [
        `${count(input.queries.totals.zeroResultQueries)} استعلامًا بلا نتائج في نافذة ${count(input.queries.window.days)} يومًا${
          input.queries.metrics.zeroResultRate === null
            ? ''
            : ` — نسبة صفر النتائج ${input.queries.metrics.zeroResultRate}%`
        }.`,
      ],
      fix: 'جسر المرادفات نقطة نهاية قائمة: POST /admin/search/synonyms.',
    });
  } else {
    out.push({
      id: 'queries',
      name: 'البحث الداخلي بلا نتائج',
      endpoint: 'GET /admin/search/queries',
      status: 'clear',
      evidence: [
        `${count(input.queries.totals.queriesLogged)} استعلامًا مسجَّلًا في ${count(input.queries.window.days)} يومًا، لا صفر نتائج بينها.`,
      ],
    });
  }

  const rank: Record<SweepStatus, number> = { flagged: 0, unavailable: 1, clear: 2 };
  return out.sort((a, b) => rank[a.status] - rank[b.status]);
}

/* ───────────── صفوف الفرص — اشتقاق + طلب فائت + مدفونة ───────────── */

export interface OppAction {
  label: string;
  /** وجهة حقيقية داخل اللوحة — أو غيابها مع السبب المكتوب. */
  href?: string;
  blockedReason?: string;
}

export interface OppRow {
  id: string;
  title: string;
  desc: string;
  /** صادقة: الإصلاح له نقطة نهاية قائمة اليوم (مرادف/طلب/زحف)، لا «سريع» شعوريًا. */
  quickWin: boolean;
  source: string;
  factors: ScoreFactor[];
  action: OppAction;
}

/** ما يُعرض مكان تقدير الإيراد لكل صف — الغياب مُسمّى لا مُصفَّر. */
export const OPP_REVENUE_ABSENT = 'يحتاج ربط الإيراد بالصفحة';

/** التفكيك السداسي بعناوين اللوح — كل عامل بحقيقة قياسه وما ينقصه. */
function opportunityFactors(demandNote: string | null, localNote: string | null): ScoreFactor[] {
  return [
    demandNote !== null
      ? { name: 'الطلب', measured: true, note: demandNote }
      : { name: 'الطلب', measured: false, note: 'يحتاج حجم البحث الخارجي — غير متوفر محليًا' },
    ...(localNote !== null ? [{ name: 'الدليل المحلي', measured: true, note: localNote }] : []),
    { name: 'إمكانية الترتيب', measured: false, note: 'يحتاج مراكز Search Console — غير موصول' },
    { name: 'القيمة التجارية', measured: false, note: 'يحتاج ربط الإيراد بالصفحة — لا تجميع خادمي' },
    { name: 'التحويل', measured: false, note: 'يحتاج أحداث زيارات طرف أول — لا جدول أحداث' },
    { name: 'الثقة', measured: false, note: 'لا نموذج ولا ذاكرة نتائج تُقاس عليها' },
    { name: 'الجهد', measured: false, note: 'تقدير بشري — لا يُحسب آليًا ولا يُخترع' },
  ];
}

/*
 * `demandLabel` كانت هنا نسخةً ثانية افترقت عن أصلها في `feeds.ts`.
 * صارت تُستورَد من هناك (أعلى الملفّ) — مصدرٌ واحد لصياغة واحدة.
 */

export function deriveOpportunities(args: {
  findings: Finding[];
  demand: DemandPayloadOs | null;
  crawl: CrawlPayloadOs | null;
}): OppRow[] {
  const out: OppRow[] = [];

  for (const finding of args.findings) {
    if (finding.severity === 'na') continue;
    const isZero = finding.id.startsWith('zero-');
    const isCrawl = finding.id === 'crawl-orphans';
    out.push({
      id: finding.id,
      title: finding.title,
      desc: finding.measured,
      quickWin: isZero || isCrawl,
      source: isZero
        ? 'GET /admin/search/queries + /admin/search/synonyms'
        : isCrawl
          ? 'GET /admin/crawl'
          : finding.urls[0] ?? 'اشتقاق محلي — seo/_findings.ts',
      factors: finding.factors,
      action:
        isZero || isCrawl
          ? { label: 'افتح الإجراء', href: `/analytics/os/actions#${finding.id}` }
          : {
              label: 'أنشئ Workflow',
              blockedReason: 'لا محرّك Workflows في هذا النظام، ولا نقطة نهاية تكتب هذا الإصلاح — العمل تحريري من مساحته.',
            },
    });
  }

  if (args.demand) {
    for (const row of args.demand.data.filter((request) => request.status === 'new').slice(0, 6)) {
      out.push({
        id: `demand-${row.id}`,
        title: `${row.source === 'zero_result' ? 'بحث داخلي بلا صفحة' : 'طلب مادة من النموذج'}: «${demandLabel(row)}»`,
        desc: `${count(row.hits)} طلبًا حقيقيًا مسجَّلًا · آخر ظهور ${dateTime(row.lastSeenAt)}`,
        quickWin: true,
        source: 'GET /admin/demand',
        factors: opportunityFactors(`${count(row.hits)} طلبًا مسجَّلًا في صندوق الطلب الفائت`, null),
        action: { label: 'افتح الإجراء', href: `/analytics/os/actions#demand-${row.id}` },
      });
    }
  }

  const report = args.crawl?.data ?? null;
  if (report && report.deep.length > 0) {
    out.push({
      id: 'crawl-deep',
      title: `${count(report.deep.length)} صفحة مدفونة (عمق نقر ≥ 3)${report.deep.length >= 50 ? ' — سقف القائمة 50' : ''}`,
      desc: `أقصى عمق مقيس ${count(report.totals.maxDepth)} — جولة #${report.runId}`,
      quickWin: true,
      source: 'GET /admin/crawl',
      factors: opportunityFactors(null, `${count(report.deep.length)} صفحة بعمق ≥ 3 قاسها الزاحف فعلًا`),
      action: { label: 'افتح الإجراء', href: '/analytics/os/actions#crawl-deep' },
    });
  }

  return out;
}

/* ───────────── المحاكاة — سيناريوهات بمدخلات مسمّاة الصدق ───────────── */

export type SimKey = 'ctr' | 'pos' | 'links';

export interface SimScenarioDef {
  key: SimKey;
  name: string;
}

export const SIM_SCENARIOS: SimScenarioDef[] = [
  { key: 'ctr', name: 'CTR +٢٠٪ للمراكز ١–١٠' },
  { key: 'pos', name: 'مراكز ٤–١٠ تتقدم مركزين' },
  { key: 'links', name: 'إصلاح كل الروابط المكسورة عالية الأولوية' },
];

export interface SimInputRow {
  label: string;
  measured: boolean;
  /** القيمة الحقيقية إن قيست، وإلا null — والغائب يسمّي معيله. */
  value: string | null;
  source: string;
}

export function simInputs(key: SimKey, crawl: CrawlPayloadOs | null): SimInputRow[] {
  if (key === 'links') {
    const report = crawl?.data ?? null;
    return [
      {
        label: 'الروابط المكسورة المقيسة',
        measured: report !== null,
        value: report === null ? null : count(report.totals.broken),
        source:
          report === null
            ? `GET /admin/crawl — ${crawl?.message ?? 'ردّ الزحف غير متاح الآن'}`
            : 'GET /admin/crawl → totals.broken',
      },
      {
        label: 'جولة القياس وتاريخها',
        measured: report !== null,
        value: report === null ? null : `#${report.runId} · ${dateTime(report.finishedAt)}`,
        source: 'GET /admin/crawl → runId · finishedAt',
      },
      {
        label: 'زيارات الصفحات المكسورة',
        measured: false,
        value: null,
        source: 'يحتاج أحداث زيارات طرف أول — لا جدول أحداث ولا مسار يقرأه',
      },
      {
        label: 'أثر الإصلاح على النقرات',
        measured: false,
        value: null,
        source: 'يحتاج Search Console — لا ربط OAuth ولا مزامنة',
      },
    ];
  }

  if (key === 'ctr') {
    return [
      { label: 'الانطباعات والمراكز الحالية', measured: false, value: null, source: 'يحتاج Search Console — غير موصول' },
      { label: 'CTR الحالي لكل مركز', measured: false, value: null, source: 'يحتاج Search Console — غير موصول' },
      { label: 'معدل التحويل لكل صفحة', measured: false, value: null, source: 'يحتاج أحداث زيارات طرف أول — لا جدول أحداث' },
    ];
  }

  return [
    { label: 'منحنيات CTR بالمركز', measured: false, value: null, source: 'يحتاج Search Console — غير موصول' },
    { label: 'الكلمات المؤهلة (مراكز ٤–١٠)', measured: false, value: null, source: 'يحتاج Search Console — غير موصول' },
    { label: 'معدل التحويل لكل صفحة', measured: false, value: null, source: 'يحتاج أحداث زيارات طرف أول — لا جدول أحداث' },
  ];
}

/* ───────────── مراجع مشتركة ───────────── */

/** الخطّ الغائب من سجلّ القياس الكنسي — الاسم من مصدر واحد لا يُعاد اختراعه. */
export function missingFeed(id: string): Feed | null {
  return MISSING_FEEDS.find((feed) => feed.id === id) ?? null;
}

/** قياس حقيقي من لوحة القياس مقابل أرضيات العيّنة (نمط growth/page.tsx). */
export function floorMeasure(dashboard: Dashboard, metric: FloorMetric): { value: number | null; source: string } {
  switch (metric) {
    case 'products':
      return { value: dashboard.measured.products.byStatus.published ?? 0, source: 'products.byStatus.published' };
    case 'orders':
      return { value: dashboard.measured.orders.total, source: 'orders.total' };
    case 'searches':
      return { value: dashboard.measured.search.queriesLogged, source: 'search.queriesLogged' };
    case 'impressions':
      return { value: null, source: 'لا مصدر ظهور — Search Console غير موصول' };
  }
}

/** فرق بإشارة صريحة للعرض داخل bdi dir=ltr. */
export function signed(value: number): string {
  return value > 0 ? `+${count(value)}` : count(value);
}
