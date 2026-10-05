'use client';
// UNIT: DEMAND-PANEL
// FILE: demand-panel.tsx
// STYLE: none
// PARENT: PAGE-ANALYTICS
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-FORMAT, RESOURCE, _VOCABULARY
// API: PATCH /admin/demand
// MAP: docs/PROJECT_MAP.md

/**
 * الطلب الفائت — /demand.
 *
 * ما طُلب ولم يوجد، مرتَّبًا بعدد من طلبه. ليس بريدًا وارِدًا يُقرأ ويُؤرشَف،
 * بل تقريرُ طلبٍ يقود قرار الإنتاج: السؤال هنا «ما أكثر ما يُطلب ولا نملكه»
 * لا «ما آخر رسالة وصلت» — ولهذا الترتيب بالشدّة لا بالتاريخ.
 *
 * وسيلة التواصل تصل مُقنَّعة من الخادم، واللوحة لا تفكّ القناع لأنها لا تملكه:
 * قائمةٌ تعرض مئة عنوان كامل على الشاشة تسريبٌ بحكم التصميم، لا خيار عرض.
 */

import Link from 'next/link';
import { useCallback, useMemo, useState } from 'react';
import { useSession } from '../../../components/admin-session';
import { EmptyRows, ResourceView, useResource } from '../../../components/resource';
import { api, firstError } from '../../../lib/admin-api';
import { count, dateTime } from '../../../lib/format';
import { DEMAND_STATUS_LABELS } from './_vocabulary';

interface DemandRow {
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
  decisionNote: string | null;
  hits: number;
  contact: string | null;
  contactKind: 'email' | 'phone' | null;
  firstSeenAt: string | null;
  lastSeenAt: string | null;
}

interface DemandPayload {
  data: DemandRow[];
  counts: Record<string, number>;
  statuses: string[];
}

const SOURCE_LABELS: Record<string, string> = {
  form: 'نموذج الطلب',
  zero_result: 'بحث بلا نتيجة',
};

/** التقاطع المطلوب بكلمات — وحقلٌ لم يُحدَّد يُترك، لا يُملأ بـ«الكل». */
function intersection(row: DemandRow): string {
  const parts = [row.subject, row.grade, row.stage, row.semester, row.kind].filter(Boolean);

  return parts.length > 0 ? parts.join(' · ') : '— بلا تصنيف —';
}

