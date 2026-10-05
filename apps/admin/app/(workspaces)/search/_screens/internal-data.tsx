'use client';
// UNIT: INTERNAL-DATA
// FILE: internal-data.tsx
// STYLE: none
// PARENT: SEARCH-PANEL
// USES: LIB-ADMIN-API, LIB-FORMAT, RESOURCE
// API: /admin/search/index, /admin/search/queries, POST /admin/search/index/reindex, POST /admin/search/synonyms
// MAP: docs/PROJECT_MAP.md

/**
 * البيانات الداخلية — الألواح الثلاثة الموصولة المحفوظة من شاشة «ذكاء البحث»
 * السابقة (page.tsx قبل المركز الموحّد)، منقولة كما هي بلا تغيير سلوك:
 * تجميعات سجل search_queries + حالة الفهرس وإعادة البناء + إدارة المرادفات.
 *
 * السجل الفارغ يُعرض فارغًا صراحةً — لا استعلامات «شائعة» مُختلَقة
 * (MIP §6.6 و§1.3). وسم المصدر (أوّلي/مُتعلَّم/يدوي) لا تعدّله الواجهة أبدًا.
 */

import { useCallback, useMemo, useState, type ReactNode } from 'react';
import { EmptyRows, ResourceView, useResource, type ResourceState } from '../../../../components/resource';
import {
  api,
  firstError,
  type Paginated,
  type SearchIndexStatus,
  type SearchQueriesReport,
  type SynonymRow,
} from '../../../../lib/admin-api';
import { count, dateTime, SYNONYM_SOURCE_LABELS, SYNONYM_STATE_LABELS } from '../../../../lib/format';
import { diagnosisBadge, type ZeroResultDiagnosis } from '../../../../lib/search-diagnosis';
import { StatusBadge } from '../../../../components/ui/status-badge';

/** الصفّ كما يصدره الخادم اليوم: `diagnosis` اختياريّ كي لا تنكسر الشاشة أمام خادمٍ أقدم. */
type ZeroResultRow = SearchQueriesReport['zeroResultQueries'][number] & { diagnosis?: ZeroResultDiagnosis };

/**
 * لوحٌ محروسٌ بصلاحية — والحراسة تُعلَن لوحًا لوحًا لا شاشةً كاملة.
 *
 * الألواح الثلاثة هنا خلف مفتاحين لا مفتاح: الاستعلامات وحالة الفهرس بـ
 * `analytics.read`، وإدارة المرادفات بـ`search.synonyms.manage`. فمن يملك
 * الأولى دون الثانية يقرأ سجلّ البحث كاملًا ويُقفَل عليه جدول المرادفات
 * وحده — وقفلٌ عامّ على الشاشة كان سيحجب عنه ما يحقّ له.
 *
 * و٤٠٣ لا يُعرض معه زرّ «إعادة المحاولة»: الردّ الثاني هو الأوّل.
 */
function GatedView<T>({
  state,
  reload,
  permission,
  reads,
  children,
}: {
  state: ResourceState<T>;
  reload: () => void;
  permission: string;
  reads: string;
  children: (data: T) => ReactNode;
}) {
  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>
          دورك لا يملك <bdi dir="ltr">{permission}</bdi>، فردّ الخادم ٤٠٣ وبقي هذا اللوح مغلقًا. {reads} —
          وما لا يُقرأ لا يُعرض صفرًا ولا جدولًا فارغًا. اطلب الصلاحية من مدير المتجر.
        </p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {children}
    </ResourceView>
  );
}

