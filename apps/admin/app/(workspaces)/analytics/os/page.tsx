'use client';
// UNIT: PAGE-ANALYTICS-OS
// FILE: page.tsx
// ROUTE: /analytics/os
// STYLE: os.module.css
// PARENT: none
// USES: FEEDS, LIB-FORMAT, OS-KIT
// API: none
// API-VIA: FEEDS
// MAP: docs/PROJECT_MAP.md

/**
 * غرفة التحكم — شاشة Analytics Pro OS الرئيسة.
 *
 * كل رقم من نقطة نهاية قائمة وموسوم بمصدره؛ وما لا مصدر له (Workflows،
 * إيراد ٧ أيام، Top 10) بلاطة غياب مسمّاة السبب — لا صفر ولا عيّنة لوح.
 * «منذ زيارتك الأخيرة» وقت متصفّح معلَن: لا سجل زيارات في الخادم.
 */

import Link from 'next/link';
import { useEffect, useSyncExternalStore } from 'react';
import { count, dateTime, money } from '../../../../lib/format';
import styles from './os.module.css';
import { OsIcon, SourceChip, type OsIconName } from './_components/os-kit';
import { ready, useHomeFeeds, demandLabel, type HomeFeeds } from './_lib/feeds';

const LAST_OPEN_KEY = 'analytics.os.lastOpen';

/** مراحل حلقة النمو الثلاث عشرة — قدرةً لا ادّعاء تشغيل. */
const LOOP_STAGES: { name: string; real: boolean; note: string }[] = [
  { name: 'رصد', real: true, note: 'سجل البحث الداخلي + مخزن الزحف موصولان' },
  { name: 'فهم', real: false, note: 'لا نموذج فهم' },
  { name: 'كشف', real: true, note: 'اشتقاق الاكتشافات من سبعة مغذّيات حقيقية' },
  { name: 'تشخيص', real: false, note: 'لا سلاسل زمنية محفوظة — لا كشف هبوط آلي' },
  { name: 'ترتيب', real: false, note: 'لا درجة مُركَّبة — أغلب العوامل غير مقيسة' },
  { name: 'محاكاة', real: false, note: 'لا منحنيات CTR — يحتاج Search Console' },
  { name: 'موافقة', real: false, note: 'لا طابور موافقات في الخادم — الموافقة زرّ جلسة فقط' },
  { name: 'تنفيذ', real: true, note: 'أربع نقاط نهاية كتابة حقيقية في مركز الإجراءات' },
  { name: 'تحقق', real: true, note: 'جولة زحف مقابل الجولة السابقة (previousRun)' },
  { name: 'مراقبة', real: false, note: 'لا مجدول تنبيهات' },
  { name: 'قياس', real: false, note: 'لا لقطات تاريخية' },
  { name: 'تعلم', real: false, note: 'لا ذاكرة نتائج' },
  { name: 'تحسين', real: false, note: 'لا محرّك قواعد' },
];

/** كل حالات غرفة التحكم — لعرض أول خطأ وإعادة تحميل الكل. */
function feedStates(feeds: HomeFeeds) {
  return [
    feeds.dashboard,
    feeds.crawl,
    feeds.queries,
    feeds.demand,
    feeds.sitemap,
    feeds.llms,
    feeds.robots,
    feeds.synonyms,
    feeds.products,
  ];
}

let lastVisitSnapshot: string | null | undefined;
function readLastVisit(): string | null {
  if (lastVisitSnapshot === undefined) {
    try {
      lastVisitSnapshot = window.localStorage.getItem(LAST_OPEN_KEY);
    } catch {
      lastVisitSnapshot = null;
    }
  }
  return lastVisitSnapshot;
}
const subscribeNoop = () => () => {};