export function DemandPanel() {
  /*
   * القراءة تقريرٌ والقرار إنتاج: القائمة تفتح ‎/demand‎ بـ`analytics.read`
   * (`lib/nav.ts`) وبها يُقرأ الجدول، أمّا `PATCH admin/demand/{id}` ففرضه
   * `products.create` (`routes/api-admin.php`) — لأنّ «مُخطَّط» و«نُشر» تعهّدٌ
   * بملفّ يُنتَج لا وسمُ بريد. أربعة أدوار تملك الأولى دون الثانية (مدير SEO
   * ومدير البحث والتحليلات وقراءة فقط) وكانت ترى صفًّا كامل الأزرار يردّ ٤٠٣
   * في كلّ ضغطة. القياس هنا لا في `decide`: الحارس داخل المُنادي يمنع النداء
   * ويُبقي الزرّ معروضًا، وهو عين العيب.
   */
  const { can } = useSession();
  const canDecide = can('products.create');
  const [status, setStatus] = useState('new');
  const [busy, setBusy] = useState<number | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const path = useMemo(() => `/admin/demand?status=${status}`, [status]);
  const { state, reload } = useResource<DemandPayload>(path);
  const forbidden = state.status === 'error' && state.code === 403;

  const decide = useCallback(
    async (row: DemandRow, next: string) => {
      setBusy(row.id);
      setFailure(null);

      const result = await api.patch(`/admin/demand/${row.id}`, { status: next });
      setBusy(null);

      // لا تتغيّر الشاشة قبل أن يقبل الخادم: نجاحٌ معروض بلا حفظ قرارٌ ضائع.
      if (!result.ok) {
        setFailure(firstError(result));

        return;
      }

      reload();
    },
    [reload],
  );

  return (
    <section>

      <p className="state-line">
        كل صفّ تقاطعٌ طُلب ولم يوجد، وعدّاده عدد من طلبوه. الترتيب بالشدّة: أعلى الصفوف أكثر ما
        يُطلب ولا يملكه المتجر — وهو أوّل ما يستحقّ الإنتاج.
      </p>

      {/*
        النصف الآخر من الطلب الفائت هو الاستعلام الذي لم يُرجع نتيجة، وهو
        مقيسٌ أصلًا في ذكاء البحث. يُشار إليه ولا يُنسخ هنا: جدولان لرقم واحد
        يفترقان أوّل مرة يُحدَّث أحدهما.
      */}
      <p className="state-line">
        الاستعلامات التي لم تُرجع نتيجة طلبٌ فائت أيضًا، وهي مقيسة في{' '}
        <Link href="/search">ذكاء البحث</Link> بصياغة الباحثين أنفسهم.
      </p>

      {/* لا زرّ معطَّل: المعطَّل يَعِد بوقتٍ، والمانع هنا صلاحية تُسمّى كما يسمّيها الخادم. */}
      {canDecide || forbidden ? null : (
        <p className="absent" role="note">
          التقرير للقراءة — تغيير قرار الطلب يحتاج صلاحية إنشاء منتج{' '}
          <bdi dir="ltr">products.create</bdi> التي لا يملكها دورك.
        </p>
      )}

      {/*
        الخطأ يُقرأ برمزه لا بنصّ الخادم وحده (نمط ‎quotes/[id]/page.tsx‎):
        `AdminAuthorize` يردّ ٤٠٣ على ‎GET admin/demand‎ حين ينقص
        `analytics.read`، ووصولها كرسالةٍ عامّة يدفع المشغّل إلى «أعد المحاولة»
        على شيءٍ لن يعود. الصلاحية تُسمّى كما يسمّيها الخادم لا بمرادفٍ لطيف.
      */}
      {forbidden ? (
        <div className="state-error" role="alert">
          <p>
            لا تملك صلاحية <bdi dir="ltr">analytics.read</bdi> لقراءة تقرير الطلب الفائت.
          </p>
        </div>
      ) : (
        <ResourceView state={state} reload={reload}>
          {(payload) => (
            <>
              <div className="filters" role="tablist" aria-label="حالة الطلب">
                {['all', ...payload.statuses].map((key) => (
                  <button
                    key={key}
                    type="button"
                    role="tab"
                    aria-selected={status === key}
                    className={status === key ? 'btn btn-primary' : 'btn btn-ghost'}
                    onClick={() => setStatus(key)}
                  >
                    {DEMAND_STATUS_LABELS[key] ?? key} ({count(payload.counts[key] ?? 0)})
                  </button>
                ))}
              </div>

              {failure ? (
                <div className="state-error" role="alert">
                  <p>{failure}</p>
                </div>
              ) : null}

              <table className="data-table">
                <caption className="sr-only">الطلبات الفائتة</caption>
                <thead>
                  <tr>
                    <th scope="col">التقاطع المطلوب</th>
                    <th scope="col">طلبوه</th>
                    <th scope="col">المصدر</th>
                    <th scope="col">وسيلة التواصل</th>
                    <th scope="col">آخر طلب</th>
                    <th scope="col">القرار</th>
                  </tr>
                </thead>
                <tbody>
                  {payload.data.length === 0 ? (
                    <EmptyRows
                      colSpan={6}
                      message="لا طلبات في هذه الحالة. الصفوف تصل من نموذج «اطلب ملفًا غير متوفّر» ومن عمليات البحث التي لم تُرجع نتيجة."
                    />
                  ) : (
                    payload.data.map((row) => (
                      <tr key={row.id}>
                        <th scope="row">
                          {intersection(row)}
                          {row.note ? <small>{row.note}</small> : null}
                          {row.query ? <small dir="auto">الاستعلام: {row.query}</small> : null}
                        </th>
                        <td>{count(row.hits)}</td>
                        <td>{SOURCE_LABELS[row.source] ?? row.source}</td>
                        <td>
                          {row.contact ? <span dir="ltr">{row.contact}</span> : 'لم يترك وسيلة'}
                        </td>
                        <td>{row.lastSeenAt ? dateTime(row.lastSeenAt) : '—'}</td>
                        <td>
                          {canDecide ? (
                            payload.statuses
                              .filter((next) => next !== row.status)
                              .map((next) => (
                                <button
                                  key={next}
                                  type="button"
                                  className="btn btn-ghost"
                                  disabled={busy === row.id}
                                  onClick={() => void decide(row, next)}
                                >
                                  {DEMAND_STATUS_LABELS[next] ?? next}
                                </button>
                              ))
                          ) : (
                            // الحالة القائمة تُقرأ ولا تُغيَّر — والسبب مكتوب مرّة فوق الجدول لا في كل صفّ.
                            <span className="absent">{DEMAND_STATUS_LABELS[row.status] ?? row.status}</span>
                          )}
                          {row.decisionNote ? <small>{row.decisionNote}</small> : null}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </>
          )}
        </ResourceView>
      )}
    </section>
  );
}
