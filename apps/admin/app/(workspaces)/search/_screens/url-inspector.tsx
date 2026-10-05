'use client';
// UNIT: URL-INSPECTOR
// FILE: url-inspector.tsx
// STYLE: none
// PARENT: SEARCH-PANEL
// USES: LIB-ADMIN-API, LIB-FORMAT, RESOURCE, _URL-INSPECTOR-KIT
// API: /admin/crawl/page, /admin/navigation/pages, /admin/products, /admin/search/index, POST /admin/navigation/link-check, POST /admin/search/index/reindex
// MAP: docs/PROJECT_MAP.md

/**
 * مفتش URL — لوحة تحكم كاملة لكل رابط.
 *
 * التصميم: Tahdheer - Search Intelligence Center.dc.html (شاشة is.url).
 * كل لوح إمّا موصول بنقطة نهاية حقيقية وإمّا حالة غياب مسمّاة بسببها:
 *  · المُحدِّد: GET /admin/navigation/pages + GET /admin/products (منشورة).
 *  · الفهرسة: GET /seo/indexability?path=… + GET /robots.
 *  · Schema (منتجات فقط): GET /seo/products/{slug}.
 *  · الروابط الداخلية: GET /admin/crawl/page?path=… (عدسة آخر جولة).
 *  · فهرس Meilisearch: GET /admin/search/index + POST /admin/search/index/reindex.
 *  · الفحص الحي: POST /admin/navigation/link-check (محدود المعدّل — عند الطلب).
 *  · جاهزية AI: لا مُقيِّم — الحقيقة الوحيدة عضوية llms.txt (GET /llms-data عند الطلب).
 *  · أداء البحث / CWV / فرصة / آخر التغييرات: مصادرها (GSC · CrUX · سجلّ
 *    تغييرات) غير متكاملة ⇒ ألواح غائبة بأسبابها، لا أرقام عيّنة.
 *
 * الدرجة نسبة شفافة passed/evaluable من الفحوص الحقيقية وحدها — عقيدة
 * CrawlController نفسها: الرقم المركّب الموزون يُقرأ حكمًا ولا يُصلَح منه شيء.
 */

import { useEffect, useMemo, useState } from 'react';
import { useResource } from '../../../../components/resource';
import {
  api,
  firstError,
  type Paginated,
  type ProductRow,
  type SearchIndexStatus,
} from '../../../../lib/admin-api';
import { count, dateTime } from '../../../../lib/format';
import {
  ACTION_BTN,
  collectTypes,
  ErrorNote,
  isDenied,
  Panel,
  type CrawlPageEnvelope,
  type HealthCheck,
  type IndexabilityReport,
  type LinkCheckResult,
  type LiveState,
  type LlmsData,
  type LlmsState,
  type PageOption,
  type ProductSeoPayload,
  type RobotsPolicy,
  type SchemaState,
} from './_url-inspector-kit';
import '../../../../../../.design-sync/tokens-3p/search-intel-b.css';

