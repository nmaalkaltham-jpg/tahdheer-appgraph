'use client';
// UNIT: _SECTION-SEARCH-INDEX
// FILE: _section-search-index.tsx
// STYLE: seo.module.css
// PARENT: SEO-OVERVIEW-PANEL
// USES: LIB-ADMIN-API, LIB-FORMAT, RESOURCE, _KIT, _SECTION-OVERVIEW
// API: POST /admin/search/synonyms
// MAP: docs/PROJECT_MAP.md

/**
 * القسمان ٣ و٤: ذكاء البحث، والفهرسة والزحف.
 *
 * ذكاء البحث هنا هو البحث الداخلي فقط — وهو المصدر الوحيد الموصول. كل ما
 * يحتاج Search Console (نقرات، ظهور، CTR، مركز، تعارض نية، تنافس داخلي،
 * جاهزية البحث التوليدي) يُعلن «غير مُهيأ» باسم التكامل الناقص.
 *
 * الفهرسة تفحص فعلًا: مفتّش قابلية الفهرسة وحلّال التحويلات وسياسة robots
 * ومركز الخرائط كلها نداءات حقيقية على نقاط نهاية قائمة.
 */

import { useCallback, useState } from 'react';
import { EmptyRows } from '../../../components/resource';
import { api, firstError, type SynonymRow } from '../../../lib/admin-api';
import { count, dateTime } from '../../../lib/format';
import type { SeoSignals } from './_section-overview';
import { StatusLine, SYNONYMS_PERMISSION } from './_section-overview';
import {
  Box,
  CrawlMeasures,
  Forbidden,
  Icon,
  Kpi,
  NotConfigured,
  VERDICT_LABELS,
  dataOf,
  isForbidden,
  type IndexabilityResult,
  type RedirectResolution,
  type SitemapDetail,
} from './_kit';
import styles from './seo.module.css';

/* ───────────── القسم ٣: ذكاء البحث ───────────── */

