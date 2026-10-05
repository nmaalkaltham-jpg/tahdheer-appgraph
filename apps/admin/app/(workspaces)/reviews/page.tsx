'use client';
// UNIT: PAGE-REVIEWS
// FILE: page.tsx
// ROUTE: /reviews
// STYLE: none
// PARENT: none
// USES: EXPORT-CSV, LIB-ADMIN-API, LIB-EXPORT-CSV, LIB-FORMAT, RESOURCE
// API: POST /admin/reviews
// MAP: docs/PROJECT_MAP.md

/**
 * المراجعات — /reviews.
 *
 * كل مراجعة يكتبها عميل تدخل `pending`، ولم يكن في اللوحة سطحٌ يعتمدها: باب
 * مفتوح للكتابة مغلق للنشر. هذه الشاشة تفتحه.
 *
 * الطابور بالأقدم أوّلًا لا بالأحدث — المراجعة التي طال انتظارها أولى بالقرار،
 * وترتيبٌ بالأحدث يدفن أقدمها إلى الأبد.
 *
 * ولا يُعرض متوسّط ولا توزيع نجوم هنا: تلك أرقام صفحة المنتج، ومكانها الواجهة
 * حيث يقرأها المشتري. الشاشة هنا للقرار، وما لا يُتَّخذ عليه قرار لا يُعرض.
 */

import { useCallback, useMemo, useState, type ReactNode } from 'react';
import { EmptyRows, PageHeader, ResourceView, useResource, type ResourceState } from '../../../components/resource';
import { api, firstError } from '../../../lib/admin-api';
import { count, dateTime } from '../../../lib/format';
import { ExportCsv } from '../../../components/export-csv';
import type { CsvColumn } from '../../../lib/export-csv';

interface ReviewRow {
  id: number;
  rating: number;
  title: string | null;
  body: string;
  status: 'pending' | 'approved' | 'rejected';
  verified: boolean;
  product: string | null;
  productSlug: string | null;
  customer: string | null;
  helpfulCount: number;
  reply: string | null;
  createdAt: string | null;
  moderatedAt: string | null;
}

interface ReviewsPayload {
  data: ReviewRow[];
  counts: Record<string, number>;
}

/*
 * نصّ المراجعة يُصدَّر كاملًا لا مقتطعًا: الملفّ يُقرأ للحكم على المحتوى، و
 * اقتطاعه يُخفي ما يُحكَم عليه. وهو الحقل الذي يُفعّل الاقتباس فعلًا — فواصل
 * وأسطر جديدة داخل نصّ حرّ.
 */
const REVIEW_CSV: readonly CsvColumn<ReviewRow>[] = [
  { header: 'التقييم', value: (r) => r.rating },
  { header: 'الحالة', value: (r) => STATUS_LABELS[r.status] ?? r.status },
  { header: 'المنتج', value: (r) => r.product },
  { header: 'العميل', value: (r) => r.customer },
  { header: 'شراء موثّق', value: (r) => (r.verified ? 'نعم' : 'لا') },
  { header: 'العنوان', value: (r) => r.title },
  { header: 'النصّ', value: (r) => r.body },
  { header: 'أفادت', value: (r) => r.helpfulCount },
  { header: 'ردّ المتجر', value: (r) => r.reply },
  { header: 'أُنشئت (ISO)', value: (r) => r.createdAt },
  { header: 'قُرّرت (ISO)', value: (r) => r.moderatedAt },
];

const STATUS_LABELS: Record<string, string> = {
  all: 'الكل',
  pending: 'بانتظار القرار',
  approved: 'منشورة',
  rejected: 'مرفوضة',
};

const TABS = ['pending', 'approved', 'rejected', 'all'] as const;

/** النجوم نصًّا: «٤ من ٥» يقرأه قارئ الشاشة، والرموز وحدها لا. */
function stars(rating: number): string {
  return `${count(rating)} من ٥`;
}

/**
 * الخطأ يُقرأ برمزه لا بنصّ الخادم وحده.
 *
 * الطابور كلّه — قراءةً وقرارًا — خلف `reviews.moderate` وحدها: من يرى الطابور
 * هو من يفرغه. فمن فتح `‎/reviews‎` برابطٍ مباشر بلا هذه الصلاحية كان يقرأ
 * رسالةً عامّة وزرّ إعادة محاولةٍ لا يمنح صلاحية. والاسم يفرق بين منعٍ مقصود
 * وعطبٍ في الشاشة.
 *
 * الحارس: `routes/api-admin.php:380` — بادئة `reviews` كلّها
 * ‎->middleware(AdminAuthorize::class.':reviews.moderate')‎.
 */
function ReviewsState({ state, reload, children }: {
  state: ResourceState<ReviewsPayload>;
  reload: () => void;
  children: (payload: ReviewsPayload) => ReactNode;
}) {
  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>لا تملك صلاحية <bdi dir="ltr">reviews.moderate</bdi> لقراءة المراجعات والبتّ فيها.</p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {children}
    </ResourceView>
  );
}