export default function OsHomePage() {
  const feeds = useHomeFeeds();
  /* الزيارة السابقة تُقرأ مرّةً واحدة كلقطة خارجيّة (لا حالة تُكتب داخل أثر)، ثمّ يُختم وقت هذه الزيارة. */
  const lastVisit = useSyncExternalStore(subscribeNoop, readLastVisit, () => null);

  useEffect(() => {
    try {
      window.localStorage.setItem(LAST_OPEN_KEY, new Date().toISOString());
    } catch {
      // تخزين معطّل — يبقى العنوان بلا وقت، وهو صادق.
    }
  }, []);

  const all = feedStates(feeds);
  const loading = all.some((feed) => feed.state.status === 'loading');
  const problem = all.find((feed) => feed.state.status === 'error');

  const riskCount = feeds.findings.filter((finding) => finding.severity === 'risk').length;
  const gapCount = feeds.findings.filter((finding) => finding.severity === 'gap').length;
  const naCount = feeds.findings.filter((finding) => finding.severity === 'na').length;
  const derived = loading ? '…' : count(riskCount);
  const derivedGaps = loading ? '…' : count(gapCount);

  const dashboard = ready(feeds.dashboard.state);
  const crawl = ready(feeds.crawl.state)?.data ?? null;
  const crawlMessage = ready(feeds.crawl.state)?.message ?? null;
  const queries = ready(feeds.queries.state);
  const demand = ready(feeds.demand.state);
  const topDemand = demand?.data[0] ?? null;

  return (
    <div className={`${styles.screen} ${styles.fade}`}>
      {problem && problem.state.status === 'error' ? (
        <div className="state-error" role="alert">
          <p>{problem.state.message}</p>
          <button
            type="button"
            className="btn btn-ghost"
            onClick={() => all.forEach((feed) => feed.reload())}
          >
            إعادة المحاولة
          </button>
        </div>
      ) : null}

      <section className={styles.card} aria-labelledby="os-home-h1">
        <div className={styles.headRow}>
          <h1 id="os-home-h1" className={styles.h1}>
            {lastVisit === null
              ? 'منذ زيارتك الأخيرة — أول فتح مسجَّل في هذا المتصفّح'
              : `منذ زيارتك الأخيرة (${dateTime(lastVisit)})`}
          </h1>
          <span className={styles.headNote}>كل رقم موسوم بمصدره — لا قيم مختلقة</span>
        </div>
        <span className={styles.localNote}>
          وقت الزيارة يُقرأ من هذا المتصفّح وحده — لا سجل زيارات في الخادم.
        </span>
        <div className={styles.statGrid}>
          <Link href="/analytics/os/actions" className={`${styles.stat} ${styles.statDanger}`}>
            <span className={`${styles.statValue} ${styles.statValueDanger}`}><bdi dir="ltr">{derived}</bdi></span>
            <span className={styles.statLabel}>اكتشافات حرِجة</span>
            <SourceChip>deriveFindings · 7 مغذّيات</SourceChip>
          </Link>
          <Link href="/analytics/os/opps" className={`${styles.stat} ${styles.statWarn}`}>
            <span className={`${styles.statValue} ${styles.statValueWarn}`}><bdi dir="ltr">{derivedGaps}</bdi></span>
            <span className={styles.statLabel}>فرص مشتقّة (تحذيرات)</span>
            <SourceChip>deriveFindings · 7 مغذّيات</SourceChip>
          </Link>
          <Link href="/growth" className={styles.stat}>
            <span className={styles.statValue}>—</span>
            <span className={styles.statLabel}>Workflows اكتملت</span>
            <span className={styles.statWhy}>لا مشغّل أتمتة — لا عدّ يُزعم</span>
            <SourceChip>غير مقاس</SourceChip>
          </Link>
          <Link href="/analytics/os/cause" className={styles.stat}>
            <span className={styles.statValue}>—</span>
            <span className={styles.statLabel}>الإيراد العضوي (٧ أيام)</span>
            <span className={styles.statWhy}>لا سلاسل زمنية محفوظة — لا نسبة تغيّر تُعرض</span>
            <SourceChip>غير مقاس</SourceChip>
          </Link>
          <Link href="/seo" className={styles.stat}>
            <span className={styles.statValue}>—</span>
            <span className={styles.statLabel}>منتجات دخلت Top 10</span>
            <span className={styles.statWhy}>يحتاج Search Console — غير مربوط</span>
            <SourceChip>غير مقاس</SourceChip>
          </Link>
        </div>
      </section>

      <section className={styles.card} aria-labelledby="os-loop-h2">
        <h2 id="os-loop-h2" className={styles.h2}>
          حلقة النمو الذاتية — خريطة القدرة: ما له بنية حقيقية اليوم
        </h2>
        <div className={styles.loopWrap}>
          {LOOP_STAGES.map((stage) => (
            <span
              key={stage.name}
              className={`${styles.loopChip} ${stage.real ? styles.loopChipReal : ''}`}
              title={stage.note}
            >
              {stage.name}
              {stage.real ? (
                <span style={{ display: 'inline-flex', inlineSize: 10, blockSize: 10 }}>
                  <OsIcon name="check" />
                </span>
              ) : null}
              <span className="sr-only">
                {stage.real ? ` — له بنية: ${stage.note}` : ` — بلا بنية: ${stage.note}`}
              </span>
            </span>
          ))}
        </div>
        <span className={styles.loopLine}>
          {crawl === null
            ? (crawlMessage ?? 'مرحلة «تحقق»: لم تُشغَّل جولة زحف بعد.')
            : crawl.previousRun === null
              ? `مرحلة «تحقق»: جولة واحدة مكتملة (#${count(crawl.runId)}) — المقارنة قبل/بعد تحتاج جولة ثانية.`
              : `مرحلة «تحقق»: الجولة #${count(crawl.runId)} مقابل #${count(crawl.previousRun.runId)} — فرق الروابط المكسورة ${crawl.previousRun.deltas.broken >= 0 ? '+' : ''}${count(crawl.previousRun.deltas.broken)}.`}
          {' '}المراحل غير المعلَّمة بلا بنية خادم بعد — سبب كل واحدة في تلميحها.
        </span>
      </section>

      <div className={styles.quadGrid}>
        <Quad
          icon="eye"
          tone={styles.quadInfo}
          name="رصد"
          source="GET /admin/dashboard"
          rows={
            dashboard === null
              ? null
              : [
                  { label: 'منتجات', value: count(dashboard.measured.products.total), ltr: true },
                  { label: 'طلبات', value: count(dashboard.measured.orders.total), ltr: true },
                  {
                    label: 'إيراد مدفوع',
                    value: `${money(dashboard.measured.revenue.totalMinor)} ${dashboard.measured.revenue.currency}`,
                    tone: styles.quadValOk,
                  },
                  { label: 'بحث مسجّل', value: count(dashboard.measured.search.queriesLogged), ltr: true },
                ]
          }
          state={feeds.dashboard.state.status}
          cta={{ label: 'كل المؤشرات', href: '/analytics' }}
        />
        <Quad
          icon="warn"
          tone={styles.quadWarn}
          name="كشف"
          source="deriveFindings — اشتقاق محلي"
          rows={
            loading
              ? null
              : [
                  { label: 'حرِج', value: count(riskCount), tone: styles.quadValDanger, ltr: true },
                  { label: 'تحذير', value: count(gapCount), tone: styles.quadValWarn, ltr: true },
                  { label: 'بنود بلا مصدر (تُعدّ «—»)', value: count(naCount), ltr: true },
                ]
          }
          state={loading ? 'loading' : 'ready'}
          cta={{ label: 'كل الاكتشافات', href: '/analytics/os/cause' }}
        />
        <Quad
          icon="bolt"
          tone={styles.quadGreen}
          name="تنفيذ"
          source="GET /admin/search/queries · GET /admin/demand"
          rows={
            queries === null || demand === null
              ? null
              : [
                  {
                    label: `بلا نتائج (${count(queries.window.days)} أيام)`,
                    value: count(queries.totals.zeroResultQueries),
                    tone: styles.quadValWarn,
                    ltr: true,
                  },
                  { label: 'طلب فائت جديد', value: count(demand.counts['new'] ?? 0), ltr: true },
                  {
                    label: topDemand === null ? 'أعلى طلب' : `أعلى طلب: «${demandLabel(topDemand)}»`,
                    value: topDemand === null ? '—' : `${count(topDemand.hits)} طلبًا`,
                  },
                ]
          }
          state={
            feeds.queries.state.status === 'ready' && feeds.demand.state.status === 'ready'
              ? 'ready'
              : feeds.queries.state.status === 'error' || feeds.demand.state.status === 'error'
                ? 'error'
                : 'loading'
          }
          cta={{ label: 'مركز الإجراءات', href: '/analytics/os/actions' }}
        />
        <Quad
          icon="shield"
          tone={styles.quadInk}
          name="تحقق"
          source="GET /admin/crawl"
          rows={
            feeds.crawl.state.status !== 'ready'
              ? null
              : crawl === null
                ? [{ label: crawlMessage ?? 'لم تُشغَّل جولة زحف بعد.', value: '—' }]
                : [
                    { label: 'صفحات مزحوفة', value: count(crawl.totals.pages), ltr: true },
                    {
                      label:
                        crawl.previousRun === null
                          ? 'روابط مكسورة'
                          : `مكسورة (${crawl.previousRun.deltas.broken >= 0 ? '+' : ''}${count(crawl.previousRun.deltas.broken)} عن السابقة)`,
                      value: count(crawl.totals.broken),
                      tone: crawl.totals.broken > 0 ? styles.quadValDanger : styles.quadValOk,
                      ltr: true,
                    },
                    {
                      label: 'صفحات يتيمة',
                      value: count(crawl.totals.orphans),
                      tone: crawl.totals.orphans > 0 ? styles.quadValWarn : styles.quadValOk,
                      ltr: true,
                    },
                  ]
          }
          state={feeds.crawl.state.status}
          cta={{ label: 'خريطة الروابط', href: '/crawl' }}
        />
      </div>
    </div>
  );
}