export default function InternalDataScreen() {
  const [days, setDays] = useState(30);
  const queriesPath = useMemo(() => `/admin/search/queries?days=${days}`, [days]);
  const queries = useResource<SearchQueriesReport>(queriesPath);
  const synonyms = useResource<Paginated<SynonymRow>>('/admin/search/synonyms');
  const index = useResource<{ data: SearchIndexStatus }>('/admin/search/index');

  return (
    <div>
      <section className="panel">
        <h2>استعلامات البحث</h2>
        <label className="field field-inline" htmlFor="window-days">
          <span>النافذة الزمنية</span>
          <select id="window-days" value={days} onChange={(event) => setDays(Number(event.target.value))}>
            <option value={7}>آخر ٧ أيام</option>
            <option value={30}>آخر ٣٠ يومًا</option>
            <option value={90}>آخر ٩٠ يومًا</option>
          </select>
        </label>

        <GatedView
          state={queries.state}
          reload={queries.reload}
          permission="analytics.read"
          reads="تجميعات سجلّ ‎search_queries‎ كلّها تأتي من ‎GET /admin/search/queries‎ وحدها"
        >
          {(report) => (
            <>
              <div className="cards">
                <article className="card">
                  <div className="label">استعلامات مسجَّلة</div>
                  <div className="value">
                    <bdi dir="ltr">{count(report.totals.queriesLogged)}</bdi>
                  </div>
                  <bdi dir="ltr" className="api">
                    search_queries
                  </bdi>
                </article>
                <article className="card">
                  <div className="label">استعلامات مميّزة</div>
                  <div className="value">
                    <bdi dir="ltr">{count(report.totals.distinctQueries)}</bdi>
                  </div>
                  <bdi dir="ltr" className="api">
                    distinct normalized_query
                  </bdi>
                </article>
                <article className="card">
                  <div className="label">بلا نتائج</div>
                  <div className="value">
                    <bdi dir="ltr">{count(report.totals.zeroResultQueries)}</bdi>
                  </div>
                  <bdi dir="ltr" className="api">
                    search_queries.zero_result
                  </bdi>
                </article>
                <article className="card">
                  <div className="label">معدّل «بلا نتائج»</div>
                  <div className={report.metrics.zeroResultRate === null ? 'value value-absent' : 'value'}>
                    {report.metrics.zeroResultRate === null ? (
                      '—'
                    ) : (
                      <bdi dir="ltr">{`${(report.metrics.zeroResultRate * 100).toFixed(1)}%`}</bdi>
                    )}
                  </div>
                  <bdi dir="ltr" className="api">
                    zero_result / total
                  </bdi>
                </article>
                <article className="card">
                  <div className="label">نسبة النقر CTR</div>
                  <div className={report.metrics.ctr === null ? 'value value-absent' : 'value'}>
                    {report.metrics.ctr === null ? (
                      '—'
                    ) : (
                      <bdi dir="ltr">{`${(report.metrics.ctr * 100).toFixed(1)}%`}</bdi>
                    )}
                  </div>
                  <bdi dir="ltr" className="api">
                    clicked / total
                  </bdi>
                </article>
                <article className="card">
                  <div className="label">متوسّط موضع النقرة</div>
                  <div className={report.metrics.meanClickPosition === null ? 'value value-absent' : 'value'}>
                    {report.metrics.meanClickPosition === null ? (
                      '—'
                    ) : (
                      <bdi dir="ltr">{String(report.metrics.meanClickPosition)}</bdi>
                    )}
                  </div>
                  <bdi dir="ltr" className="api">
                    avg(position)
                  </bdi>
                </article>
              </div>

              {/* المقاييس الفارغة تُفسَّر لا تُترك شرطات غامضة. */}
              {report.metrics.ctr === null || report.metrics.clickedQueries === 0 ? (
                <p className="page-note">
                  «—» تعني لا بيانات كافية: CTR وموضع النقرة يُحسبان من نقرات النتائج
                  الحقيقية التي يرويها المتجر، لا من تقدير.
                </p>
              ) : null}

              <h3>الأكثر تكرارًا</h3>
              <div className="table-scroll" tabIndex={0} role="region" aria-label="أكثر استعلامات البحث الداخلي تكرارًا">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th scope="col">الاستعلام</th>
                      <th scope="col">التكرار</th>
                      <th scope="col">منه بلا نتائج</th>
                      <th scope="col">آخر ظهور</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.topQueries.length === 0 ? (
                      <EmptyRows colSpan={4} message="لا استعلامات مسجَّلة في هذه النافذة." />
                    ) : (
                      report.topQueries.map((row) => (
                        <tr key={`top-${row.query}`}>
                          <td>{row.query}</td>
                          <td>
                            <bdi dir="ltr">{count(row.count)}</bdi>
                          </td>
                          <td>
                            <bdi dir="ltr">{count(row.zeroResultCount)}</bdi>
                          </td>
                          <td>
                            <bdi>{dateTime(row.lastSeenAt)}</bdi>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              <h3>استعلامات بلا نتائج</h3>
              <div className="table-scroll" tabIndex={0} role="region" aria-label="استعلامات البحث الداخلي بلا نتائج">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th scope="col">الاستعلام</th>
                      <th scope="col">التكرار</th>
                      <th scope="col">التشخيص</th>
                      <th scope="col">آخر ظهور</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.zeroResultQueries.length === 0 ? (
                      <EmptyRows colSpan={4} message="لا استعلامات بلا نتائج في هذه النافذة." />
                    ) : (
                      (report.zeroResultQueries as ZeroResultRow[]).map((row) => (
                        <tr key={`zero-${row.query}`}>
                          <td>{row.query}</td>
                          <td>
                            <bdi dir="ltr">{count(row.count)}</bdi>
                          </td>
                          <td>
                            {/*
                              * لماذا فشل، وما يُفعل (`ZeroResultDiagnosis`): منتجٌ ناقص،
                              * أو مرادفٌ ناقص، أو لا صلة بالمتجر، أو أُصلح منذ سُجّل.
                              */}
                            {row.diagnosis ? (
                              <>
                                <StatusBadge {...diagnosisBadge(row.diagnosis.reason)} />{' '}
                                <span>{row.diagnosis.action}</span>
                              </>
                            ) : (
                              '—'
                            )}
                          </td>
                          <td>
                            <bdi>{dateTime(row.lastSeenAt)}</bdi>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </GatedView>
      </section>

      <IndexPanel state={index.state} reload={index.reload} />

      <SynonymManager state={synonyms.state} reload={synonyms.reload} />
    </div>
  );
}

/**
 * حالة فهرس البحث — الشاشة التي كانت غائبة حين عمل الإنتاج على الاحتياطي
 * شهرًا بلا أن يعرف أحد. «أيّ مسار يخدم البحث الآن؟» يُجاب هنا حرفيًّا.
 */
function IndexPanel({
  state,
  reload,
}: {
  state: ReturnType<typeof useResource<{ data: SearchIndexStatus }>>['state'];
  reload: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);

  async function reindex() {
    setBusy(true);
    setNotice(null);
    const result = await api.post<{ message: string }>('/admin/search/index/reindex');
    setBusy(false);
    if (!result.ok) {
      setNotice({ tone: 'error', text: firstError(result) });
      return;
    }
    setNotice({ tone: 'ok', text: result.data.message });
    reload();
  }

  return (
    <section className="panel">
      <h2>فهرس المحرّك</h2>
      <GatedView
        state={state}
        reload={reload}
        permission="analytics.read"
        reads="حالة الفهرس ومسار الخدمة يُقرآن من ‎GET /admin/search/index‎"
      >
        {(payload) => {
          const idx = payload.data;
          const degraded = idx.servingPath !== 'meilisearch';
          /*
             `unknown` أسطرٌ كُتبت قبل وجود القياس — تُسمّى بما هي، لا تُنسب
             إلى PostgreSQL ولا تُحسب سلامة.
          */
          const SERVED_LABEL: Record<string, string> = {
            meilisearch: 'Meilisearch',
            pg: 'احتياطي PostgreSQL',
            mixed: 'مختلط',
          };
          const served =
            idx.measured.servedPath === null
              ? null
              : (SERVED_LABEL[idx.measured.servedPath] ?? idx.measured.servedPath);
          return (
            <>
              <div className="cards">
                <article className="card">
                  <div className="label">المسار المتوقَّع للطلب القادم</div>
                  <div className={degraded ? 'value value-absent' : 'value'}>
                    {degraded ? 'احتياطي PostgreSQL' : 'Meilisearch'}
                  </div>
                  <bdi dir="ltr" className="api">
                    provider={idx.configuredProvider}
                  </bdi>
                </article>
                {/*
                 * التنبّؤ أعلاه أعمى عن استثناءٍ داخل طلبٍ بعينه: `/health`
                 * يجيب بنعم والجيل أكبر من صفر، فيقول «Meilisearch» بينما
                 * PostgreSQL يخدم كلّ استعلام. هذه البطاقة تقرأ ما وقع.
                 */}
                <article className="card">
                  <div className="label">المسار الذي خدم فعلًا</div>
                  <div className={served === null ? 'value value-absent' : 'value'}>
                    {served ?? '—'}
                  </div>
                  <bdi dir="ltr" className="api">
                    search_queries · {idx.measured.windowHours}h ·{' '}
                    {idx.measured.totalQueries} مقيسًا
                  </bdi>
                </article>
                <article className="card">
                  <div className="label">نسبة التدهور</div>
                  <div
                    className={
                      idx.measured.degradedRate === null || idx.measured.degradedRate > 0
                        ? 'value value-absent'
                        : 'value'
                    }
                  >
                    {idx.measured.degradedRate === null ? (
                      '—'
                    ) : (
                      <bdi dir="ltr">{`${(idx.measured.degradedRate * 100).toFixed(1)}%`}</bdi>
                    )}
                  </div>
                  <bdi dir="ltr" className="api">
                    {idx.measured.degradedQueries === null
                      ? `لا سطر مقيس · ${idx.measured.unmeasuredRows} سابق للقياس`
                      : `${idx.measured.degradedQueries} / ${idx.measured.totalQueries}`}
                  </bdi>
                </article>
                <article className="card">
                  <div className="label">زمن الاستجابة</div>
                  <div className={idx.measured.latencyP50Ms === null ? 'value value-absent' : 'value'}>
                    {idx.measured.latencyP50Ms === null ? (
                      '—'
                    ) : (
                      <bdi dir="ltr">{`${idx.measured.latencyP50Ms} ms`}</bdi>
                    )}
                  </div>
                  <bdi dir="ltr" className="api">
                    {idx.measured.latencyP95Ms === null
                      ? 'latency_ms · غير مقيس'
                      : `p50 · p95 ${idx.measured.latencyP95Ms} ms`}
                  </bdi>
                </article>
                <article className="card">
                  <div className="label">المحرّك</div>
                  <div className="value">{idx.engineHealthy ? 'متاح' : 'متعذّر'}</div>
                  <bdi dir="ltr" className="api">
                    /health
                  </bdi>
                </article>
                <article className="card">
                  <div className="label">الفهرس</div>
                  <div className={idx.everBuilt ? 'value' : 'value value-absent'}>
                    {idx.everBuilt ? (
                      <bdi dir="ltr">{idx.indexName}</bdi>
                    ) : (
                      'لم يُبنَ بعد'
                    )}
                  </div>
                  <bdi dir="ltr" className="api">
                    generation {idx.generation} · {idx.status}
                  </bdi>
                </article>
                <article className="card">
                  <div className="label">تقادم الفهرس</div>
                  <div className="value">
                    {idx.staleSeconds === null ? (
                      '—'
                    ) : idx.staleSeconds === 0 ? (
                      'محدَّث'
                    ) : (
                      <bdi dir="ltr">{`${Math.round(idx.staleSeconds / 60)} دقيقة`}</bdi>
                    )}
                  </div>
                  <bdi dir="ltr" className="api">
                    max(updated_at) − last_built_at
                  </bdi>
                </article>
              </div>

              {idx.predictionDiverges === true ? (
                <p className="state-error" role="alert">
                  التنبّؤ يقول «{degraded ? 'احتياطي PostgreSQL' : 'Meilisearch'}» والأسطر تقول «
                  {served}». فحص الصحّة يمرّ والتعثّر يقع داخل الطلب نفسه
                  {Object.keys(idx.measured.reasons).length > 0 ? (
                    <>
                      {' — '}
                      <bdi dir="ltr">
                        {Object.entries(idx.measured.reasons)
                          .map(([reason, count]) => `${reason} ×${count}`)
                          .join(' · ')}
                      </bdi>
                    </>
                  ) : null}
                  .
                </p>
              ) : null}

              {degraded ? (
                <p className="state-error" role="alert">
                  البحث يُخدم من الاحتياطي الآن
                  {idx.configuredProvider !== 'meilisearch'
                    ? ' لأن SEARCH_PROVIDER غير مضبوط على meilisearch.'
                    : !idx.engineHealthy
                      ? ' لأن المحرّك متعذّر.'
                      : ' لأن الفهرس لم يُبنَ بعد.'}{' '}
                  النتائج صحيحة لكن أبطأ وأفقر ترتيبًا.
                </p>
              ) : null}

              {Object.keys(idx.kindMismatches).length > 0 ? (
                <p className="state-error" role="alert">
                  منتجات نوعها المكتوب لا يطابق مصطلحات التصنيف، فمرشِّح النوع لا يجدها:{' '}
                  {Object.entries(idx.kindMismatches)
                    .map(([sku, value]) => `${sku} («${value}»)`)
                    .join('، ')}
                  . صحّح النوع من محرّر المنتج ثم أعد الفهرسة.
                </p>
              ) : null}

              <div className="form-actions">
                <button type="button" className="btn btn-primary" disabled={busy} onClick={() => void reindex()}>
                  {busy ? 'جارٍ الإرسال…' : 'أعد بناء الفهرس'}
                </button>
                <span className="absent">
                  يبني جيلًا جديدًا في الخلفية ثم يبدّل ذرّيًّا — البحث لا ينقطع.
                </span>
              </div>

              {notice ? (
                <p
                  className={notice.tone === 'ok' ? 'state-ok' : 'state-error'}
                  role={notice.tone === 'ok' ? 'status' : 'alert'}
                >
                  {notice.text}
                </p>
              ) : null}
            </>
          );
        }}
      </GatedView>
    </section>
  );
}

function SynonymManager({
  state,
  reload,
}: {
  state: ReturnType<typeof useResource<Paginated<SynonymRow>>>['state'];
  reload: () => void;
}) {
  const [term, setTerm] = useState('');
  const [synonym, setSynonym] = useState('');
  const [direction, setDirection] = useState('one_way');
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  const mutate = useCallback(
    async (id: number, payload: Record<string, unknown>) => {
      setError(null);
      const result = await api.patch(`/admin/search/synonyms/${id}`, payload);
      if (!result.ok) {
        setError(firstError(result));
        return;
      }
      reload();
    },
    [reload],
  );

  async function create() {
    setPending(true);
    setError(null);
    const result = await api.post('/admin/search/synonyms', { term, synonym, direction });
    setPending(false);
    if (!result.ok) {
      setError(firstError(result));
      return;
    }
    setTerm('');
    setSynonym('');
    reload();
  }

  return (
    <section className="panel">
      <h2>المرادفات</h2>

      {error ? (
        <p className="form-error" role="alert">
          {error}
        </p>
      ) : null}

      <form
        className="filters"
        onSubmit={(event) => {
          event.preventDefault();
          void create();
        }}
      >
        <label className="field field-inline" htmlFor="syn-term">
          <span>المصطلح</span>
          <input id="syn-term" value={term} onChange={(event) => setTerm(event.target.value)} required />
        </label>
        <label className="field field-inline" htmlFor="syn-synonym">
          <span>المرادف</span>
          <input id="syn-synonym" value={synonym} onChange={(event) => setSynonym(event.target.value)} required />
        </label>
        <label className="field field-inline" htmlFor="syn-direction">
          <span>الاتجاه</span>
          <select id="syn-direction" value={direction} onChange={(event) => setDirection(event.target.value)}>
            <option value="one_way">أحادي</option>
            <option value="two_way">ثنائي</option>
          </select>
        </label>
        <button type="submit" className="btn btn-primary" disabled={pending}>
          {pending ? 'جارٍ الإضافة…' : 'إضافة'}
        </button>
      </form>

      <GatedView
        state={state}
        reload={reload}
        permission="search.synonyms.manage"
        reads="جدول المرادفات وقراءته وكتابته خلف المفتاح نفسه"
      >
        {(page) => (
          <>
            <div className="table-scroll" tabIndex={0} role="region" aria-label="قائمة المرادفات المسجَّلة">
              <table className="data-table">
                <caption className="sr-only">قائمة المرادفات</caption>
                <thead>
                  <tr>
                    <th scope="col">المصطلح</th>
                    <th scope="col">المرادف</th>
                    <th scope="col">الاتجاه</th>
                    <th scope="col">الحالة</th>
                    <th scope="col">المصدر</th>
                    <th scope="col">الأدلّة</th>
                    <th scope="col">إجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  {page.data.length === 0 ? (
                    <EmptyRows colSpan={7} message="لا مرادفات مسجَّلة بعد." />
                  ) : (
                    page.data.map((row) => (
                      <tr key={row.id}>
                        <td>{row.term}</td>
                        <td>{row.synonym}</td>
                        <td>{row.direction === 'two_way' ? 'ثنائي' : 'أحادي'}</td>
                        <td>
                          <span className={`chip chip-${row.state}`}>
                            {SYNONYM_STATE_LABELS[row.state] ?? row.state}
                          </span>
                        </td>
                        <td>{SYNONYM_SOURCE_LABELS[row.source] ?? row.source}</td>
                        <td>
                          {row.source === 'learned' ? (
                            <bdi dir="ltr">{count(row.evidenceCount)}</bdi>
                          ) : (
                            <span className="absent">—</span>
                          )}
                        </td>
                        <td className="row-actions">
                          {row.state === 'active' ? (
                            <button
                              type="button"
                              className="btn btn-ghost"
                              onClick={() => void mutate(row.id, { state: 'negative' })}
                            >
                              إيقاف
                            </button>
                          ) : (
                            <button
                              type="button"
                              className="btn btn-ghost"
                              onClick={() => void mutate(row.id, { state: 'active' })}
                            >
                              تفعيل
                            </button>
                          )}
                          <button
                            type="button"
                            className="btn btn-ghost"
                            onClick={() => void mutate(row.id, { action: 'rollback' })}
                          >
                            استرجاع
                          </button>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            <p className="page-note">
              <bdi dir="ltr">{count(page.meta.total)}</bdi> مرادفًا. «استرجاع» يعيد الحالة المسجَّلة في سجل
              التدقيق قبل آخر تعديل؛ إن لم يوجد تعديل سابق يرفض الخادم العملية.
            </p>
            <p className="page-note">
              الصفوف الموسومة «مُتعلَّم» كتبها <bdi dir="ltr">search:learn</bdi> ليلًا من أزواج الإنقاذ في
              سجلّ البحث: زائرٌ بحث فلم يجد، فأعاد الصياغة فوجد. و«الأدلّة» عدد الجلسات المستقلّة التي
              أظهرت الإنقاذ نفسه — الزائر الذي كرّر محاولته دليلٌ واحد لا عشرة. ودون عتبة التبنّي يبقى
              الصفّ «بانتظار» فلا يصل المحرّك. وإيقافُ صفٍّ مُتعلَّم يمنع اقتراحه ثانيةً.
            </p>
          </>
        )}
      </GatedView>
    </section>
  );
}
