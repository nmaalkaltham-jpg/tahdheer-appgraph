'use client';
// UNIT: _SECTION-OVERVIEW
// FILE: _section-overview.tsx
// STYLE: seo.module.css
// PARENT: SEO-OVERVIEW-PANEL, _SECTION-CONTENT-PRODUCTS, _SECTION-SEARCH-INDEX
// USES: LIB-ADMIN-API, LIB-FORMAT, RESOURCE, _FINDINGS, _KIT
// API: /admin/crawl, /admin/dashboard, /admin/products, /admin/search/queries, /admin/search/synonyms
// MAP: docs/PROJECT_MAP.md

/**
 * القسمان ١ و٢: اللوحة التنفيذية ومحرك الفرص.
 *
 * لا «درجة صحة SEO» مُركَّبة هنا: ترجيح عواملها يحتاج نقرات وإيرادًا لكل
 * صفحة وليس لأيّهما مصدر موصول، فالحلقة تعرض نسبة واحدة يعلنها الخادم
 * بنفسه (تغطية فهرس llms.txt) والباقي مسطرة بسطها ومقامها ظاهران.
 */

import Link from 'next/link';
import { useId, useMemo, useState } from 'react';
import { useResource } from '../../../components/resource';
import type {
  Dashboard,
  Paginated,
  ProductRow,
  SearchQueriesReport,
  SynonymRow,
} from '../../../lib/admin-api';
import { count } from '../../../lib/format';
import { SEVERITY_LABELS, deriveFindings, type Finding, type FindingAction } from './_findings';
import {
  Box,
  EvidenceDialog,
  Icon,
  Kpi,
  Meter,
  NotConfigured,
  Ring,
  anyLoading,
  dataOf,
  firstProblem,
  isForbidden,
  type CrawlPayload,
  type LlmsCatalog,
  type RobotsPolicy,
  type SeoSectionKey,
  type SitemapIndex,
} from './_kit';
import styles from './seo.module.css';

/** الأسئلة الخمسة التي تحكم كل بطاقة في هذه المنصة — نصّ اللوح حرفيًا. */
const DECISION_SYSTEM: [string, string][] = [
  ['ما المشكلة؟', 'كل بطاقة تسمّيها بلغة واضحة'],
  ['لماذا تهم؟', 'زر «لماذا؟» يفتح الدليل'],
  ['كم حجم أثرها؟', 'درجة مفككة العوامل + نطاق تقديري'],
  ['ماذا أفعل الآن؟', 'إجراء موصى به قابل للتحويل لمهمة'],
  ['هل نجح الإصلاح؟', 'تحقق تقني ثم مراقبة أداء'],
];

export function useSeoSignals(days: number) {
  const dashboard = useResource<Dashboard>('/admin/dashboard');
  const sitemap = useResource<SitemapIndex>('/sitemap/index');
  const llms = useResource<LlmsCatalog>('/llms-data');
  const robots = useResource<RobotsPolicy>('/robots');
  const queries = useResource<SearchQueriesReport>(`/admin/search/queries?days=${days}`);
  const synonyms = useResource<Paginated<SynonymRow>>('/admin/search/synonyms?perPage=100');
  /*
   * مخزن الزحف موصول فعلًا: GET /admin/crawl يعيد اليتامى والمكسورة لكل جولة.
   * كان يُقرأ في مساحة /crawl وحدها بينما تعلن هذه المساحة أنه مفقود.
   */
  const crawl = useResource<CrawlPayload>('/admin/crawl');
  /* صفحة المنتجات نفسها تُقرأ مرة واحدة وتُمرَّر للأقسام بدل نداء لكل قسم. */
  const products = useResource<Paginated<ProductRow>>('/admin/products?perPage=100');

  const findings = useMemo(
    () =>
      deriveFindings({
        sitemap: dataOf(sitemap.state),
        llms: dataOf(llms.state),
        robots: dataOf(robots.state),
        queries: dataOf(queries.state),
        synonyms: dataOf(synonyms.state)?.data ?? null,
        crawl: dataOf(crawl.state),
        products: dataOf(products.state),
      }),
    [sitemap.state, llms.state, robots.state, queries.state, synonyms.state, crawl.state, products.state],
  );

  return { dashboard, sitemap, llms, robots, queries, synonyms, crawl, products, findings };
}

export type SeoSignals = ReturnType<typeof useSeoSignals>;