function Quad({
  icon,
  tone,
  name,
  source,
  rows,
  state,
  cta,
}: {
  icon: OsIconName;
  tone: string | undefined;
  name: string;
  source: string;
  /** `ltr`: القيمة لاتينية صرفة فتُعزل بـbdi. صفوفٌ قيمتُها مختلطة (مال+«ر.س»، عدد+«طلبًا») تتركه. */
  rows: { label: string; value: string; tone?: string; ltr?: boolean }[] | null;
  state: 'loading' | 'error' | 'ready';
  cta: { label: string; href: string };
}) {
  return (
    <section className={styles.quad} aria-label={name}>
      <div className={`${styles.quadHead} ${tone ?? ''}`}>
        <OsIcon name={icon} />
        <b>{name}</b>
      </div>
      <div className={styles.quadBody}>
        {state === 'loading' || rows === null ? (
          state === 'error' ? (
            <span className={`${styles.statusLine} ${styles.statusErr}`} role="alert">
              تعذّرت قراءة هذا المصدر — الخطأ معروض أعلى الشاشة.
            </span>
          ) : (
            <span className={styles.statusLine} role="status">
              جارٍ القراءة من الخادم…
            </span>
          )
        ) : (
          rows.map((row) => (
            <div className={styles.quadRow} key={row.label}>
              <span>{row.label}</span>
              <b className={row.tone}>{row.ltr ? <bdi dir="ltr">{row.value}</bdi> : row.value}</b>
            </div>
          ))
        )}
        <SourceChip>{source}</SourceChip>
        <Link href={cta.href} className={styles.quadCta}>
          {cta.label} ←
        </Link>
      </div>
    </section>
  );
}
