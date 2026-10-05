'use client';
// UNIT: _KIT
// FILE: _kit.tsx
// STYLE: seo.module.css
// PARENT: DERIVE, FEEDS, LOOPS-PANEL, PAGE-ANALYTICS-OS-AI, PAGE-ANALYTICS-OS-CAUSE, PAGE-ANALYTICS-OS-OPPS +7
// USES: LIB-FORMAT, RESOURCE, _SEO-ICONS
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * عُدّة منصة SEO: الأيقونات، أنواع نقاط النهاية الحقيقية، وعناصر الحالة الصادقة.
 *
 * عقيدة الصدق (MIP §1.3) مطبَّقة هنا بثلاث حالات صريحة لا رابعة لها:
 *   · «مقيس»        — رقم جاء من نقطة نهاية قائمة، ومصدره معروض معه.
 *   · «غير مُهيأ»    — التكامل الذي يقيسه غير موصول؛ نسمّيه بالاسم.
 *   · «بيانات غير كافية» — النقطة موصولة لكن السجل لا يكفي للحكم.
 * الصفر الذي يعني «لا تكامل» كذب، فلا يُعرض صفرًا أبدًا.
 */

import Link from 'next/link';
import { useCallback, useEffect, useId, useRef, type ReactNode } from 'react';
import type { ResourceState } from '../../../components/resource';
import { count, dateTime } from '../../../lib/format';
import { Icon, type IconName } from './_seo-icons';
import styles from './seo.module.css';

/** بيانات المورد إن جهزت، وإلا null — لا قيمة افتراضية تسدّ الفراغ. */
export function dataOf<T>(state: ResourceState<T>): T | null {
  return state.status === 'ready' ? state.data : null;
}

/** أول خطأ في مجموعة موارد، ليُعلن مرة واحدة بدل تكراره في كل لوح. */
export function firstProblem(states: ResourceState<unknown>[]): string | null {
  for (const state of states) {
    if (state.status === 'error') return state.message;
  }
  return null;
}

export function anyLoading(states: ResourceState<unknown>[]): boolean {
  return states.some((state) => state.status === 'loading');
}

/**
 * ٤٠٣ ليس عطلًا. الخادم ردّ، وردّه أنّ هذا المفتاح ليس في دورك — فبطاقة خطأ
 * عامّة بزرّ «إعادة المحاولة» تكذب مرّتين: تُوهم بعطلٍ عابر، وتَعِد بأنّ ضغطة
 * ثانية تفتح ما لن يُفتح. تمييزه برمزه هو ما يسمح للّوح أن يسمّي الصلاحية.
 */
export function isForbidden(state: ResourceState<unknown>): boolean {
  return state.status === 'error' && state.code === 403;
}

export { Icon, type IconName };

/* ───────────── أنواع نقاط النهاية القائمة فعلًا ───────────── */

/** أقسام مساحة SEO — معرّفة هنا كي يشير إليها الاشتقاق دون دورة استيراد. */
export type SeoSectionKey =
  | 'exec'
  | 'opps'
  | 'search'
  | 'index'
  | 'content'
  | 'products'
  | 'safety'
  | 'tasks';

/**
 * تقرير الزحف كما يعيده GET /admin/crawl حرفيًا (CrawlController::show).
 * الغلاف يحمل data = null قبل أول جولة، ورسالةَ الخادم التي تشرح ذلك — وهو
 * فرق جوهري عن «لا مخزن زحف»: المخزن موجود وموصول، والجولة وحدها لم تُشغَّل.
 */
export interface CrawlReport {
  runId: number;
  startedAt: string | null;
  finishedAt: string | null;
  totals: { pages: number; links: number; broken: number; orphans: number; maxDepth: number };
  orphans: { path: string; title: string | null; discovered_from: string }[];
  broken: { path: string; status: number }[];
}

export interface CrawlPayload {
  data: CrawlReport | null;
  message?: string;
}

export interface SitemapProviderRow {
  provider: string;
  label: string;
  enabled: boolean;
  /** سبب سُبات المزوّد بالعربية — من الخادم، لا يُخترع في الواجهة. */
  reason: string | null;
  url: string;
}

export interface SitemapIndex {
  providers: SitemapProviderRow[];
}

export interface SitemapUrl {
  loc: string;
  lastmod: string | null;
  changefreq?: string;
  priority?: number;
}

export interface SitemapDetail {
  provider: string;
  enabled: boolean;
  reason: string | null;
  urls: SitemapUrl[];
  count: number;
}

export interface RobotsPolicy {
  userAgent: string;
  disallow: string[];
  sitemap: string | null;
  host: string | null;
}