export function SearchSection({
  signals,
  days,
  onDays,
}: {
  signals: SeoSignals;
  days: number;
  onDays: (days: number) => void;
}) {
  const report = dataOf(signals.queries.state);
  const synonyms = dataOf(signals.synonyms.state)?.data ?? null;

  return (
    <section className={styles.section}>
      <StatusLine signals={signals} />

      <NotConfigured
        title="أداء البحث الخارجي غير موصول"
        reason="النقرات والظهور وCTR والمركز وفروق ٢٨ يومًا، ومعها محرك فرص CTR وتعارض نية البحث والتنافس الداخلي وجاهزية البحث التوليدي — كلها تُشتقّ من Google Search Console. لا ربط OAuth ولا استيراد CSV في هذا النظام، ولا نقطة نهاية تخزّن الحقول محليًا بعد الاستيراد. فلا جدول أداء ولا رسم اتجاه ولا فروق."
        needs={[
          'Google Search Console (OAuth أو CSV)',
          'GET /admin/seo/gsc/queries',
          'query · page · clicks · impressions · ctr · position · date · country · device',
        ]}
      />

      <Box
        title="البحث الداخلي — سجل حقيقي"
        icon="search"
        note="المصدر الوحيد الموصول لنية الزائر"
        foot="سجل البحث الداخلي يقيس ما بحث عنه زوّار متجرك داخل المتجر — لا علاقة له بترتيب محرّكات البحث ولا يُقدَّم بديلًا عنه."
      >
        <div className={styles.inspect}>
          <label htmlFor="seo-window">
            <span>النافذة الزمنية</span>
            <select id="seo-window" value={days} onChange={(event) => onDays(Number(event.target.value))}>
              <option value={7}>آخر ٧ أيام</option>
              <option value={28}>آخر ٢٨ يومًا</option>
              <option value={90}>آخر ٩٠ يومًا</option>
            </select>
          </label>
          <div className={styles.gridkpi} style={{ flex: '2 1 320px' }}>
            <Kpi
              label="استعلامات مسجَّلة"
              value={report ? count(report.totals.queriesLogged) : null}
              source="search_queries"
            />
            <Kpi
              label="استعلامات مميّزة"
              value={report ? count(report.totals.distinctQueries) : null}
              source="distinct normalized_query"
            />
            <Kpi
              label="بلا نتائج"
              value={report ? count(report.totals.zeroResultQueries) : null}
              source="search_queries.zero_result"
            />
          </div>
        </div>

        <div className="table-scroll" tabIndex={0} role="region" aria-label="أكثر استعلامات البحث الداخلي تكرارًا">
          <table className={styles.table}>
            <caption>الأكثر تكرارًا داخل المتجر</caption>
            <thead>
              <tr>
                <th scope="col">الاستعلام</th>
                <th scope="col">التكرار</th>
                <th scope="col">منه بلا نتائج</th>
                <th scope="col">آخر ظهور</th>
              </tr>
            </thead>
            <tbody>
              {report === null ? (
                <EmptyRows colSpan={4} message="بانتظار ردّ الخادم." />
              ) : report.topQueries.length === 0 ? (
                <EmptyRows colSpan={4} message="لا استعلامات مسجَّلة في هذه النافذة." />
              ) : (
                report.topQueries.map((row) => (
                  <tr key={`top-${row.query}`}>
                    <th scope="row">{row.query}</th>
                    <td className={styles.num}>
                      <bdi dir="ltr">{count(row.count)}</bdi>
                    </td>
                    <td className={styles.num}>
                      <bdi dir="ltr">{count(row.zeroResultCount)}</bdi>
                    </td>
                    <td>
                      <bdi dir="ltr">{dateTime(row.lastSeenAt)}</bdi>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </Box>

      {/*
       * لوحٌ واحد مقفل، فالقفل يُعلن في مكانه لا في رأس المساحة: بقيّة هذا
       * القسم (سجل البحث الداخلي) مقروءةٌ بـ`analytics.read` وحدها وتبقى.
       */}
      {isForbidden(signals.synonyms.state) ? (
        <Forbidden
          title="استعلامات بلا نتائج — وتغطية المرادفات"
          permission={SYNONYMS_PERMISSION}
          reads="جدول المرادفات وحده يقول إن كان لكلّ استعلامٍ بلا نتائج مدخلٌ يغطّيه"
        />
      ) : (
        <ZeroResultBridge
          rows={report?.zeroResultQueries ?? null}
          synonyms={synonyms}
          onSaved={() => {
            signals.synonyms.reload();
            signals.queries.reload();
          }}
        />
      )}
    </section>
  );
}

/**
 * جسر «بلا نتائج ← مرادف»: نداء POST حقيقي على /admin/search/synonyms.
 * الجدول الكامل لإدارة المرادفات يعيش في مساحة «ذكاء البحث» — هنا فقط
 * إغلاق الفجوة التي اكتشفها السجل، بمصطلح مأخوذ من الاستعلام نفسه.
 */
function ZeroResultBridge({
  rows,
  synonyms,
  onSaved,
}: {
  rows: { query: string; count: number; lastSeenAt: string | null }[] | null;
  synonyms: SynonymRow[] | null;
  onSaved: () => void;
}) {
  const [target, setTarget] = useState('');
  const [term, setTerm] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const mapped = new Set((synonyms ?? []).map((row) => row.term.trim()));

  const submit = useCallback(async () => {
    if (term === null || target.trim() === '') return;
    setPending(true);
    setError(null);
    setDone(null);
    const result = await api.post('/admin/search/synonyms', {
      term,
      synonym: target.trim(),
      direction: 'one_way',
    });
    setPending(false);
    if (!result.ok) {
      setError(firstError(result));
      return;
    }
    setDone(`أُضيف مرادف للمصطلح «${term}». المصدر «يدوي» ولا تبدّله الواجهة.`);
    setTerm(null);
    setTarget('');
    onSaved();
  }, [term, target, onSaved]);

  return (
    <Box
      title="استعلامات بلا نتائج — وتغطية المرادفات"
      icon="link"
      note="فجوة قابلة للإغلاق بنداء حقيقي"
      foot="إضافة مرادف تغيّر استرجاع البحث لكل الزوّار فورًا. المرادف الخاطئ يلوّث نتائج صحيحة، ولذلك لا يُقترح آليًا ولا يُطبَّق بلا مصطلح هدف تكتبه بنفسك."
    >
      {error ? (
        <p className="form-error" role="alert" style={{ marginBlock: 12, marginInline: 13 }}>
          {error}
        </p>
      ) : null}
      {done ? (
        <p className="state-ok" role="status" aria-live="polite" style={{ marginInline: 13 }}>
          {done}
        </p>
      ) : null}

      <div className="table-scroll" tabIndex={0} role="region" aria-label="استعلامات بلا نتائج وتغطية المرادفات لها">
        <table className={styles.table}>
          <caption>كل صف يقول إن كان له مدخل مرادفات أم لا</caption>
          <thead>
            <tr>
              <th scope="col">الاستعلام</th>
              <th scope="col">التكرار</th>
              <th scope="col">آخر ظهور</th>
              <th scope="col">تغطية المرادفات</th>
              <th scope="col">إجراء</th>
            </tr>
          </thead>
          <tbody>
            {rows === null ? (
              <EmptyRows colSpan={5} message="بانتظار ردّ الخادم." />
            ) : rows.length === 0 ? (
              <EmptyRows colSpan={5} message="لا استعلامات بلا نتائج في هذه النافذة." />
            ) : (
              rows.map((row) => {
                const covered = mapped.has(row.query.trim());
                return (
                  <tr key={`zero-${row.query}`}>
                    <th scope="row">{row.query}</th>
                    <td className={styles.num}>
                      <bdi dir="ltr">{count(row.count)}</bdi>
                    </td>
                    <td>
                      <bdi dir="ltr">{dateTime(row.lastSeenAt)}</bdi>
                    </td>
                    <td>
                      <span className={`${styles.tag} ${covered ? styles.ok : styles.bad}`}>
                        {covered ? 'له مرادف' : 'بلا مرادف'}
                      </span>
                    </td>
                    <td>
                      {covered ? (
                        <span className={styles.footnote}>مغطّى</span>
                      ) : (
                        <button
                          type="button"
                          className={`${styles.btn} ${styles.ghost} ${styles.small}`}
                          onClick={() => {
                            setTerm(row.query);
                            setTarget('');
                            setDone(null);
                            setError(null);
                          }}
                        >
                          اربطه بمصطلح
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      {term === null ? null : (
        <form
          className={styles.inspect}
          onSubmit={(event) => {
            event.preventDefault();
            void submit();
          }}
        >
          <label htmlFor="syn-target">
            <span>
              المصطلح المصدر: «{term}» ← المرادف في الكتالوج
            </span>
            <input
              id="syn-target"
              value={target}
              onChange={(event) => setTarget(event.target.value)}
              placeholder="اكتب المصطلح الذي يجب أن يجده هذا البحث"
              required
            />
          </label>
          <button type="submit" className={styles.btn} disabled={pending || target.trim() === ''}>
            {pending ? 'جارٍ الحفظ…' : 'احفظ المرادف'}
          </button>
          <button type="button" className={`${styles.btn} ${styles.ghost}`} onClick={() => setTerm(null)}>
            إلغاء
          </button>
        </form>
      )}
    </Box>
  );
}

/* ───────────── القسم ٤: الفهرسة والزحف ───────────── */

export function IndexationSection({ signals }: { signals: SeoSignals }) {
  const sitemap = dataOf(signals.sitemap.state);
  const robots = dataOf(signals.robots.state);

  return (
    <section className={styles.section}>
      <StatusLine signals={signals} />

      <div className={styles.grid2}>
        <UrlInspector />
        <RedirectProbe />
      </div>

      <SitemapCenter providers={sitemap?.providers ?? null} />

      <div className={styles.grid2}>
        {/*
          العنوان كان «المُنفَّذة» وهو ادّعاء رصدٍ لقيمةٍ مُعلَنة: ما يُعرض هنا
          سياسة Laravel وقت طلب اللوحة، لا الملفّ الذي تقرؤه الزواحف. وهما
          يفترقان بحقّ: `apps/storefront/app/robots.ts` يتقدّم بأصله على
          `host` و`sitemap` القادمين من الـAPI — عمدًا وبسببٍ موثَّق — ويُبنى
          وقت البناء، فيتجمّد على آخر نشرة للمتجر.

          قواعد المنع وحدها هي القرار المخدوم فعلًا (ما لم يتعذّر الـAPI،
          فيستعمل المتجر قائمة منعٍ مغلقة). ولا شيء هنا يجلب `/robots.txt`
          الحيّ، فلا يجوز أن يُسمّى ما يُعرض «منفَّذًا».
        */}
        <Box
          title="سياسة robots المصدر"
          icon="robot"
          note="من سياسة الفهرسة نفسها التي تحكم الخرائط — لا من ‎/robots.txt‎ المخدوم"
          foot="لا تُعلن هنا قاعدة إلا إذا كانت السياسة تمنعها فعلًا: القائمة تُبنى بفحص كل مسار مقترح ضد السياسة قبل إدراجه."
        >
          <div className={styles.boxbody}>
            {robots === null ? (
              <p className={styles.lede}>بانتظار ردّ الخادم.</p>
            ) : (
              <>
                <p className={styles.lede}>
                  <b>User-agent:</b> <bdi dir="ltr">{robots.userAgent}</bdi>
                </p>
                <ul className={styles.absentneeds} style={{ marginBlockStart: 8 }}>
                  {robots.disallow.map((path) => (
                    <li key={path}>
                      <bdi dir="ltr">Disallow: {path}</bdi>
                    </li>
                  ))}
                </ul>
                <div className={styles.gridkpi} style={{ marginBlockStart: 10 }}>
                  <Kpi
                    label="خريطة معلنة (سياسة)"
                    value={robots.sitemap}
                    source="سياسة الخادم — المتجر يتقدّم بأصله"
                    icon="map"
                    absentLabel="غير مضبوط"
                  />
                  <Kpi
                    label="المضيف (سياسة)"
                    value={robots.host}
                    source="سياسة الخادم — المتجر يتقدّم بأصله"
                    icon="globe"
                    absentLabel="غير مضبوط"
                  />
                </div>
                <p className={styles.footnote} style={{ marginBlockStart: 8 }}>
                  القيمتان أعلاه سياسة الخادم لا ما يُخدَم: المتجر يكتب مضيفه وخريطته من أصله هو
                  (<bdi dir="ltr">NEXT_PUBLIC_SITE_URL</bdi>) لأنّ الملفّ يُقدَّم منه. للتحقّق ممّا تقرؤه
                  الزواحف افتح <bdi dir="ltr">/robots.txt</bdi> على نطاق المتجر.
                </p>
                {robots.sitemap === null ? (
                  <p className={styles.footnote} style={{ marginBlockStart: 8 }}>
                    القيمتان غائبتان لأن أصل الموقع العام غير مضبوط. الخادم يرفض تخمين نطاق: عنوان مطلق
                    مُخمَّن يفسد canonical وكل رابط في الخريطة.
                  </p>
                ) : null}
              </>
            )}
          </div>
        </Box>

        <CrawlMeasures state={signals.crawl.state} title="قياسات الزحف الداخلي" />

        {/*
          الروابط الداخلية والصفحات المكسورة مقيسة أعلاه من مخزن زحف موجود.
          ما يبقى غير مُهيأ هو ما يأتي من خارج هذا الزحف: زيارات العناكب
          الحقيقية وطابور IndexNow.
        */}
        <NotConfigured
          title="زيارات العناكب وميزانية الزحف وIndexNow"
          reason="عدّ زيارات Googlebot وBingbot وميزانية الزحف يحتاج مسارًا يستقبل سجلات خادم الويب، وطابور IndexNow يحتاج تكاملًا مع الخدمة نفسها. لا هذا ولا ذاك في هذا النظام، وعرض صفر زيارة كذب لأن التسجيل لم يبدأ. أمّا الزحف الداخلي — الصفحات والروابط والمكسور واليتيم — فمقيس فعلًا من مخزن الزحف المعروض بجواره."
          needs={['مسار يستقبل سجلات خادم الويب (bot hits)', 'GET /admin/seo/crawl/bots', 'POST /admin/seo/indexnow']}
        />
      </div>
    </section>
  );
}

/** مفتّش قابلية الفهرسة: نداء حقيقي يعيد الحكم وسببه والقاعدة التي أنتجته. */
function UrlInspector() {
  const [path, setPath] = useState('/products/');
  const [pending, setPending] = useState(false);
  const [result, setResult] = useState<IndexabilityResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function inspect() {
    setPending(true);
    setError(null);
    setResult(null);
    const response = await api.get<IndexabilityResult>(
      `/seo/indexability?path=${encodeURIComponent(path.trim())}`,
    );
    setPending(false);
    if (!response.ok) {
      setError(response.message);
      return;
    }
    setResult(response.data);
  }

  const tone =
    result === null
      ? ''
      : result.verdict === 'eligible'
        ? styles.eligible
        : result.verdict === 'excluded'
          ? styles.excluded
          : styles.monitor;

  return (
    <Box
      title="مفتّش قابلية الفهرسة"
      icon="globe"
      note="فحص حيّ على السياسة"
      foot="الحكم يصدر من سياسة الفهرسة الحقيقية، وهي نفسها التي تقرّر دخول الرابط في الخريطة وllms.txt. لا يوجد رأي ثانٍ يمكن أن يخالفها."
    >
      <form
        className={styles.inspect}
        onSubmit={(event) => {
          event.preventDefault();
          void inspect();
        }}
      >
        <label htmlFor="idx-path">
          <span>المسار المراد فحصه</span>
          <input
            id="idx-path"
            dir="ltr"
            value={path}
            onChange={(event) => setPath(event.target.value)}
            placeholder="/products/my-product"
            required
          />
        </label>
        <button type="submit" className={styles.btn} disabled={pending || path.trim() === ''}>
          {pending ? 'جارٍ الفحص…' : 'افحص'}
        </button>
      </form>

      {error ? (
        <p className="form-error" role="alert" style={{ marginBlock: 0, marginInline: 13 }}>
          {error}
        </p>
      ) : null}

      {result ? (
        <div className={`${styles.verdict} ${tone}`} role="status" aria-live="polite">
          <span className={styles.absenthead}>
            <Icon name={result.verdict === 'eligible' ? 'check' : 'warn'} />
            <span className={styles.absenttitle}>{VERDICT_LABELS[result.verdict] ?? result.verdict}</span>
            <span className={styles.tag}>
              <bdi dir="ltr">{result.robots}</bdi>
            </span>
          </span>
          <span className={styles.verdictline}>{result.reason}</span>
          <code className={styles.codeline} dir="ltr">
            rule: {result.rule} · path: {result.path}
          </code>
        </div>
      ) : null}
    </Box>
  );
}

/** حلّال التحويلات: نفس الجدول الذي يستشيره المتجر قبل عرض 404. */
function RedirectProbe() {
  const [path, setPath] = useState('/');
  const [pending, setPending] = useState(false);
  const [result, setResult] = useState<RedirectResolution | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function resolve() {
    setPending(true);
    setError(null);
    setResult(null);
    const response = await api.get<RedirectResolution>(
      `/redirects/resolve?path=${encodeURIComponent(path.trim())}`,
    );
    setPending(false);
    if (!response.ok) {
      setError(response.message);
      return;
    }
    setResult(response.data);
  }

  return (
    <Box
      title="حلّال التحويلات"
      icon="link"
      note="الجدول الذي يُستشار قبل 404"
      foot="الفحص يسجّل إصابة للقاعدة المطابقة كما يفعل المتجر — وهذا مقصود: عدّاد الإصابات يجب أن يعكس كل استشارة حقيقية."
    >
      <form
        className={styles.inspect}
        onSubmit={(event) => {
          event.preventDefault();
          void resolve();
        }}
      >
        <label htmlFor="rdx-path">
          <span>المسار القديم</span>
          <input
            id="rdx-path"
            dir="ltr"
            value={path}
            onChange={(event) => setPath(event.target.value)}
            placeholder="/old-path"
            required
          />
        </label>
        <button type="submit" className={styles.btn} disabled={pending || path.trim() === ''}>
          {pending ? 'جارٍ الاستعلام…' : 'استعلم'}
        </button>
      </form>

      {error ? (
        <p className="form-error" role="alert" style={{ marginBlock: 0, marginInline: 13 }}>
          {error}
        </p>
      ) : null}

      {result ? (
        <div className={`${styles.verdict} ${result.match ? styles.eligible : ''}`} role="status" aria-live="polite">
          {result.match === null ? (
            <span className={styles.verdictline}>
              لا قاعدة تحويل تطابق هذا المسار. الغياب جواب طبيعي لا خطأ — المتجر سيمضي إلى المسار العادي ثم
              صفحة 404.
            </span>
          ) : (
            <>
              <span className={styles.absenthead}>
                <Icon name="check" />
                <span className={styles.absenttitle}>قاعدة مطابقة</span>
                <span className={`${styles.tag} ${styles.ok}`}>
                  <bdi dir="ltr">{result.match.statusCode}</bdi>
                </span>
              </span>
              <code className={styles.codeline} dir="ltr">
                {result.match.source} → {result.match.target} (rule #{result.match.ruleId})
              </code>
            </>
          )}
        </div>
      ) : null}
    </Box>
  );
}

/**
 * مركز الخرائط: كل مزوّد يعلن حالته وسبب سُباته كما سجّله الخادم — نمط
 * «معطَّل مع السبب» من لوح مركز SEO. لا مفتاح تفعيل هنا لأن التفعيل نتيجة
 * بناء مسارات النطاق لا خيارًا في اللوحة.
 */
function SitemapCenter({ providers }: { providers: import('./_kit').SitemapProviderRow[] | null }) {
  const [openProvider, setOpenProvider] = useState<string | null>(null);
  const [detail, setDetail] = useState<SitemapDetail | null>(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function load(provider: string) {
    setOpenProvider(provider);
    setDetail(null);
    setError(null);
    setPending(true);
    const response = await api.get<SitemapDetail>(`/sitemap/${provider}`);
    setPending(false);
    if (!response.ok) {
      setError(response.message);
      return;
    }
    setDetail(response.data);
  }

  return (
    <Box
      title="مركز تحكم خرائط الموقع"
      icon="map"
      note="حالة كل مزوّد وسبب سُباته"
      foot="المزوّد الخامل يعيد مصفوفة روابط فارغة ومعها السبب — لا رابط بديل ولا عنوان مُخمَّن. الخريطة لا تُرتِّب صفحة واحدة؛ مهمتها ألّا تبقى صفحة تستحق الظهور غير مكتشَفة."
    >
      <div className="table-scroll" tabIndex={0} role="region" aria-label="مزوّدو خرائط الموقع وحالاتهم">
        <table className={styles.table}>
          <caption>مزوّدو الخرائط المسجَّلون في الخادم</caption>
          <thead>
            <tr>
              <th scope="col">المزوّد</th>
              <th scope="col">الحالة</th>
              <th scope="col">السبب المسجَّل</th>
              <th scope="col">المسار</th>
              <th scope="col">الروابط</th>
            </tr>
          </thead>
          <tbody>
            {providers === null ? (
              <EmptyRows colSpan={5} message="بانتظار ردّ الخادم." />
            ) : providers.length === 0 ? (
              <EmptyRows colSpan={5} message="لا مزوّدي خرائط مسجَّلين." />
            ) : (
              providers.map((provider) => (
                <tr key={provider.provider}>
                  <th scope="row">
                    {provider.label}
                    <span className={styles.rowsub}>
                      <bdi dir="ltr">{provider.provider}</bdi>
                    </span>
                  </th>
                  <td>
                    <span className={`${styles.tag} ${provider.enabled ? styles.ok : styles.warn}`}>
                      {provider.enabled ? 'نشط' : 'خامل'}
                    </span>
                  </td>
                  <td>{provider.enabled ? <span className={styles.footnote}>—</span> : provider.reason}</td>
                  <td>
                    <bdi dir="ltr" className={styles.mono}>
                      {provider.url}
                    </bdi>
                  </td>
                  <td>
                    <button
                      type="button"
                      className={`${styles.btn} ${styles.ghost} ${styles.small}`}
                      onClick={() => void load(provider.provider)}
                      aria-expanded={openProvider === provider.provider}
                    >
                      {openProvider === provider.provider && pending ? 'جارٍ…' : 'اقرأ الخريطة'}
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {error ? (
        <p className="form-error" role="alert" style={{ marginBlock: 12, marginInline: 13 }}>
          {error}
        </p>
      ) : null}

      {detail ? (
        <div className={styles.boxbody} role="status" aria-live="polite">
          <p className={styles.lede}>
            <b>
              <bdi dir="ltr">{detail.provider}</bdi>
            </b>{' '}
            — {detail.enabled ? `${detail.count} رابطًا` : 'خامل'}
            {detail.reason ? ` · ${detail.reason}` : ''}
          </p>
          {detail.urls.length === 0 ? (
            <p className={styles.footnote}>
              مصفوفة فارغة صادقة: لا روابط مؤهّلة في هذا المزوّد الآن.
            </p>
          ) : (
            <div className="table-scroll" tabIndex={0} role="region" aria-label="روابط المزوّد المختار في خريطة الموقع">
              <table className={styles.table}>
                <caption>أول {Math.min(detail.urls.length, 20)} رابطًا مع آخر تعديل حقيقي</caption>
                <thead>
                  <tr>
                    <th scope="col">الرابط</th>
                    <th scope="col">آخر تعديل</th>
                  </tr>
                </thead>
                <tbody>
                  {detail.urls.slice(0, 20).map((url) => (
                    <tr key={url.loc}>
                      <td>
                        <bdi dir="ltr" className={`${styles.mono} ${styles.strong}`}>
                          {url.loc}
                        </bdi>
                      </td>
                      <td>
                        <bdi dir="ltr">{dateTime(url.lastmod)}</bdi>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      ) : null}
    </Box>
  );
}
