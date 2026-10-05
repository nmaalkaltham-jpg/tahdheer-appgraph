'use client';
// UNIT: BROKEN-LINKS-PANEL
// FILE: broken-links-panel.tsx
// STYLE: none
// PARENT: PAGE-SEO
// USES: LIB-FORMAT, RESOURCE
// API: /admin/seo/not-found
// MAP: docs/PROJECT_MAP.md

/**
 * الروابط المكسورة (٤٠٤) — الجدول الذي كان يُكتب ويُشذَّب ولا يُقرأ.
 *
 * ─── لماذا وُجدت هذه الشاشة ───
 *
 * `not_found_hits` يُملأ من `POST /api/v1/not-found` في كلّ مرّة يصطدم زائرٌ
 * بصفحةٍ غير موجودة، ويُشذَّب ليلًا بـ`seo:prune-not-found`. وقارئه الوحيد في
 * المستودع كان `RedirectService::isKnown404()` — فحص وجودٍ يمنع تحويلًا إلى
 * مسارٍ معروفٍ بأنّه ٤٠٤، ولا يعرض حرفًا. فأيّ رابطٍ يكسر في المتجر الحيّ، وكم
 * مرّة طُلب، ومن أين جاء الزائر: كلّه مخزَّن منذ البداية ولا يراه إنسان.
 *
 * ─── ما لا تعرضه هذه الشاشة، ولماذا ───
 *
 * لا اتّجاه زمنيّ ولا «٤٠٤ اليوم» ولا رمز حالة ولا وكيل ولا بلد. الجدول
 * **تجميعيّ صراحةً**: صفٌّ واحد لكلّ مسار بعدّاد، لا سجلٌّ لكلّ طلب. رسمُ
 * منحنًى من عدّادٍ تراكميّ اختراعُ تاريخٍ لم يُخزَّن.
 *
 * وكلّ عمودٍ فارغ يقول «غير مقيس» لا «—» ولا صفرًا: `firstSeenAt` و
 * `lastSeenAt` أُضيفا بعد إنشاء الجدول، فصفوفٌ سبقتهما تحملهما فارغَين —
 * وعرضُ `created_at` مكانهما كذبٌ صغير يُقرأ حقيقة.
 *
 * ─── «أوّل مُحيل» لا «المُحيل» ───
 *
 * `recordNotFound` يحتفظ بأوّل مُحيلٍ رآه ويتجاهل ما بعده (الأصل أدلّ من
 * الأحدث). فالعمود مسمًّى بما يحمله فعلًا.
 */

import Link from 'next/link';
import { useMemo, useState } from 'react';
import { EmptyRows, ResourceView, useResource, type ResourceState } from '../../../components/resource';
import { count, dateTime } from '../../../lib/format';

/** عقد `GET /admin/seo/not-found` — مُعلَن محلّيًّا، فالشاشة تملك ما تقرأ. */
type RedirectRuleRef = {
  id: number;
  fromPath: string;
  toPath: string;
  statusCode: number;
};

type NotFoundRow = {
  path: string;
  hits: number;
  /** `null` ⇒ غير مقيس: صفٌّ سبق العمود، لا «لم يظهر بعد». */
  firstSeenAt: string | null;
  lastSeenAt: string | null;
  firstReferrer: string | null;
  /** `null` حكمٌ مقيس: المطابقة جرت ولم تجد قاعدةً مفعَّلة تغطّي المسار. */
  redirectRule: RedirectRuleRef | null;
};

type NotFoundReport = {
  data: NotFoundRow[];
  meta: {
    page: number;
    perPage: number;
    lastPage: number;
    total: number;
    sort: string;
    window: number | 'all';
    /** `null` حين لا سياسة احتفاظٍ فعّالة — لا وعد بكنسٍ لا منفّذ له. */
    retentionDays: number | null;
    /** لا جدول يخزّن آخر تشذيب: `null` دائمًا، وتُعرض «غير مقيس». */
    prunedBefore: string | null;
    oldestRemaining: string | null;
  };
};

const UNMEASURED = <span className="absent">غير مقيس</span>;