export interface IndexabilityResult {
  path: string;
  robots: string;
  verdict: 'eligible' | 'monitor' | 'excluded' | string;
  reason: string;
  rule: string;
}

export interface RedirectResolution {
  match: { ruleId: number; source: string; target: string; statusCode: number } | null;
}

export interface LlmsCatalog {
  brand: string;
  items: { title: string; url: string; stage: string | null; subject: string | null; semester: string | null; oneLiner: string | null }[];
  coverage: { published: number; included: number; pct: number | null; withOneLiner: number };
}

export interface ProductSeoPayload {
  metadata: {
    title: string;
    renderedTitle: string;
    titleLength: number;
    overEditorBudget: boolean;
    renderedTitleAlarm: boolean;
    description: string | null;
    descriptionSource: string;
    canonical: string;
    canonicalIsSelf: boolean;
    robots: string;
    indexability: { verdict: string; reason: string; rule: string };
    openGraph: { image: string | null; imageSource: string; type: string; locale: string };
    twitter: { card: string };
  };
  visible: {
    breadcrumbs: { name: string; url: string }[];
    faqs: { question: string; answer: string }[];
    /** خريطة مفتاح←قيمة كما يعرضها جدول الحقائق في الصفحة، لا مصفوفة. */
    facts: Record<string, string | number | null>;
    author: { name: string; url: string | null; bio: string | null } | null;
    description: string | null;
    /** رابط صورة حقيقية للمنتج فقط؛ شعار الاحتياط لا يُعدّ محتوى ظاهرًا. */
    image: string | null;
  };
  schema: { '@context': string; '@graph': { '@type'?: string | string[] }[] };
}

export const VERDICT_LABELS: Record<string, string> = {
  eligible: 'مؤهّل للفهرسة',
  monitor: 'تحت المراقبة',
  excluded: 'مستبعَد',
};

/* ───────────── عناصر الحالة الصادقة ───────────── */

/**
 * تكامل غير موصول: نُسمّي التكامل الناقص ونقطة النهاية التي ستغذّي اللوح،
 * ولا نعرض صفرًا ولا رسمًا فارغًا يُوهم بأن القياس تمّ ونتيجته لا شيء.
 */