/**
 * مورّدان من الثمانية محروسان بمفتاحٍ غير الذي يفتح هذه المساحة.
 *
 * `analytics.read` تفتح لوحة القياس والاستعلامات والزحف، لكنّ المرادفات
 * تُقرأ بـ`search.synonyms.manage` (routes/api-admin.php:207-208) والمنتجات
 * بـ`products.read` (routes/api-admin.php:129-130). فدورٌ تحليليّ خالص يفتح
 * ‎/seo‎ فيصطدم بـ٤٠٣ مرّتين عند التركيب — لا لأنّ شيئًا تعطّل، بل لأنّ
 * مفتاحين ليسا معه. تُسمّى المفاتيح هنا مرّة، وتُقرأ حيثما احتيج إليها.
 */
export const SYNONYMS_PERMISSION = 'search.synonyms.manage';
export const PRODUCTS_PERMISSION = 'products.read';

export function StatusLine({ signals }: { signals: SeoSignals }) {
  const states = [
    signals.dashboard.state,
    signals.sitemap.state,
    signals.llms.state,
    signals.robots.state,
    signals.queries.state,
    signals.synonyms.state,
    signals.crawl.state,
    signals.products.state,
  ];
  /*
   * ٤٠٣ يخرج من حساب «المشكلة» عمدًا: مورد محروس بمفتاحٍ ناقص يُعلن غيابه في
   * لوحه وحده، فلا تحجب بطاقةُ خطأ واحدة بقيّةَ المساحة المقروءة تمامًا.
   */
  const problem = firstProblem(states.filter((state) => !isForbidden(state)));

  const denied = [
    isForbidden(signals.synonyms.state) ? SYNONYMS_PERMISSION : null,
    isForbidden(signals.products.state) ? PRODUCTS_PERMISSION : null,
  ].filter((permission): permission is string => permission !== null);

  if (anyLoading(states)) {
    return (
      <p className="state-line" role="status" aria-live="polite">
        جارٍ قراءة القياسات من الخادم…
      </p>
    );
  }

  if (problem) {
    return (
      <div className="state-error" role="alert">
        <p>{problem}</p>
        <button
          type="button"
          className="btn btn-ghost"
          onClick={() => {
            signals.dashboard.reload();
            signals.sitemap.reload();
            signals.llms.reload();
            signals.robots.reload();
            signals.queries.reload();
            signals.synonyms.reload();
            signals.crawl.reload();
            signals.products.reload();
          }}
        >
          إعادة المحاولة
        </button>
      </div>
    );
  }

  /*
   * سطرٌ واحد يقول إنّ الصورة منقوصة ولماذا. تفصيل كلّ مفتاح في لوحه، لكنّ
   * المشغّل يحتاج أن يعرف — قبل أن يقرأ رقمًا — أنّ فحوصًا لم تُقرأ أصلًا.
   */
  if (denied.length > 0) {
    return (
      <p className={styles.footnote} role="note">
        صورةٌ منقوصة: دورك لا يملك{' '}
        {denied.map((permission, index) => (
          <span key={permission}>
            {index > 0 ? ' ولا ' : ''}
            <bdi dir="ltr">{permission}</bdi>
          </span>
        ))}
        ، فمصادرها ردّت ٤٠٣ ولم تُقرأ. الألواح المعتمدة عليها تُعلن قفلها في مكانها، وبنودها غائبة عن
        «محرك الفرص» — غيابها هنا نقصُ قراءة لا شهادةَ سلامة.
      </p>
    );
  }

  return null;
}

/* ───────────── القسم ١: اللوحة التنفيذية ───────────── */