export default function UrlInspectorScreen() {
  const [path, setPath] = useState('/');

  const pages = useResource<{ data: PageOption[] }>('/admin/navigation/pages');
  const products = useResource<Paginated<ProductRow>>('/admin/products?status=published&perPage=100');
  const encoded = encodeURIComponent(path);
  const inspect = useResource<IndexabilityReport>(`/seo/indexability?path=${encoded}`);
  const robots = useResource<RobotsPolicy>('/robots');
  const lens = useResource<CrawlPageEnvelope>(`/admin/crawl/page?path=${encoded}`);
  const index = useResource<{ data: SearchIndexStatus }>('/admin/search/index');

  const productRows = useMemo(
    () => (products.state.status === 'ready' ? products.state.data.data : []),
    [products.state],
  );
  const product = useMemo(
    () => productRows.find((row) => `/products/${row.slug}` === path) ?? null,
    [productRows, path],
  );
  const slug = product?.slug ?? null;

  /* Schema — لصفحات المنتجات المنشورة فقط (نقطة النهاية عامة ومنشورة فقط). */
  // الحالة تُشتقّ من المعرّف: ما لم يُحمَّل للمعرّف الحالي فهو «جارٍ التحميل».
  const [loadedSchema, setLoadedSchema] = useState<{ slug: string; state: SchemaState } | null>(null);
  const schema: SchemaState =
    slug === null ? { status: 'na' } : loadedSchema?.slug === slug ? loadedSchema.state : { status: 'loading' };
  useEffect(() => {
    if (slug === null) return;
    let cancelled = false;
    const setSchema = (next: SchemaState) => setLoadedSchema({ slug, state: next });
    void api.get<ProductSeoPayload>(`/seo/products/${encodeURIComponent(slug)}`).then((result) => {
      if (cancelled) return;
      if (!result.ok) {
        setSchema({ status: 'error', message: result.message, code: result.status });
        return;
      }
      const canonical = result.data.metadata['canonical'];
      setSchema({
        status: 'ready',
        types: [...collectTypes(result.data.schema)],
        canonical: typeof canonical === 'string' ? canonical : null,
      });
    });
    return () => {
      cancelled = true;
    };
  }, [slug]);

  /* الفحص الحي — عند الطلب فقط (throttle:link-check)، ويُصفَّر عند تغيير الرابط. */
  const [liveFor, setLiveFor] = useState<{ path: string; state: LiveState } | null>(null);
  const live: LiveState = liveFor?.path === path ? liveFor.state : { status: 'idle' };
  const setLive = (next: LiveState) => setLiveFor({ path, state: next });

  async function runLiveCheck() {
    setLive({ status: 'pending' });
    const result = await api.post<{ data: LinkCheckResult[] }>('/admin/navigation/link-check', {
      urls: [path],
    });
    if (!result.ok) {
      setLive({ status: 'error', message: firstError(result) });
      return;
    }
    const first = result.data.data[0];
    if (first === undefined) {
      setLive({ status: 'error', message: 'ردّ الخادم بلا نتيجة.' });
      return;
    }
    setLive({ status: 'ready', result: first });
  }

  /* عضوية llms.txt — عند الطلب (throttle:expensive)، والردّ يُبقى للجلسة. */
  const [llms, setLlms] = useState<LlmsState>({ status: 'idle' });

  async function fetchLlms() {
    setLlms({ status: 'pending' });
    const result = await api.get<LlmsData>('/llms-data');
    if (!result.ok) {
      setLlms({ status: 'error', message: result.message });
      return;
    }
    setLlms({ status: 'ready', data: result.data });
  }

  /* إعادة بناء الفهرس. */
  const [reindexBusy, setReindexBusy] = useState(false);
  const [reindexNotice, setReindexNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);

  async function reindex() {
    setReindexBusy(true);
    setReindexNotice(null);
    const result = await api.post<{ message: string }>('/admin/search/index/reindex');
    setReindexBusy(false);
    if (!result.ok) {
      setReindexNotice({ tone: 'error', text: firstError(result) });
      return;
    }
    setReindexNotice({ tone: 'ok', text: result.data.message });
    index.reload();
  }

  /* ───── الدرجة: نسبة شفافة من الفحوص القابلة للتقييم وحدها ───── */

  const lensData = lens.state.status === 'ready' ? lens.state.data.data : null;
  const lensPage = lensData?.page ?? null;
  const indexStatus = index.state.status === 'ready' ? index.state.data.data : null;
  const mismatch =
    product !== null && indexStatus !== null ? indexStatus.kindMismatches[product.sku] : undefined;

  const checks: HealthCheck[] = [
    {
      key: 'indexability',
      label: 'قابلية الفهرسة',
      evaluable: inspect.state.status === 'ready',
      passed: inspect.state.status === 'ready' && inspect.state.data.verdict === 'eligible',
    },
    {
      key: 'links',
      label: 'الروابط الداخلية',
      evaluable: lensPage !== null,
      passed: lensPage !== null && lensPage.status === 200 && lensPage.inbound > 0,
    },
    {
      key: 'schema',
      label: 'Schema منتج',
      evaluable: schema.status === 'ready',
      passed: schema.status === 'ready' && schema.types.includes('Product') && schema.types.includes('Offer'),
    },
    {
      key: 'kind',
      label: 'نوع الفهرس',
      evaluable: product !== null && indexStatus !== null,
      passed: product !== null && indexStatus !== null && mismatch === undefined,
    },
    {
      key: 'live',
      label: 'الفحص الحي',
      evaluable: live.status === 'ready',
      passed: live.status === 'ready' && live.result.status === 'OK',
    },
  ];

  const evaluable = checks.filter((check) => check.evaluable);
  const passed = evaluable.filter((check) => check.passed);
  const score = evaluable.length === 0 ? null : Math.round((passed.length / evaluable.length) * 100);
  const ringColor =
    score === null
      ? 'var(--sib-ring-track)'
      : score >= 80
        ? 'var(--green-700)'
        : score >= 70
          ? 'var(--sib-ring-mid)'
          : 'var(--sib-danger)';

  const disallowed =
    robots.state.status === 'ready'
      ? robots.state.data.disallow.some((rule) => path === rule || path.startsWith(`${rule}/`))
      : null;

  const productsTotal = products.state.status === 'ready' ? products.state.data.meta.total : null;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
      <div className="sib-fade" style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <h1 style={{ margin: 0, fontSize: 18, fontWeight: 900 }}>مفتش URL — لوحة تحكم كاملة لكل رابط</h1>
        <select
          aria-label="الرابط المفحوص"
          value={path}
          onChange={(event) => setPath(event.target.value)}
          dir="ltr"
          style={{
            marginInlineStart: 'auto',
            minHeight: 36,
            padding: '0 11px',
            border: '1px solid var(--sib-input-border)',
            borderRadius: 10,
            background: 'var(--cc-glass)',
            fontWeight: 800,
            fontSize: 12,
            fontFamily: 'inherit',
          }}
        >
          {pages.state.status === 'ready' ? (
            <optgroup label="صفحات ثابتة">
              {pages.state.data.data.map((page) => (
                <option key={page.url} value={page.url}>
                  {page.url}
                </option>
              ))}
            </optgroup>
          ) : (
            <option value="/">/</option>
          )}
          {productRows.length > 0 ? (
            <optgroup label="منتجات منشورة">
              {productRows.map((row) => (
                <option key={row.id} value={`/products/${row.slug}`}>
                  /products/{row.slug}
                </option>
              ))}
            </optgroup>
          ) : null}
        </select>
      </div>

      {/*
       * مصادر المُحدِّد تعلن فشلها ولا تصمت — وكلٌّ منها بصلاحيته: الصفحات
       * الثابتة خلف `appearance.manage` (routes/api-admin.php:435 داخل مجموعة
       * السطر 433) والمنتجات خلف `products.read` (routes/api-admin.php:129).
       * فمن يملك واحدة دون الأخرى يرى مجموعةً واحدة في القائمة لا قائمةً
       * ناقصة بلا تفسير. والقفل لا يُعرض معه زرّ إعادة محاولة.
       */}
      {pages.state.status === 'error' ? (
        <p style={{ margin: 0, fontSize: 11.5, color: 'var(--sib-danger)' }} role="alert">
          تعذّر تحميل الصفحات الثابتة: <ErrorNote state={pages.state} permission="appearance.manage" />{' '}
          {isDenied(pages.state) ? null : (
            <button type="button" style={{ ...ACTION_BTN, marginTop: 0 }} onClick={pages.reload}>
              إعادة المحاولة
            </button>
          )}
        </p>
      ) : null}
      {products.state.status === 'error' ? (
        <p style={{ margin: 0, fontSize: 11.5, color: 'var(--sib-danger)' }} role="alert">
          تعذّر تحميل المنتجات: <ErrorNote state={products.state} permission="products.read" />{' '}
          {isDenied(products.state) ? null : (
            <button type="button" style={{ ...ACTION_BTN, marginTop: 0 }} onClick={products.reload}>
              إعادة المحاولة
            </button>
          )}
        </p>
      ) : null}
      {productsTotal !== null && productsTotal > productRows.length ? (
        <p style={{ margin: 0, fontSize: 11.5, color: 'var(--sib-hint)' }}>
          تُعرض أول {count(productRows.length)} من {count(productsTotal)} منتجًا منشورًا في المُحدِّد.
        </p>
      ) : null}

      <div style={{ display: 'grid', gridTemplateColumns: '220px minmax(0,1fr)', gap: 12, alignItems: 'start' }}>
        {/* درجة صحة الرابط — نسبة شفافة، لا وزن ولا رقم عيّنة. */}
        <section
          style={{
            border: '1px solid var(--sib-card-border)',
            borderRadius: 14,
            background: 'var(--cc-glass)',
            padding: 15,
            textAlign: 'center',
          }}
        >
          <div style={{ position: 'relative', width: 110, height: 110, margin: '0 auto' }}>
            <svg viewBox="0 0 100 100" style={{ width: 110, height: 110, transform: 'rotate(-90deg)' }} aria-hidden="true">
              <circle cx="50" cy="50" r="44" fill="none" stroke="var(--sib-ring-track)" strokeWidth="9" />
              <circle
                cx="50"
                cy="50"
                r="44"
                fill="none"
                stroke={ringColor}
                strokeWidth="9"
                strokeLinecap="round"
                strokeDasharray={score === null ? '0 276' : `${(score * 2.76).toFixed(1)} 276`}
              />
            </svg>
            <div style={{ position: 'absolute', inset: 0, display: 'grid', placeItems: 'center' }}>
              <b style={{ fontSize: 24, fontWeight: 900 }}>{score === null ? '—' : count(score)}</b>
            </div>
          </div>
          <b style={{ display: 'block', marginTop: 6, fontSize: 12 }}>درجة صحة الرابط</b>
          <span style={{ fontSize: 11.5, color: 'var(--sib-hint)' }}>
            {score === null
              ? 'لا بند قابلًا للتقييم بعد — لا درجة تُخترع.'
              : `${count(passed.length)} من ${count(evaluable.length)} بنود مُقاسة اجتازت — نسبة بلا أوزان.`}
          </span>
          <ul style={{ margin: '8px 0 0', padding: 0, listStyle: 'none', textAlign: 'start' }}>
            {checks.map((check) => (
              <li
                key={check.key}
                style={{
                  fontSize: 11.5,
                  lineHeight: 1.8,
                  color: check.evaluable
                    ? check.passed
                      ? 'var(--success-chip-fg)'
                      : 'var(--sib-danger)'
                    : 'var(--sib-hint)',
                }}
              >
                {check.evaluable ? (check.passed ? '✓' : '✗') : '·'} {check.label}
                {check.evaluable ? '' : ' — غير مُقاس'}
              </li>
            ))}
          </ul>
          <span style={{ display: 'block', marginTop: 6, fontSize: 11.5, color: 'var(--sib-hint)' }}>
            GSC وCrUX وجاهزية AI خارج الحساب لغياب مصدرها.
          </span>
        </section>

        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 9 }}>
          {/* أداء البحث — غائب باسمه وسببه. */}
          <Panel tone="absent" title="أداء البحث" chip="يتطلب Search Console — غير متصل">
            الانطباعات ونسبة النقر والمركز لكل رابط مصدرها Search Console، ولا تكامل GSC في المنظومة —
            فلا أرقام تُعرض ولا تُقدَّر.
          </Panel>

          {/* الفهرسة — GET /seo/indexability + GET /robots. */}
          <Panel
            tone={
              inspect.state.status !== 'ready'
                ? inspect.state.status === 'error'
                  ? 'bad'
                  : 'absent'
                : inspect.state.data.verdict === 'eligible'
                  ? 'ok'
                  : inspect.state.data.verdict === 'monitor'
                    ? 'warn'
                    : 'bad'
            }
            title="الفهرسة"
            chip={
              inspect.state.status === 'loading'
                ? 'يُفحص…'
                : inspect.state.status === 'error'
                  ? 'تعذّر'
                  : inspect.state.data.verdict === 'eligible'
                    ? 'قابل للفهرسة'
                    : inspect.state.data.verdict === 'monitor'
                      ? 'تحت المراقبة'
                      : 'مستبعد'
            }
            action={
              inspect.state.status === 'error' ? (
                <button type="button" style={ACTION_BTN} onClick={inspect.reload}>
                  إعادة المحاولة
                </button>
              ) : undefined
            }
          >
            {inspect.state.status === 'loading' ? 'جارٍ فحص السياسة…' : null}
            {inspect.state.status === 'error' ? inspect.state.message : null}
            {inspect.state.status === 'ready' ? (
              <>
                {inspect.state.data.reason}
                <br />
                <bdi dir="ltr">robots: {inspect.state.data.robots}</bdi> · القاعدة{' '}
                <bdi dir="ltr">{inspect.state.data.rule}</bdi>
                {disallowed === null ? null : (
                  <>
                    <br />
                    {disallowed
                      ? 'المسار ضمن قواعد Disallow المعلنة في robots.'
                      : 'ليس ضمن قواعد Disallow المعلنة في robots.'}
                  </>
                )}
              </>
            ) : null}
          </Panel>

          {/* CWV ميداني — غائب باسمه وسببه. */}
          <Panel tone="absent" title="CWV ميداني" chip="يتطلب CrUX — غير متصل">
            بيانات الميدان (LCP · INP · CLS) مصدرها تقرير تجربة كروم CrUX، وهو غير متكامل — ولا قياس
            معملي بديل يُعرض مكانها.
          </Panel>

          {/* Schema — منتجات منشورة فقط: GET /seo/products/{slug}. */}
          <Panel
            tone={
              schema.status === 'ready'
                ? schema.types.includes('Product') && schema.types.includes('Offer')
                  ? 'ok'
                  : 'warn'
                : schema.status === 'error'
                  ? 'bad'
                  : 'absent'
            }
            title="Schema"
            chip={
              schema.status === 'na'
                ? 'لصفحات المنتجات فقط'
                : schema.status === 'loading'
                  ? 'يُقرأ…'
                  : schema.status === 'error'
                    ? schema.code === 404
                      ? 'غير منشور'
                      : 'تعذّر'
                    : schema.types.includes('Product') && schema.types.includes('Offer')
                      ? 'صالح'
                      : 'ناقص'
            }
          >
            {schema.status === 'na'
              ? 'رسم JSON-LD يُبنى لصفحات المنتجات المنشورة وحدها (GET /seo/products/{slug}) — اختر رابط منتج لفحصه.'
              : null}
            {schema.status === 'loading' ? 'جارٍ قراءة الرسم…' : null}
            {schema.status === 'error' ? schema.message : null}
            {schema.status === 'ready' ? (
              <>
                الأنواع المعادة فعلًا: <bdi dir="ltr">{schema.types.join(' · ') || '—'}</bdi>
                <br />
                {schema.types.includes('Product')
                  ? schema.types.includes('Offer')
                    ? 'Product و Offer موجودان في الرسم.'
                    : 'Product موجود بلا Offer.'
                  : 'لا Product في الرسم.'}
                {schema.canonical !== null ? (
                  <>
                    <br />
                    canonical: <bdi dir="ltr">{schema.canonical}</bdi>
                  </>
                ) : null}
              </>
            ) : null}
          </Panel>

          {/* الروابط الداخلية — عدسة GET /admin/crawl/page. */}
          <Panel
            tone={
              lens.state.status === 'error'
                ? 'bad'
                : lensPage === null
                  ? 'absent'
                  : lensPage.status >= 400
                    ? 'bad'
                    : lensPage.status >= 300 || lensPage.inbound === 0 || lensPage.depth >= 3
                      ? 'warn'
                      : 'ok'
            }
            title="الروابط الداخلية"
            chip={
              lens.state.status === 'loading'
                ? 'يُقرأ…'
                : lens.state.status === 'error'
                  ? 'تعذّر'
                  : lensData === null
                    ? 'لا جولة زحف'
                    : lensPage === null
                      ? 'خارج آخر جولة'
                      : lensPage.status >= 400
                        ? 'مكسور'
                        : lensPage.status >= 300
                          ? 'تحويل'
                          : lensPage.inbound === 0
                            ? 'يتيمة'
                            : lensPage.depth >= 3
                              ? 'مدفونة'
                              : 'سليم'
            }
            action={
              lens.state.status === 'error' && !isDenied(lens.state) ? (
                <button type="button" style={ACTION_BTN} onClick={lens.reload}>
                  إعادة المحاولة
                </button>
              ) : undefined
            }
          >
            {lens.state.status === 'loading' ? 'جارٍ قراءة آخر جولة…' : null}
            {/* عدسة الزحف خلف `analytics.read` (routes/api-admin.php:398-399). */}
            <ErrorNote state={lens.state} permission="analytics.read" />
            {lens.state.status === 'ready' ? (
              lensData === null || lensPage === null ? (
                (lens.state.data.message ?? 'لا بيانات لهذا المسار في آخر جولة مكتملة.')
              ) : (
                <>
                  {count(lensPage.inbound)} رابط وارد · عمق {count(lensPage.depth)} نقرة · حالة HTTP{' '}
                  <bdi dir="ltr">{lensPage.status}</bdi>
                  {lensPage.redirectTo !== null ? (
                    <>
                      <br />
                      يحوّل إلى <bdi dir="ltr">{lensPage.redirectTo}</bdi>
                    </>
                  ) : null}
                  {lensPage.discoveredFrom !== null ? (
                    <>
                      <br />
                      اكتُشف من <bdi dir="ltr">{lensPage.discoveredFrom}</bdi>
                    </>
                  ) : null}
                  <br />
                  جولة {count(lensData.runId)} · {dateTime(lensData.finishedAt)}
                </>
              )
            ) : null}
          </Panel>

          {/* جاهزية AI — لا مُقيِّم؛ الحقيقة الوحيدة عضوية llms.txt. */}
          <Panel
            tone={
              llms.status === 'ready' && slug !== null
                ? llms.data.items.some((item) => item.url.endsWith(`/products/${slug}`))
                  ? 'ok'
                  : 'warn'
                : llms.status === 'error'
                  ? 'bad'
                  : 'absent'
            }
            title="جاهزية AI"
            chip={
              llms.status === 'ready' && slug !== null
                ? llms.data.items.some((item) => item.url.endsWith(`/products/${slug}`))
                  ? 'مدرج في llms.txt'
                  : 'غير مدرج'
                : llms.status === 'pending'
                  ? 'يُقرأ…'
                  : llms.status === 'error'
                    ? 'تعذّر'
                    : 'غير مُقاس'
            }
            action={
              slug !== null && llms.status !== 'pending' && llms.status !== 'ready' ? (
                <button type="button" style={ACTION_BTN} onClick={() => void fetchLlms()}>
                  تحقّق من عضوية llms.txt
                </button>
              ) : undefined
            }
          >
            لا مُقيِّم «جاهزية AI» في المنظومة — النسب المئوية في التصميم عيّنة.{' '}
            {slug === null
              ? 'فهرس llms.txt يغطي المنتجات المنشورة فقط، فلا حكم لهذا المسار.'
              : 'الحقيقة الوحيدة المتاحة: عضوية فهرس llms.txt (تُقرأ عند الطلب).'}
            {llms.status === 'error' ? (
              <>
                <br />
                {llms.message}
              </>
            ) : null}
            {llms.status === 'ready' && slug !== null ? (
              <>
                <br />
                تغطية الفهرس: {count(llms.data.coverage.included)} من {count(llms.data.coverage.published)} منتجًا
                منشورًا — الإدراج مشروط بأهلية الفهرسة.
              </>
            ) : null}
          </Panel>

          {/* فرصة — غائبة باسمها وسببها. */}
          <Panel tone="absent" title="فرصة" chip="يتطلب Search Console — غير متصل">
            حساب الفرص (مركز أعلى = نقرات إضافية) يحتاج انطباعات ومراكز حقيقية من Search Console —
            غير متصل، فلا فرصة تُخترع.
          </Panel>

          {/* آخر التغييرات — غائب باسمه وسببه. */}
          <Panel tone="absent" title="آخر التغييرات" chip="لا سجلّ — غير مُقاس">
            لا سجلّ تغييرات لكل رابط في الواجهة الخلفية بعد، فلا مقارنة «قبل/بعد» تُعرض.
          </Panel>

          {/* فحص الرابط الحي — POST /admin/navigation/link-check. */}
          <Panel
            tone={
              live.status === 'ready'
                ? live.result.status === 'OK'
                  ? 'ok'
                  : 'bad'
                : live.status === 'error'
                  ? 'bad'
                  : 'absent'
            }
            title="فحص الرابط الحي"
            chip={
              live.status === 'idle'
                ? 'لم يُفحص'
                : live.status === 'pending'
                  ? 'يجري…'
                  : live.status === 'error'
                    ? 'تعذّر'
                    : live.result.status === 'OK'
                      ? 'يستجيب'
                      : live.result.status === 'BLOCKED'
                        ? 'محجوب'
                        : 'متعذّر'
            }
            action={
              live.status === 'pending' ? undefined : (
                <button type="button" style={ACTION_BTN} onClick={() => void runLiveCheck()}>
                  {live.status === 'idle' ? 'افحص الآن' : 'أعد الفحص'}
                </button>
              )
            }
          >
            {live.status === 'idle'
              ? 'جلب فعلي للوجهة من الخادم: سلسلة التحويلات والحالة وrobots — عند الطلب فقط لأنه محدود المعدّل.'
              : null}
            {live.status === 'pending' ? 'جارٍ الجلب…' : null}
            {live.status === 'error' ? live.message : null}
            {live.status === 'ready' ? (
              <>
                الحالة النهائية <bdi dir="ltr">{live.result.httpStatus ?? '—'}</bdi> · قفزات{' '}
                {count(live.result.hops)}
                {live.result.chain.length > 0 ? (
                  <>
                    <br />
                    <bdi dir="ltr">
                      {live.result.chain.map((hop) => `${hop.url} (${hop.status})`).join(' ← ')}
                    </bdi>
                  </>
                ) : null}
                {live.result.canonical !== null ? (
                  <>
                    <br />
                    canonical: <bdi dir="ltr">{live.result.canonical}</bdi>
                  </>
                ) : null}
                <br />
                {live.result.indexable === null
                  ? 'لا حكم على الفهرسة — الصفحة لم تُقرأ.'
                  : live.result.indexable
                    ? 'لا noindex في الصفحة المقروءة.'
                    : `noindex معلن: ${live.result.robots ?? ''}`}
                {live.result.reason !== null ? (
                  <>
                    <br />
                    {live.result.reason}
                  </>
                ) : null}
              </>
            ) : null}
          </Panel>

          {/* فهرس Meilisearch — GET /admin/search/index + إعادة البناء. */}
          <Panel
            tone={
              index.state.status === 'error'
                ? 'bad'
                : indexStatus === null
                  ? 'absent'
                  : mismatch !== undefined
                    ? 'bad'
                    : indexStatus.servingPath !== 'meilisearch' || !indexStatus.engineHealthy
                      ? 'warn'
                      : 'ok'
            }
            title="فهرس Meilisearch"
            chip={
              index.state.status === 'loading'
                ? 'يُقرأ…'
                : index.state.status === 'error'
                  ? 'تعذّر'
                  : mismatch !== undefined
                    ? 'نوع منحرف'
                    : indexStatus !== null && indexStatus.servingPath !== 'meilisearch'
                      ? 'احتياطي'
                      : indexStatus !== null && !indexStatus.engineHealthy
                        ? 'المحرّك متعذّر'
                        : 'يعمل'
            }
            action={
              indexStatus !== null ? (
                <button type="button" style={ACTION_BTN} disabled={reindexBusy} onClick={() => void reindex()}>
                  {reindexBusy ? 'جارٍ الإرسال…' : 'أعد بناء الفهرس'}
                </button>
              ) : index.state.status === 'error' && !isDenied(index.state) ? (
                <button type="button" style={ACTION_BTN} onClick={index.reload}>
                  إعادة المحاولة
                </button>
              ) : undefined
            }
          >
            {index.state.status === 'loading' ? 'جارٍ قراءة حالة الفهرس…' : null}
            {/* حالة الفهرس خلف `analytics.read` (routes/api-admin.php:215-216). */}
            <ErrorNote state={index.state} permission="analytics.read" />
            {indexStatus !== null ? (
              <>
                يخدم البحث: {indexStatus.servingPath === 'meilisearch' ? 'Meilisearch' : 'احتياطي PostgreSQL'} ·
                الفهرس <bdi dir="ltr">{indexStatus.indexName ?? 'لم يُبنَ بعد'}</bdi> · جيل{' '}
                {count(indexStatus.generation)}
                <br />
                التقادم:{' '}
                {indexStatus.staleSeconds === null
                  ? '—'
                  : indexStatus.staleSeconds === 0
                    ? 'محدَّث'
                    : `${count(Math.round(indexStatus.staleSeconds / 60))} دقيقة`}
                {product !== null ? (
                  <>
                    <br />
                    {mismatch !== undefined ? (
                      <>
                        نوع هذا المنتج المكتوب («{mismatch}») لا يطابق مصطلحات التصنيف — مرشِّح النوع لا
                        يجده. صحّحه من محرّر المنتج ثم أعد الفهرسة.
                      </>
                    ) : (
                      'لا انحراف نوع مرصود لهذا المنتج — والنقطة لا تُعيد عضوية كل منتج في الفهرس.'
                    )}
                  </>
                ) : null}
                {reindexNotice !== null ? (
                  <span
                    role={reindexNotice.tone === 'ok' ? 'status' : 'alert'}
                    style={{
                      display: 'block',
                      marginTop: 4,
                      color: reindexNotice.tone === 'ok' ? 'var(--success-chip-fg)' : 'var(--sib-danger)',
                    }}
                  >
                    {reindexNotice.text}
                  </span>
                ) : null}
              </>
            ) : null}
          </Panel>
        </div>
      </div>
    </div>
  );
}