export function NotConfigured({
  title,
  reason,
  needs,
  kind = 'NOT_CONFIGURED',
}: {
  title: string;
  reason: string;
  needs?: string[];
  kind?: 'NOT_CONFIGURED' | 'NO_DATA' | 'INSUFFICIENT_DATA';
}) {
  const label =
    kind === 'NOT_CONFIGURED' ? 'غير مُهيأ' : kind === 'NO_DATA' ? 'لا بيانات' : 'بيانات غير كافية';

  return (
    <div className={`${styles.absentbox} ${kind === 'NOT_CONFIGURED' ? '' : styles.nodata}`} role="note">
      <div className={styles.absenthead}>
        <Icon name={kind === 'NOT_CONFIGURED' ? 'warn' : 'info'} />
        <span className={styles.absenttitle}>{title}</span>
        <span className={`${styles.tag} ${kind === 'NOT_CONFIGURED' ? styles.warn : ''}`}>
          <bdi dir="ltr">{kind}</bdi> · {label}
        </span>
      </div>
      <p className={styles.absentbody}>{reason}</p>
      {needs && needs.length > 0 ? (
        <ul className={styles.absentneeds}>
          {needs.map((need) => (
            <li key={need}>
              <bdi dir="ltr">{need}</bdi>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

/**
 * لوحٌ مقفل بصلاحية: يُسمّى المفتاح الناقص، ويُقال ما الذي كان سيُقرأ به.
 *
 * الفرق عن «غير مُهيأ» جوهريّ لا لفظيّ: هناك تكاملٌ غائب عن النظام كلّه،
 * وهنا مصدرٌ موصول وقائم يقرأه زميلك ولا تقرأه أنت. ولا زرّ إعادة محاولة —
 * الردّ الثاني ٤٠٣ نفسه، والباب يُفتح من كتالوج الصلاحيات لا من هذه الصفحة.
 */
export function Forbidden({
  title,
  permission,
  reads,
}: {
  title: string;
  permission: string;
  reads: string;
}) {
  return (
    <div className={`${styles.absentbox} ${styles.nodata}`} role="note">
      <div className={styles.absenthead}>
        <Icon name="shield" />
        <span className={styles.absenttitle}>{title}</span>
        <span className={styles.tag}>
          <bdi dir="ltr">FORBIDDEN</bdi> · صلاحية ناقصة
        </span>
      </div>
      <p className={styles.absentbody}>
        دورك لا يملك <bdi dir="ltr">{permission}</bdi>، فردّ الخادم ٤٠٣ وبقي هذا اللوح مغلقًا. {reads} — وما
        لا يُقرأ لا يُعرض صفرًا ولا يُعلن سليمًا. اطلب الصلاحية من مدير المتجر؛ إعادة المحاولة تردّ ٤٠٣ نفسه.
      </p>
    </div>
  );
}

/**
 * مؤشر مقيس: القيمة ومصدرها معًا؛ القيمة الغائبة تُعلن غيابها ولا تصير صفرًا.
 * `absentLabel` يميّز «غير مضبوط» (إعداد ناقص) عن «غير مقيس» (قياس مفقود).
 */
export function Kpi({
  label,
  value,
  source,
  icon,
  absentLabel = 'غير مقيس',
}: {
  label: string;
  value: string | null;
  source: string;
  icon?: IconName;
  absentLabel?: string;
}) {
  return (
    <div className={styles.kpi}>
      <span className={styles.kpilabel}>
        {icon ? <Icon name={icon} /> : null}
        {label}
      </span>
      <span className={`${styles.kpivalue} ${value === null ? styles.absent : ''}`}>
        {value === null ? absentLabel : <bdi dir="ltr">{value}</bdi>}
      </span>
      <span className={styles.kpisrc}>{source}</span>
    </div>
  );
}

export function Box({
  title,
  icon,
  note,
  children,
  foot,
}: {
  title: string;
  icon?: IconName;
  note?: string;
  children: ReactNode;
  foot?: ReactNode;
}) {
  return (
    <section className={styles.box}>
      <div className={styles.boxhead}>
        {icon ? <Icon name={icon} className={styles.icon} /> : null}
        <h2>{title}</h2>
        {note ? <span className={styles.boxnote}>{note}</span> : null}
      </div>
      {children}
      {foot ? <div className={styles.boxfoot}>{foot}</div> : null}
    </section>
  );
}

/** شريط نسبة مقيسة: البسط والمقام معروضان دائمًا حتى لا تكون النسبة مجرّدة. */
export function Meter({ name, done, total }: { name: string; done: number; total: number }) {
  const pct = total === 0 ? 0 : Math.round((done / total) * 100);
  return (
    <div className={styles.meterrow}>
      <span className={styles.metername}>{name}</span>
      <span className={styles.metertrack}>
        <span className={styles.meterfill} style={{ inlineSize: `${pct}%` }} />
      </span>
      <span className={styles.metervalue}>
        <bdi dir="ltr">
          {done}/{total}
        </bdi>
      </span>
    </div>
  );
}

/**
 * حلقة نسبة: تُرسم بيد كما في اللوح (لا مكتبة رسوم). النسبة الغائبة تُكتب
 * «لا نسبة» بدل رسم حلقة فارغة توحي بصفر مقيس.
 */
export function Ring({ pct, caption }: { pct: number | null; caption: string }) {
  const circumference = 2 * Math.PI * 45;
  const filled = pct === null ? 0 : (Math.max(0, Math.min(100, pct)) / 100) * circumference;

  return (
    <div className={styles.ring}>
      <svg viewBox="0 0 100 100" role="img" aria-label={caption}>
        <circle cx="50" cy="50" r="45" fill="none" style={{ stroke: 'var(--neutral)' }} strokeWidth="9" />
        {pct === null ? null : (
          <circle
            cx="50"
            cy="50"
            r="45"
            fill="none"
            style={{ stroke: 'var(--primary)' }}
            strokeWidth="9"
            strokeLinecap="round"
            strokeDasharray={`${filled} ${circumference - filled}`}
          />
        )}
      </svg>
      <span className={`${styles.ringlabel} ${pct === null ? styles.absent : ''}`} aria-hidden="true">
        {pct === null ? 'لا نسبة' : <bdi dir="ltr">{pct}%</bdi>}
      </span>
    </div>
  );
}

/**
 * قياسات الزحف الداخلي من GET /admin/crawl — نقطة نهاية موصولة فعلًا فوق
 * جداول crawl_runs و crawled_pages و internal_links.
 *
 * وقبل أول جولة يعيد الخادم data = null ومعه سببه: تلك «لا بيانات» لا «لا
 * تكامل». الفرق ليس لفظيًا — الأولى تُصلَح بتشغيل جولة، والثانية كانت تُقرأ
 * «هذه القدرة غير موجودة» فتُغلق باب عمل مفتوح أصلًا.
 */
export function CrawlMeasures({ state, title }: { state: ResourceState<CrawlPayload>; title: string }) {
  if (state.status === 'loading') {
    return (
      <p className="state-line" role="status" aria-live="polite">
        جارٍ قراءة تقرير الزحف…
      </p>
    );
  }

  if (state.status === 'error') {
    return <NotConfigured kind="NO_DATA" title={title} reason={state.message} />;
  }

  const report = state.data.data;

  if (report === null) {
    return (
      <NotConfigured
        kind="NO_DATA"
        title={title}
        reason={`${state.data.message ?? 'لم تُشغَّل جولة زحف بعد.'} مخزن الزحف موصول ونقطة النهاية تردّ، لكن لا جولة مكتملة بعد — فلا رقم يُعرض، والصفر هنا كان سيُقرأ «لا صفحات يتيمة ولا روابط مكسورة».`}
        needs={['تشغيل جولة زحف من مساحة الروابط الداخلية']}
      />
    );
  }

  return (
    <Box title={title} icon="link" note={`جولة #${report.runId}`}>
      <div className={styles.boxbody}>
        <div className={styles.gridkpi}>
          <Kpi
            label="صفحات مزحوفة"
            icon="doc"
            value={count(report.totals.pages)}
            source="GET /admin/crawl → totals.pages"
          />
          <Kpi
            label="روابط داخلية"
            icon="link"
            value={count(report.totals.links)}
            source="GET /admin/crawl → totals.links"
          />
          <Kpi
            label="روابط مكسورة"
            icon="warn"
            value={count(report.totals.broken)}
            source="crawled_pages.status >= 400"
          />
          <Kpi
            label="صفحات يتيمة"
            icon="globe"
            value={count(report.totals.orphans)}
            source="crawled_pages.inbound = 0"
          />
          <Kpi
            label="أقصى عمق نقر"
            icon="map"
            value={count(report.totals.maxDepth)}
            source="GET /admin/crawl → totals.maxDepth"
          />
        </div>
        <p className={styles.footnote} style={{ marginBlockStart: 8 }}>
          {report.finishedAt
            ? `آخر جولة مكتملة: ${dateTime(report.finishedAt)}.`
            : 'لم يسجّل الخادم وقت انتهاء لهذه الجولة.'}{' '}
          الخريطة الكاملة — كل صفحة يتيمة ومكسورة ومدفونة ومرساها العامّة — في مساحة الروابط الداخلية.
        </p>
        <Link className={`${styles.btn} ${styles.tinted} ${styles.small}`} href="/crawl">
          <Icon name="link" />
          افتح خريطة الروابط الداخلية
        </Link>
      </div>
    </Box>
  );
}

/**
 * حوار الدليل: يحبس التنقّل بالمفاتيح داخله، يُغلق بـEscape، ويعيد التركيز
 * إلى الزرّ الذي فتحه (MIP §2.4 قواعد الوصول المُلزِمة).
 */
export function EvidenceDialog({
  title,
  subtitle,
  onClose,
  children,
}: {
  title: string;
  subtitle?: string;
  onClose: () => void;
  children: ReactNode;
}) {
  const dialogRef = useRef<HTMLDivElement | null>(null);
  const openerRef = useRef<Element | null>(null);
  const headingId = useId();

  const focusables = useCallback((): HTMLElement[] => {
    const root = dialogRef.current;
    if (!root) return [];
    return Array.from(
      root.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'),
    ).filter((el) => el.offsetParent !== null || el === root);
  }, []);

  useEffect(() => {
    openerRef.current = document.activeElement;
    dialogRef.current?.focus();

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.stopPropagation();
        onClose();
        return;
      }
      if (event.key !== 'Tab') return;

      const items = focusables();
      if (items.length === 0) {
        event.preventDefault();
        return;
      }
      const first = items[0];
      const last = items[items.length - 1];
      const active = document.activeElement;

      if (event.shiftKey && (active === first || active === dialogRef.current)) {
        event.preventDefault();
        last?.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first?.focus();
      }
    }

    document.addEventListener('keydown', onKeyDown, true);
    return () => {
      document.removeEventListener('keydown', onKeyDown, true);
      const opener = openerRef.current;
      if (opener instanceof HTMLElement) opener.focus();
    };
  }, [focusables, onClose]);

  return (
    <div
      className={styles.backdrop}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <div
        className={styles.dialog}
        role="dialog"
        aria-modal="true"
        aria-labelledby={headingId}
        tabIndex={-1}
        ref={dialogRef}
      >
        <div className={styles.dialoghead}>
          <div style={{ flex: 1, minInlineSize: 0 }}>
            <h2 id={headingId}>{title}</h2>
            {subtitle ? (
              <bdi dir="ltr" className={styles.mono}>
                {subtitle}
              </bdi>
            ) : null}
          </div>
          <button type="button" className={styles.dialogclose} onClick={onClose} aria-label="إغلاق الحوار">
            <Icon name="x" />
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