const SORTS: Array<{ value: string; label: string }> = [
  { value: 'hits', label: 'الأكثر طلبًا' },
  { value: 'last_seen', label: 'الأحدث ظهورًا' },
  { value: 'path', label: 'المسار' },
];

const WINDOWS: Array<{ value: string; label: string }> = [
  { value: 'all', label: 'كل ما في الجدول' },
  { value: '7', label: 'آخر ٧ أيّام' },
  { value: '30', label: 'آخر ٣٠ يومًا' },
  { value: '90', label: 'آخر ٩٠ يومًا' },
];

/**
 * ٤٠٣ يُسمّي الصلاحية الناقصة ولا يعرض «إعادة المحاولة».
 *
 * إعادةُ طلبٍ مرفوضٍ بحكم الدور تُرجع الرفض نفسه؛ الزرّ هنا يَعِد بما لا يقع.
 */
function ReportState({ state, reload, children }: {
  state: ResourceState<NotFoundReport>;
  reload: () => void;
  children: (report: NotFoundReport) => React.ReactNode;
}) {
  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>
          لا تملك صلاحية <bdi dir="ltr">analytics.read</bdi> لقراءة تقرير الروابط المكسورة.
        </p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {(report) => children(report)}
    </ResourceView>
  );
}

export function BrokenLinksPanel() {
  const [sort, setSort] = useState('hits');
  const [windowDays, setWindowDays] = useState('all');
  const [query, setQuery] = useState('');
  const [applied, setApplied] = useState({ sort: 'hits', window: 'all', q: '', page: 1 });

  const path = useMemo(() => {
    const params = new URLSearchParams({ sort: applied.sort, window: applied.window, page: String(applied.page) });
    if (applied.q) params.set('q', applied.q);
    return `/admin/seo/not-found?${params.toString()}`;
  }, [applied]);

  const { state, reload } = useResource<NotFoundReport>(path);

  return (
    <section>
      <ReportState state={state} reload={reload}>
        {(report) => (
          <>
            <form
              className="filters list-toolbar"
              onSubmit={(event) => {
                event.preventDefault();
                setApplied({ sort, window: windowDays, q: query, page: 1 });
              }}
            >
              <label className="field field-inline" htmlFor="nf-sort">
                <span>الترتيب</span>
                <select id="nf-sort" value={sort} onChange={(event) => setSort(event.target.value)}>
                  {SORTS.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </label>
              <label className="field field-inline" htmlFor="nf-window">
                <span>النافذة</span>
                <select id="nf-window" value={windowDays} onChange={(event) => setWindowDays(event.target.value)}>
                  {WINDOWS.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </label>
              <label className="field field-inline" htmlFor="nf-q">
                <span>بحث في المسار</span>
                <input
                  id="nf-q"
                  type="search"
                  value={query}
                  placeholder="/products/old"
                  onChange={(event) => setQuery(event.target.value)}
                />
              </label>
              <button type="submit" className="btn btn-primary">
                تطبيق
              </button>
              <span className="list-toolbar-end">
                <bdi dir="ltr">{count(report.data.length)}</bdi> من <bdi dir="ltr">{count(report.meta.total)}</bdi> مسارًا
              </span>
            </form>

            <div className="table-scroll" tabIndex={0} role="region" aria-label="المسارات التي ردّت 404">
              <table className="data-table">
                <caption className="sr-only">المسارات التي بلّغ عنها المتجر بـ404، وقاعدة التحويل التي تغطّي كلًّا منها إن وُجدت</caption>
                <thead>
                  <tr>
                    <th scope="col">المسار</th>
                    <th scope="col">الطلبات</th>
                    <th scope="col">أوّل ظهور</th>
                    <th scope="col">آخر ظهور</th>
                    <th scope="col">أوّل مُحيل</th>
                    <th scope="col">قاعدة التحويل</th>
                  </tr>
                </thead>
                <tbody>
                  {report.data.length === 0 ? (
                    /*
                     * فراغٌ لا يُقرأ «المتجر سليم»: قد يكون المتجر لم يبلّغ
                     * بعد، أو شذّب التشذيبُ ما كان، أو ضيّق المرشِّح النافذة.
                     */
                    <EmptyRows colSpan={6} message="لا صفوف 404 في هذه النافذة. الفراغ لا يعني سلامة المتجر — قد لا يكون بلّغ بعد." />
                  ) : (
                    report.data.map((row) => (
                      <tr key={row.path}>
                        <td>
                          <bdi dir="ltr">{row.path}</bdi>
                        </td>
                        <td>
                          <bdi dir="ltr">{count(row.hits)}</bdi>
                        </td>
                        <td>{row.firstSeenAt === null ? UNMEASURED : <bdi>{dateTime(row.firstSeenAt)}</bdi>}</td>
                        <td>{row.lastSeenAt === null ? UNMEASURED : <bdi>{dateTime(row.lastSeenAt)}</bdi>}</td>
                        <td>{row.firstReferrer === null ? UNMEASURED : <bdi dir="ltr">{row.firstReferrer}</bdi>}</td>
                        <td>
                          {row.redirectRule ? (
                            <>
                              <span className="chip chip-paid">مغطّى</span>
                              <div style={{ color: 'var(--text-muted)', fontSize: 12 }}>
                                <bdi dir="ltr">
                                  {row.redirectRule.fromPath} → {row.redirectRule.toPath} ({row.redirectRule.statusCode})
                                </bdi>
                              </div>
                            </>
                          ) : (
                            /*
                             * الحلقة مغلقة في الشيفرة: `isKnown404()` يستشير
                             * هذا الجدول قبل قبول قاعدة. فالمشغّل الذي يرى
                             * مسارًا بمئات الطلبات يريد كتابة القاعدة من هنا.
                             */
                            <Link className="btn btn-ghost" href="/redirects">
                              أنشئ قاعدة تحويل
                            </Link>
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            {/*
              * تعبئةُ `from` مسبقًا غير ممكنة اليوم: نموذج قواعد التحويل لا
              * يقرأ معاملات الرابط. فالوعد بها هنا زرٌّ يكذب — والصادق أن
              * يُقال إنّ المسار يُنسخ يدويًّا.
              */}
            <p className="state-line">
              نموذج قواعد التحويل لا يقبل تعبئةً مسبقة اليوم — انسخ المسار من الصفّ والصقه في حقل «المصدر».
            </p>

            <dl className="filters">
              <dt>مدّة الاحتفاظ</dt>
              <dd>
                {report.meta.retentionDays === null ? (
                  UNMEASURED
                ) : (
                  <>
                    <bdi dir="ltr">{count(report.meta.retentionDays)}</bdi> يومًا منذ آخر ظهور
                  </>
                )}
              </dd>
              <dt>أقدم صفّ باقٍ</dt>
              <dd>{report.meta.oldestRemaining === null ? UNMEASURED : <bdi>{dateTime(report.meta.oldestRemaining)}</bdi>}</dd>
              {/* لا جدول يخزّن جولات التشذيب: الغياب يُعلَن كي تُقرأ القائمة بحذرٍ صحيح. */}
              <dt>آخر تشذيب</dt>
              <dd>{report.meta.prunedBefore === null ? UNMEASURED : <bdi>{dateTime(report.meta.prunedBefore)}</bdi>}</dd>
            </dl>

            {report.meta.lastPage > 1 ? (
              <div className="list-toolbar">
                <button
                  type="button"
                  className="btn"
                  disabled={report.meta.page <= 1}
                  onClick={() => setApplied((current) => ({ ...current, page: current.page - 1 }))}
                >
                  السابق
                </button>
                <span>
                  صفحة <bdi dir="ltr">{count(report.meta.page)}</bdi> من <bdi dir="ltr">{count(report.meta.lastPage)}</bdi>
                </span>
                <button
                  type="button"
                  className="btn"
                  disabled={report.meta.page >= report.meta.lastPage}
                  onClick={() => setApplied((current) => ({ ...current, page: current.page + 1 }))}
                >
                  التالي
                </button>
              </div>
            ) : null}
          </>
        )}
      </ReportState>
    </section>
  );
}