export default function ReviewsPage() {
  const [status, setStatus] = useState<string>('pending');
  const [busy, setBusy] = useState<number | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [replyTo, setReplyTo] = useState<number | null>(null);
  const [replyBody, setReplyBody] = useState('');

  const path = useMemo(() => `/admin/reviews?status=${status}`, [status]);
  const { state, reload } = useResource<ReviewsPayload>(path);

  const act = useCallback(
    async (review: ReviewRow, action: 'approve' | 'reject' | 'reply', payload?: Record<string, unknown>) => {
      setBusy(review.id);
      setFailure(null);

      const result = await api.post(`/admin/reviews/${review.id}/${action}`, payload);
      setBusy(null);

      // القرار لا يظهر على الشاشة قبل أن يقبله الخادم: اعتمادٌ معروض بلا حفظ
      // يعني مراجعةً يظنّها المشرف منشورة وهي ليست كذلك.
      if (!result.ok) {
        setFailure(firstError(result));

        return;
      }

      setReplyTo(null);
      setReplyBody('');
      reload();
    },
    [reload],
  );

  return (
    <section>
      <PageHeader trail="المتجر" title="المراجعات" />

      <p className="state-line">
        المراجعة لا تظهر في صفحة المنتج قبل اعتمادها. الطابور بالأقدم أوّلًا — والانتظار الطويل
        يعني مشتريًا كتب رأيه ولم يره أحد.
      </p>

      <ReviewsState state={state} reload={reload}>
        {(payload) => (
          <>
            <div className="filters" role="tablist" aria-label="حالة المراجعة">
              {TABS.map((key) => (
                <button
                  key={key}
                  type="button"
                  role="tab"
                  aria-selected={status === key}
                  className={status === key ? 'btn btn-primary' : 'btn btn-ghost'}
                  onClick={() => setStatus(key)}
                >
                  {STATUS_LABELS[key]} ({count(payload.counts[key] ?? 0)})
                </button>
              ))}
            </div>

            {failure ? (
              <div className="state-error" role="alert">
                <p>{failure}</p>
              </div>
            ) : null}

            {/* الطوق منطقةُ بلوغٍ بلوحة المفاتيح: كروم لا يبئّر منطقة تمرير من تلقائه (globals.css). */}
            <div className="table-scroll" tabIndex={0} role="region" aria-label="طابور المراجعات">
              <table className="data-table">
                <caption className="sr-only">المراجعات</caption>
                <thead>
                  <tr>
                    <th scope="col">المراجعة</th>
                    <th scope="col">التقييم</th>
                    <th scope="col">المنتج</th>
                    <th scope="col">وردت</th>
                    <th scope="col">القرار</th>
                  </tr>
                </thead>
                <tbody>
                  {payload.data.length === 0 ? (
                    <EmptyRows
                      colSpan={5}
                      message="لا مراجعات في هذه الحالة. المراجعات تصل من صفحات المنتجات بعد شراء موثّق."
                    />
                  ) : (
                    payload.data.map((review) => (
                      <tr key={review.id}>
                        <th scope="row">
                          {review.title ?? '— بلا عنوان —'}
                          <small>{review.body}</small>
                          <small>
                            {review.customer ?? 'عميل'}
                            {/* شارة الشراء الموثّق من سطر طلب حقيقي، لا ادّعاء. */}
                            {review.verified ? ' · شراء موثّق' : ' · بلا طلب مرتبط'}
                          </small>
                          {review.reply ? <small>ردّ المتجر: {review.reply}</small> : null}
                        </th>
                        <td>{stars(review.rating)}</td>
                        <td>{review.product ?? '—'}</td>
                        <td>{review.createdAt ? dateTime(review.createdAt) : '—'}</td>
                        <td>
                          {review.status !== 'approved' ? (
                            <button
                              type="button"
                              className="btn btn-primary"
                              disabled={busy === review.id}
                              onClick={() => void act(review, 'approve')}
                            >
                              اعتمد
                            </button>
                          ) : null}

                          {review.status !== 'rejected' ? (
                            <button
                              type="button"
                              className="btn btn-ghost"
                              disabled={busy === review.id}
                              onClick={() => void act(review, 'reject')}
                            >
                              ارفض
                            </button>
                          ) : null}

                          {/* الردّ للمعتمَدة وحدها: ردٌّ تحت مراجعة لم تُنشر نصفُ حوار. */}
                          {review.status === 'approved' ? (
                            replyTo === review.id ? (
                              <div className="stack">
                                <label className="field" htmlFor={`reply-${review.id}`}>
                                  <span>ردّ المتجر</span>
                                  <textarea
                                    id={`reply-${review.id}`}
                                    rows={2}
                                    value={replyBody}
                                    onChange={(event) => setReplyBody(event.target.value)}
                                  />
                                </label>
                                <button
                                  type="button"
                                  className="btn btn-primary"
                                  disabled={busy === review.id || replyBody.trim().length < 2}
                                  onClick={() => void act(review, 'reply', { body: replyBody })}
                                >
                                  انشر الردّ
                                </button>
                              </div>
                            ) : (
                              <button
                                type="button"
                                className="btn btn-ghost"
                                onClick={() => {
                                  setReplyTo(review.id);
                                  setReplyBody(review.reply ?? '');
                                }}
                              >
                                {review.reply ? 'عدّل الردّ' : 'ردّ'}
                              </button>
                            )
                          ) : null}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            {/*
              * `rows` لا `paged`: نقطة المراجعات لا تُرقّم أصلًا (ReviewController
              * ::index يُعيد الصفوف كلّها)، فالمحمَّل هو الكلّ ونداءٌ ثانٍ عبث.
              */}
            <div className="list-foot">
              <p className="page-note">
                <bdi dir="ltr">{count(payload.data.length)}</bdi> مراجعة في العرض الحالي
              </p>
              <ExportCsv filename="reviews" columns={REVIEW_CSV} source={{ kind: 'rows', rows: payload.data }} />
            </div>
          </>
        )}
      </ReviewsState>
    </section>
  );
}