export function ExecSection({ signals }: { signals: SeoSignals }) {
  const dashboard = dataOf(signals.dashboard.state);
  const sitemap = dataOf(signals.sitemap.state);
  const llms = dataOf(signals.llms.state);
  const robots = dataOf(signals.robots.state);
  const queries = dataOf(signals.queries.state);

  const providers = sitemap?.providers ?? [];
  const activeProviders = providers.filter((provider) => provider.enabled).length;
  const coverage = llms?.coverage ?? null;
  const risks = signals.findings.filter((finding) => finding.severity === 'risk');
  const gaps = signals.findings.filter((finding) => finding.severity === 'gap');

  return (
    <section className={styles.section}>
      <StatusLine signals={signals} />

      <div className={styles.grid2}>
        <Box title="تغطية فهرس الكتالوج" icon="gauge" note="نسبة يعلنها الخادم — لا درجة مركّبة">
          <div className={styles.boxbody} style={{ display: 'flex', gap: 14, flexWrap: 'wrap', alignItems: 'center' }}>
            <Ring pct={coverage?.pct ?? null} caption="نسبة المنتجات المنشورة المؤهّلة للفهرس" />
            <div style={{ flex: 1, minInlineSize: 200 }}>
              <Meter name="مزوّدو الخرائط" done={activeProviders} total={providers.length} />
              {coverage ? (
                <>
                  <Meter name="مؤهّل للفهرس" done={coverage.included} total={coverage.published} />
                  <Meter name="بملخّص سطر" done={coverage.withOneLiner} total={coverage.included} />
                </>
              ) : null}
              {dashboard ? (
                <Meter
                  name="منتجات منشورة"
                  done={dashboard.measured.products.byStatus.published ?? 0}
                  total={dashboard.measured.products.total}
                />
              ) : null}
            </div>
          </div>
          <div className={styles.boxfoot}>
            لا تُحسب هنا «درجة صحة SEO» واحدة: ترجيح عواملها يحتاج نقرات وإيرادًا لكل صفحة، وليس لأيّهما
            مصدر موصول. كل مسطرة أعلاه بسطها ومقامها ظاهران، ولا شيء منها عامل ترتيب لدى محرّكات البحث.
          </div>
        </Box>

        <div className={styles.gridkpi}>
          <Kpi
            label="منتجات منشورة"
            icon="cart"
            value={dashboard ? count(dashboard.measured.products.byStatus.published ?? 0) : null}
            source="GET /admin/dashboard"
          />
          <Kpi
            label="مزوّدو خرائط نشطون"
            icon="map"
            value={sitemap ? `${activeProviders}/${providers.length}` : null}
            source="GET /api/v1/sitemap/index"
          />
          <Kpi
            label="روابط الكتالوج للنماذج"
            icon="doc"
            value={coverage ? count(coverage.included) : null}
            source="GET /api/v1/llms-data"
          />
          <Kpi
            label="استعلامات مسجَّلة (٢٨ يومًا)"
            icon="search"
            value={queries ? count(queries.totals.queriesLogged) : null}
            source="GET /admin/search/queries"
          />
          <Kpi
            label="بحث بلا نتائج"
            icon="warn"
            value={queries ? count(queries.totals.zeroResultQueries) : null}
            source="search_queries.zero_result"
          />
          <Kpi
            label="أصل الموقع العام"
            icon="globe"
            value={robots ? (robots.host ?? null) : null}
            source="GET /api/v1/robots → host"
            absentLabel={robots === null ? 'غير مقيس' : 'غير مضبوط'}
          />
        </div>
      </div>

      <div className={styles.grid2}>
        <Box title="مخاطر مكتشفة محليًا" icon="warn" note={`${risks.length} بندًا`}>
          <div className={styles.rows}>
            {risks.length === 0 ? (
              <p className={styles.boxbody}>
                <span className={styles.rowsub}>
                  لا مخاطر في الفحوص المحلية المتاحة. هذا لا يعني «لا مخاطر»: فحوص الزحف والترتيب غير موصولة،
                  وما لا يُقاس لا يُعلن سليمًا.
                </span>
              </p>
            ) : (
              risks.map((finding) => (
                <div className={styles.row} key={finding.id}>
                  <span className={styles.rowmain}>
                    <span className={styles.rowtitle}>{finding.title}</span>
                    <span className={styles.rowsub}>{finding.evidence[0]}</span>
                  </span>
                  <span className={`${styles.tag} ${styles.bad}`}>{finding.category}</span>
                </div>
              ))
            )}
          </div>
        </Box>

        <Box title="فجوات قابلة للإغلاق" icon="rocket" note={`${gaps.length} بندًا`}>
          <div className={styles.rows}>
            {gaps.length === 0 ? (
              <p className={styles.boxbody}>
                <span className={styles.rowsub}>لا فجوات في القياسات المتاحة.</span>
              </p>
            ) : (
              gaps.map((finding) => (
                <div className={styles.row} key={finding.id}>
                  <span className={styles.rowmain}>
                    <span className={styles.rowtitle}>{finding.title}</span>
                    <span className={styles.rowsub}>{finding.evidence[0]}</span>
                  </span>
                  <span className={`${styles.tag} ${styles.info}`}>{finding.category}</span>
                </div>
              ))
            )}
          </div>
          <div className={styles.boxfoot}>التفاصيل والدليل الكامل في قسم «محرك الفرص».</div>
        </Box>
      </div>

      <div className={styles.grid2}>
        <Box title="مقاييس أعلنها الخادم غير مُهيأة" icon="info">
          <div className={styles.rows}>
            {dashboard === null ? (
              <p className={styles.boxbody}>
                <span className={styles.rowsub}>بانتظار ردّ لوحة القياس.</span>
              </p>
            ) : dashboard.notConfigured.length === 0 ? (
              <p className={styles.boxbody}>
                <span className={styles.rowsub}>لم يعلن الخادم أي مقياس غير مُهيأ.</span>
              </p>
            ) : (
              dashboard.notConfigured.map((metric) => (
                <div className={styles.row} key={metric.key}>
                  <span className={styles.rowmain}>
                    <span className={styles.rowtitle}>{metric.label}</span>
                    <span className={styles.rowsub}>{metric.reason}</span>
                  </span>
                  <span className={`${styles.tag} ${styles.warn}`}>
                    <bdi dir="ltr">{metric.state}</bdi>
                  </span>
                </div>
              ))
            )}
          </div>
          <div className={styles.boxfoot}>
            هذه القائمة تأتي كما هي من <bdi dir="ltr">GET /admin/dashboard → notConfigured</bdi> — الواجهة
            لا تضيف إليها ولا تحذف منها.
          </div>
        </Box>

        <NotConfigured
          title="عائد SEO التجاري"
          reason="ربط الإيراد بالصفحة الهابطة يحتاج مصدر جلسات عضوية ونسب إسناد. لا Search Console ولا GA4 موصولان، ولا نقطة نهاية إسناد في هذا النظام — فلا نعرض إيرادًا عضويًا ولا نموًّا ولا معدّل تحويل، ولا صفرًا يوهم بأن القياس تمّ."
          needs={['Google Search Console', 'GA4 أو بديل تحليلات', 'GET /admin/seo/attribution']}
        />
      </div>

      <dl className={styles.doctrine}>
        <div style={{ flex: '0 0 auto', fontSize: 12, fontWeight: 900, color: 'var(--ink)' }}>منظومة القرار —</div>
        {DECISION_SYSTEM.map(([question, answer]) => (
          <div className={styles.doctrinepair} key={question}>
            <dt>{question}</dt>
            <dd>{answer}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

/* ───────────── القسم ٢: محرك الفرص ───────────── */

/**
 * إجراءات البند: كل زرّ إمّا وجهة موجودة فعلًا في هذه اللوحة (مسار مُتحقَّق
 * منه أو قسم في هذه المساحة)، وإمّا زرّ معطَّل يُعلن سبب تعطّله نصًّا مقروءًا
 * ومربوطًا به بـaria-describedby — لا زرّ يبدو صالحًا ولا يفعل شيئًا.
 */
function FindingActions({
  finding,
  onSection,
}: {
  finding: Finding;
  onSection: (key: SeoSectionKey) => void;
}) {
  const noteId = useId();
  const blocked = finding.actions.filter((action) => action.blockedReason);
  const hasNote = blocked.length > 0 || Boolean(finding.blocked);

  const render = (action: FindingAction) => {
    const body = (
      <>
        <Icon name={action.icon} />
        {action.label}
      </>
    );

    if (action.blockedReason) {
      return (
        <button
          key={action.label}
          type="button"
          className={`${styles.btn} ${styles.ghost} ${styles.small}`}
          disabled
          aria-describedby={noteId}
        >
          {body}
        </button>
      );
    }

    if (action.href) {
      return (
        <Link key={action.label} className={`${styles.btn} ${styles.tinted} ${styles.small}`} href={action.href}>
          {body}
        </Link>
      );
    }

    return (
      <button
        key={action.label}
        type="button"
        className={`${styles.btn} ${styles.tinted} ${styles.small}`}
        onClick={() => {
          if (action.section) onSection(action.section);
        }}
      >
        {body}
      </button>
    );
  };

  return (
    <div className={styles.actions}>
      <div className={styles.actionrow}>{finding.actions.map(render)}</div>
      {hasNote ? (
        <p className={styles.blockednote} id={noteId}>
          {finding.blocked ? `${finding.blocked} ` : ''}
          {blocked.map((action) => `«${action.label}» — ${action.blockedReason}`).join(' · ')}
        </p>
      ) : null}
    </div>
  );
}

/** صفوف الخطورة الثلاثة كما يعلنها اللوح — أزرار ثابتة لا تختفي بغياب البنود. */
const SEVERITY_FILTERS: Finding['severity'][] = ['risk', 'gap', 'na'];

export function OpportunitiesSection({
  signals,
  onSection,
}: {
  signals: SeoSignals;
  onSection: (key: SeoSectionKey) => void;
}) {
  const [open, setOpen] = useState<Finding | null>(null);
  const [category, setCategory] = useState<string | null>(null);
  const [severity, setSeverity] = useState<Finding['severity'] | null>(null);

  const categories = useMemo(() => {
    const counts = new Map<string, number>();
    for (const finding of signals.findings) counts.set(finding.category, (counts.get(finding.category) ?? 0) + 1);
    return [...counts.entries()];
  }, [signals.findings]);

  const rows = signals.findings.filter(
    (finding) =>
      (category === null || finding.category === category) &&
      (severity === null || finding.severity === severity),
  );

  return (
    <section className={styles.section}>
      <StatusLine signals={signals} />

      <div className={styles.doctrine} role="group" aria-label="تصفية حسب الخطورة">
        <button
          type="button"
          className={`${styles.tag} ${styles.filter} ${severity === null ? styles.dark : ''}`}
          onClick={() => setSeverity(null)}
          aria-pressed={severity === null}
        >
          الكل <bdi dir="ltr">{signals.findings.length}</bdi>
        </button>
        {SEVERITY_FILTERS.map((key) => (
          <button
            key={key}
            type="button"
            className={`${styles.tag} ${styles.filter} ${severity === key ? styles.dark : ''}`}
            onClick={() => setSeverity(key)}
            aria-pressed={severity === key}
          >
            {SEVERITY_LABELS[key]}{' '}
            <bdi dir="ltr">{signals.findings.filter((finding) => finding.severity === key).length}</bdi>
          </button>
        ))}
        <span className={styles.boxnote}>
          «لا ينطبق» بند بلا مصدر: يُعرض عدده «—» ولا يُحتسَب — الصفر هنا يُقرأ «لا أخطاء» والحقيقة «لا بيانات».
        </span>
      </div>

      <div className={styles.doctrine} role="group" aria-label="تصفية حسب التصنيف">
        <button
          type="button"
          className={`${styles.tag} ${styles.filter} ${category === null ? styles.dark : ''}`}
          onClick={() => setCategory(null)}
          aria-pressed={category === null}
        >
          كل التصنيفات <bdi dir="ltr">{signals.findings.length}</bdi>
        </button>
        {categories.map(([name, n]) => (
          <button
            key={name}
            type="button"
            className={`${styles.tag} ${styles.filter} ${category === name ? styles.dark : ''}`}
            onClick={() => setCategory(name)}
            aria-pressed={category === name}
          >
            {name} <bdi dir="ltr">{n}</bdi>
          </button>
        ))}
        <span className={styles.boxnote}>الترتيب: كما اكتُشفت — لا ترتيب بدرجة أثر غير مقيسة</span>
      </div>

      {rows.length === 0 ? (
        <NotConfigured
          kind="NO_DATA"
          title="لا بنود تطابق هذه التصفية"
          reason="محرك الفرص هنا لا يخترع بندًا: كل بطاقة تُشتقّ من ردّ نقطة نهاية قائمة (خرائط الموقع، فهرس الكتالوج، سياسة robots، سجل البحث الداخلي، مخزن الزحف، قائمة المنتجات). حين تخلو هذه المصادر من فجوة لا تُعرض بطاقات — وليس معنى ذلك أن الموقع بلا فرص، بل أن فحوص الترتيب والأداء الخارجي غير موصولة."
          needs={['Google Search Console (نقرات · ظهور · موضع)', 'مصدر إيراد لكل صفحة هابطة']}
        />
      ) : (
        rows.map((finding) => (
          <article className={`${styles.opp} ${finding.severity === 'na' ? styles.oppna : ''}`} key={finding.id}>
            <div className={styles.oppscore}>
              <span className={styles.scorebadge}>
                بلا
                <br />
                درجة
              </span>
              <span
                className={`${styles.tag} ${
                  finding.severity === 'risk' ? styles.bad : finding.severity === 'gap' ? styles.warn : ''
                }`}
              >
                {SEVERITY_LABELS[finding.severity]}
              </span>
              <span className={styles.oppcount}>
                {/* count() تعيد «—» للقيمة الغائبة — لا صفرًا يُقرأ «لا أخطاء». */}
                <bdi dir="ltr">{count(finding.count)}</bdi>
              </span>
            </div>

            <div className={styles.oppmain}>
              <span className={styles.opptitle}>{finding.title}</span>
              <span className={styles.oppwhy}>{finding.evidence.join(' · ')}</span>
              <div className={styles.chips}>
                <span className={styles.chip}>التصنيف: {finding.category}</span>
                <span className={styles.chip}>المصدر: {finding.source}</span>
                {finding.severity === 'na' ? (
                  <span className={`${styles.chip} ${styles.estimate}`}>مقاس: لا شيء — البند بلا مصدر</span>
                ) : (
                  <span className={`${styles.chip} ${styles.measured}`}>مقاس: {finding.measured}</span>
                )}
                <span className={`${styles.chip} ${styles.estimate}`}>
                  تقدير: غير متاح — لا نقرات ولا إيراد لكل صفحة
                </span>
              </div>
              <FindingActions finding={finding} onSection={onSection} />
            </div>

            <div className={styles.oppside}>
              <button type="button" className={`${styles.btn} ${styles.tinted}`} onClick={() => setOpen(finding)}>
                لماذا؟ — الدليل
              </button>
              <button type="button" className={`${styles.btn} ${styles.ghost}`} disabled>
                تحويل إلى مهمة
              </button>
              <span className={styles.footnote} style={{ textAlign: 'center' }}>
                لا مخزن مهام: <bdi dir="ltr">/admin/seo/tasks</bdi> غير موجود
              </span>
            </div>
          </article>
        ))
      )}

      {open ? (
        <EvidenceDialog title={open.title} subtitle={open.rule} onClose={() => setOpen(null)}>
          <div className={styles.dialogbody}>
            <div className={styles.dialogcol}>
              <div>
                <span className={styles.subhead}>تفكيك الدرجة — لماذا لا توجد درجة</span>
                <ul className={styles.factlist}>
                  {open.factors.map((factor) => (
                    <li key={factor.name}>
                      <span className={`${styles.tag} ${factor.measured ? styles.ok : styles.warn}`}>
                        {factor.measured ? 'مقيس' : 'غير مقيس'}
                      </span>{' '}
                      <b>{factor.name}</b> — {factor.note}
                    </li>
                  ))}
                </ul>
                <p className={styles.footnote} style={{ marginBlockStart: 6 }}>
                  أربعة من خمسة عوامل غير مقيسة، فأي رقم مركّب منها سيكون تقديرًا مُقنَّعًا بثقة زائفة. تُعرض
                  العوامل مفكّكة، ويبقى القرار للمشغّل.
                </p>
              </div>

              <div>
                <span className={styles.subhead}>الأدلة والقياسات</span>
                <ul className={styles.factlist}>
                  {open.evidence.map((line) => (
                    <li key={line}>{line}</li>
                  ))}
                </ul>
                <code className={styles.codeline} dir="ltr">
                  {open.rule}
                </code>
              </div>

              {open.urls.length > 0 ? (
                <div>
                  <span className={styles.subhead}>المصادر المتأثرة</span>
                  <ul className={styles.factlist}>
                    {open.urls.map((url) => (
                      <li key={url}>
                        <bdi dir="ltr" className={`${styles.mono} ${styles.strong}`}>
                          {url}
                        </bdi>
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}
            </div>

            <div className={styles.dialogcol}>
              <div className={`${styles.callout} ${styles.act}`}>
                <span className={styles.calloutlabel}>الإجراء الموصى به</span>
                <span className={styles.callouttext}>{open.action}</span>
                <FindingActions finding={open} onSection={onSection} />
              </div>
              <div className={styles.callout}>
                <span className={styles.calloutlabel}>النتيجة المتوقعة</span>
                <span className={styles.callouttext}>
                  غير مقيسة. قياس النتيجة يحتاج نقرات وظهورًا قبل التعديل وبعده، ولا مصدر لهما — وأي «تحسّن
                  متوقع» يُكتب هنا سيكون وعدًا بلا سند.
                </span>
              </div>
              <div className={`${styles.callout} ${styles.risk}`}>
                <span className={styles.calloutlabel}>المخاطر والتحفظات</span>
                <span className={styles.callouttext}>{open.risks}</span>
              </div>
              <button type="button" className={styles.btn} disabled>
                تحويل إلى مهمة — لا مخزن مهام
              </button>
            </div>
          </div>
        </EvidenceDialog>
      ) : null}
    </section>
  );
}
