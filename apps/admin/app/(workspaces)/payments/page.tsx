'use client';
// UNIT: PAGE-PAYMENTS
// FILE: page.tsx
// ROUTE: /payments
// STYLE: none
// PARENT: none
// USES: LIB-ADMIN-API, LIB-FORMAT, RESOURCE
// API: /admin/payments/reconciliation, /admin/payments/refunds, /admin/payments/transactions
// MAP: docs/PROJECT_MAP.md

/**
 * المدفوعات — دفتر المعاملات والاستردادات.
 *
 * ─── الفجوة التي تسدّها هذه الصفحة ───
 *
 * لوحة الإدارة كان لها مسار مدفوعات واحد: صحّة المزوّد. أمّا «ما الذي جرى
 * لهذه العمليّة» — أيّ محاولة وقعت، وبأيّ أداة، ولماذا فشلت، وأيّ حدث وصل
 * من البوّابة — فلم يكن له سطح، والجداول قائمة منذ الإطلاق.
 *
 * ─── الرقم الذي يستحقّ الصدارة ───
 *
 * «عمليّات نجحت وطلبها غير مدفوع» ليس مؤشّرًا بين مؤشّرات: هو مالٌ حُصّل
 * ولم يُسلَّم مقابله. يُحسب في الخادم ويُعرض بلون التنبيه حين يتجاوز الصفر،
 * لأنّ العين لا تلتقطه من جدول من خمسٍ وعشرين صفًّا.
 *
 * ولا سرّ يُعرض: حمولة حدث البوّابة تُنقّى في الخادم قبل أن تصل هنا.
 */

import { useState, type ReactNode } from 'react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { EmptyRows, PageHeader, ResourceView, useResource, type ResourceState } from '../../../components/resource';
import type {
  PaymentTransactionDetail,
  PaymentTransactions,
  RefundLedger,
} from '../../../lib/admin-api';
import { FUNDING_LABELS, REFUND_STATUS_LABELS } from '../../../lib/admin-api-types';
import { api, firstError } from '../../../lib/admin-api';
import { idempotencyKey } from '../../../lib/idempotency';
import { useSession } from '../../../components/admin-session';
import { count, dateTime, money } from '../../../lib/format';

type View = 'transactions' | 'refunds' | 'reconciliation';

const STATE_LABELS: Record<string, string> = {
  CAPTURED: 'محصَّلة',
  FAILED: 'فاشلة',
  PENDING: 'معلّقة',
  INITIATED: 'بدأت',
  ABANDONED: 'مهجورة',
  CREATED: 'أُنشئت',
  AUTHORIZED: 'مُصرَّح بها',
  REFUNDED: 'مسترَدّة',
};

/**
 * الخطأ يُقرأ برمزه لا بنصّ الخادم وحده.
 *
 * دفتر المعاملات والاستردادات يُحرَس بـ`orders.read` — لا بصلاحية «مدفوعات»
 * مستقلّة، والتعليق في ملفّ المسارات يقول لماذا: العمليّة واقعةٌ على طلب، ومن
 * يرى الطلب يرى دفعه. فمن فتح `‎/payments‎` برابطٍ مباشر بلا هذه الصلاحية كان
 * يقرأ رسالةً عامّة وزرّ إعادة محاولةٍ لن ينجح؛ والاسم أصدق من الزرّ.
 *
 * الحارس: `routes/api-admin.php:181` و`:185` — ‎AdminAuthorize::class.':orders.read'‎.
 *
 * والقسمان وحدهما يُلَفّان: سجلّ الأحداث لا يُطلَب إلّا من داخل جدولٍ وصل
 * بالفعل، فرمزُ 403 عليه غيرُ بالغ.
 */
function LedgerState<T>({ state, reload, children }: {
  state: ResourceState<T>;
  reload: () => void;
  children: (data: T) => ReactNode;
}) {
  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>لا تملك صلاحية <bdi dir="ltr">orders.read</bdi> لقراءة دفتر المدفوعات.</p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {children}
    </ResourceView>
  );
}

/**
 * قراءة الرابط وكتابته — لتصير كلّ بطاقة تنبيهٍ في لوحة التشغيل وجهةً حقيقيّة.
 *
 * كان القسم والمرشِّح حالتين محلّيّتين، فكان «دفعات فاشلة: ٣» يهبط بالمشغّل
 * على أوّل صفحة المعاملات بلا ترشيح: يرى الرقم ثمّ يبحث عنه بيده. الترشيح في
 * الرابط يجعل الوجهة هي الصفوف نفسها، ويجعل السطر قابلًا للمشاركة والرجوع.
 */
function useUrlFilter(): {
  read: (key: string) => string;
  write: (next: Record<string, string>) => void;
} {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();

  return {
    read: (key) => params.get(key) ?? '',
    write: (next) => {
      const merged = new URLSearchParams(params.toString());
      for (const [key, value] of Object.entries(next)) {
        if (value === '') merged.delete(key);
        else merged.set(key, value);
      }
      const query = merged.toString();
      router.replace(query === '' ? pathname : `${pathname}?${query}`, { scroll: false });
    },
  };
}

export default function PaymentsPage() {
  const { read, write } = useUrlFilter();
  const requested = read('view');
  const view: View =
    requested === 'refunds' ? 'refunds' : requested === 'reconciliation' ? 'reconciliation' : 'transactions';

  return (
    <section>
      <PageHeader trail="المبيعات والعملاء" title="المدفوعات" />
      <div className="filters" role="tablist" aria-label="أقسام المدفوعات">
        <button
          type="button"
          role="tab"
          aria-selected={view === 'transactions'}
          className={view === 'transactions' ? 'btn btn-primary' : 'btn btn-ghost'}
          onClick={() => write({ view: '' })}
        >
          المعاملات
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={view === 'refunds'}
          className={view === 'refunds' ? 'btn btn-primary' : 'btn btn-ghost'}
          onClick={() => write({ view: 'refunds' })}
        >
          الاستردادات
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={view === 'reconciliation'}
          className={view === 'reconciliation' ? 'btn btn-primary' : 'btn btn-ghost'}
          onClick={() => write({ view: 'reconciliation' })}
        >
          المطابقة
        </button>
      </div>

      {view === 'transactions' ? <Transactions /> : view === 'refunds' ? <Refunds /> : <Reconciliation />}
    </section>
  );
}

function Transactions() {
  const { read, write } = useUrlFilter();
  const applied = { state: read('state'), q: read('q') };
  /*
   * المسوّدة محلّيّة والمطبَّق في الرابط: الكتابة في الحقل لا تُعيد الجلب عند
   * كلّ حرف، والضغط على «تطبيق» وحده ينقل المسوّدة إلى الرابط.
   */
  const [state, setState] = useState(applied.state);
  const [query, setQuery] = useState(applied.q);
  // ‎?tx=‎ يفتح عمليّةً مباشرةً — روابط التسوية تهبط على تفاصيلها وزرّ ردّها.
  const [openId, setOpenId] = useState<number | null>(() => {
    const tx = Number(read('tx'));
    return Number.isInteger(tx) && tx > 0 ? tx : null;
  });

  const params = new URLSearchParams();
  if (applied.state) params.set('state', applied.state);
  if (applied.q) params.set('q', applied.q);
  const suffix = params.toString();
  const { state: resource, reload } = useResource<PaymentTransactions>(
    `/admin/payments/transactions${suffix ? `?${suffix}` : ''}`,
  );

  return (
    <LedgerState state={resource} reload={reload}>
      {(page) => (
        <>
          <div className="cards">
            <article className="card">
              <div className="label">معاملات</div>
              <div className="value">
                <bdi dir="ltr">{count(page.summary.transactions)}</bdi>
              </div>
              <p className="card-reason">
                {page.summary.failureRate === null
                  ? 'لا عمليّات — لا نسبة فشل'
                  : `فشل ${Math.round(page.summary.failureRate * 1000) / 10}٪`}
              </p>
            </article>
            <article
              className={page.summary.capturedButUnpaidOrders > 0 ? 'card card-alert' : 'card'}
            >
              <div className="label">محصَّلة وطلبها غير مدفوع</div>
              <div className="value">
                <bdi dir="ltr">{count(page.summary.capturedButUnpaidOrders)}</bdi>
              </div>
              <p className="card-reason">مالٌ حُصّل ولم يُسلَّم مقابله</p>
            </article>
            <article className="card">
              <div className="label">أحداث البوّابة</div>
              <div className="value">
                <bdi dir="ltr">{count(page.summary.gatewayEvents)}</bdi>
              </div>
              <p className="card-reason">كلّ ما وصل من المزوّد</p>
            </article>
          </div>

          <form
            className="filters list-toolbar"
            onSubmit={(event) => {
              event.preventDefault();
              write({ state, q: query });
            }}
          >
            <label className="field field-inline" htmlFor="pay-state">
              <span>الحالة</span>
              <select id="pay-state" value={state} onChange={(event) => setState(event.target.value)}>
                <option value="">الكلّ</option>
                {page.filters.states.map((value) => (
                  <option key={value} value={value}>
                    {STATE_LABELS[value] ?? value}
                  </option>
                ))}
              </select>
            </label>
            <label className="field field-inline" htmlFor="pay-q">
              <span>بحث</span>
              <input
                id="pay-q"
                type="search"
                value={query}
                placeholder="رقم الطلب أو مرجع البوّابة"
                onChange={(event) => setQuery(event.target.value)}
              />
            </label>
            <button type="submit" className="btn btn-primary">
              تطبيق
            </button>
            <span className="list-toolbar-end">
              <bdi dir="ltr">{count(page.data.length)}</bdi> من{' '}
              <bdi dir="ltr">{count(page.meta.total)}</bdi>
            </span>
          </form>

          {/* الطوق منطقةُ بلوغٍ بلوحة المفاتيح: كروم لا يبئّر منطقة تمرير من تلقائه (globals.css). */}
          <div className="table-scroll" tabIndex={0} role="region" aria-label="معاملات الدفع">
            <table className="data-table">
              <caption className="sr-only">معاملات الدفع</caption>
              <thead>
                <tr>
                  <th scope="col">الحالة</th>
                  <th scope="col">الطلب</th>
                  <th scope="col">المزوّد</th>
                  <th scope="col">الأداة</th>
                  <th scope="col">المرجع</th>
                  <th scope="col">حين</th>
                  <th scope="col">الأحداث</th>
                </tr>
              </thead>
              <tbody>
                {page.data.length === 0 ? (
                  <EmptyRows colSpan={7} message="لا معاملة بهذا المرشِّح." />
                ) : (
                  page.data.map((row) => (
                    <tr key={row.id}>
                      <td>
                        <span className={`chip chip-${row.state.toLowerCase()}`}>
                          {STATE_LABELS[row.state] ?? row.state}
                        </span>
                      </td>
                      <td>
                        {row.orderId === null ? (
                          <span className="absent">—</span>
                        ) : (
                          <a href={`/orders/${row.orderId}`}>
                            <bdi dir="ltr">{row.orderNumber ?? `#${row.orderId}`}</bdi>
                          </a>
                        )}
                      </td>
                      <td>
                        <bdi dir="ltr">{row.provider}</bdi>
                      </td>
                      <td>
                        <bdi dir="ltr">{row.instrument ?? row.method ?? '—'}</bdi>
                      </td>
                      <td>
                        <bdi dir="ltr">{row.intentId ?? '—'}</bdi>
                      </td>
                      <td>{row.at ? dateTime(row.at) : <span className="absent">—</span>}</td>
                      <td>
                        <button
                          type="button"
                          className="btn btn-ghost"
                          onClick={() => setOpenId(openId === row.id ? null : row.id)}
                        >
                          {openId === row.id ? 'إخفاء' : 'عرض'}
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {openId === null ? null : <EventTrail paymentId={openId} />}
        </>
      )}
    </LedgerState>
  );
}

/** سجلّ أحداث البوّابة لعمليّة واحدة — الحمولة مُنقّاة في الخادم. */
function EventTrail({ paymentId }: { paymentId: number }) {
  const { state, reload } = useResource<{ data: PaymentTransactionDetail }>(
    `/admin/payments/transactions/${paymentId}`,
  );

  return (
    <section className="panel">
      <h2>سجلّ أحداث البوّابة</h2>
      <ResourceView state={state} reload={reload}>
        {(payload) => (
          <>
          <FundingSummary tx={payload.data} onRefunded={reload} />
          {payload.data.events.length === 0 ? (
            <p className="page-note">لا حدث بوّابة مسجَّل لهذه العمليّة.</p>
          ) : (
            <div className="table-scroll" tabIndex={0} role="region" aria-label="أحداث بوّابة الدفع">
              <table className="data-table">
                <caption className="sr-only">أحداث البوّابة</caption>
                <thead>
                  <tr>
                    <th scope="col">الحدث</th>
                    <th scope="col">حين</th>
                    <th scope="col">الحمولة (مُنقّاة)</th>
                  </tr>
                </thead>
                <tbody>
                  {payload.data.events.map((event) => (
                    <tr key={event.id}>
                      <td>
                        <bdi dir="ltr">{event.eventType}</bdi>
                      </td>
                      <td>{event.at ? dateTime(event.at) : <span className="absent">—</span>}</td>
                      <td>
                        <pre className="code-block" dir="ltr">
                          {JSON.stringify(event.payload, null, 2)}
                        </pre>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          </>
        )}
      </ResourceView>
    </section>
  );
}

/**
 * الحقيقة الماليّة للعمليّة بجانب حالة الطلب لا مكانها: ما قبضه المزوّد،
 * وقرار التمويل وسببه، وما رُدّ منه — ومن هنا يُردّ مالٌ لم يُموِّل طلبًا.
 */
function FundingSummary({ tx, onRefunded }: { tx: PaymentTransactionDetail; onRefunded: () => void }) {
  const { can } = useSession();
  const refundable = tx.refundableMinor ?? 0;
  return (
    <>
      <dl className="kv">
        <dt>قبضه المزوّد</dt>
        <dd>
          {tx.amountMinor == null ? (
            <span className="absent">غير مُبلَّغ</span>
          ) : (
            <>
              <bdi dir="ltr">{money(tx.amountMinor)}</bdi> <bdi dir="ltr">{tx.currency ?? ''}</bdi>
            </>
          )}
        </dd>
        <dt>مبلغ الطلب</dt>
        <dd>{tx.orderTotalMinor == null ? '—' : <bdi dir="ltr">{money(tx.orderTotalMinor)}</bdi>}</dd>
        <dt>قرار التمويل</dt>
        <dd>
          {tx.funding ? (
            <b className={tx.funding === 'FUNDED' ? 'chip' : 'chip chip-failed'}>{FUNDING_LABELS[tx.funding]}</b>
          ) : (
            '—'
          )}
          {tx.fundingReason ? (
            <>
              {' '}
              <bdi dir="ltr">{tx.fundingReason}</bdi>
            </>
          ) : null}
        </dd>
        <dt>رُدّ منه</dt>
        <dd>
          <bdi dir="ltr">{money(tx.refundedMinor ?? 0)}</bdi>
          {(tx.refundReservedMinor ?? 0) > 0 ? (
            <>
              {' '}
              (محجوز <bdi dir="ltr">{money(tx.refundReservedMinor ?? 0)}</bdi>)
            </>
          ) : null}
        </dd>
      </dl>
      {refundable > 0 && can('orders.refund') ? (
        <CaptureRefundForm paymentId={tx.id} refundableMinor={refundable} onDone={onRefunded} />
      ) : null}
    </>
  );
}

function CaptureRefundForm({ paymentId, refundableMinor, onDone }: { paymentId: number; refundableMinor: number; onDone: () => void }) {
  const [amount, setAmount] = useState((refundableMinor / 100).toFixed(2));
  const [reason, setReason] = useState('');
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (pending) return;
    const [whole, frac = ''] = amount.trim().split('.');
    if (!/^\d+$/.test(whole ?? '') || !/^\d{0,2}$/.test(frac)) {
      setError('المبلغ بصيغة 0.00');
      return;
    }
    const amountMinor = Number(whole) * 100 + Number(frac.padEnd(2, '0'));
    setPending(true);
    setError(null);
    const body = { amountMinor, reason: reason.trim() };
    // المفتاح من بصمة الجسم: النقرة المكرّرة استردادٌ واحد، والمبلغ المصحَّح طلبٌ آخر.
    const result = await api.post(`/admin/payments/transactions/${paymentId}/refund`, {
      ...body,
      idempotencyKey: idempotencyKey('capture-refund', { paymentId, ...body }),
    });
    setPending(false);
    if (!result.ok) {
      setError(firstError(result));
      return;
    }
    onDone();
  }

  return (
    <form className="refund-form" onSubmit={(e) => void submit(e)}>
      <label className="field" htmlFor="capture-refund-amount">
        <span>ردّ من هذا التحصيل (المتاح <bdi dir="ltr">{money(refundableMinor)}</bdi>)</span>
        <input id="capture-refund-amount" inputMode="decimal" dir="ltr" value={amount} onChange={(e) => setAmount(e.target.value)} />
      </label>
      <label className="field field-wide" htmlFor="capture-refund-reason">
        <span>السبب (إلزاميّ)</span>
        <input id="capture-refund-reason" required minLength={3} value={reason} onChange={(e) => setReason(e.target.value)} />
      </label>
      <button type="submit" className="btn btn-primary" disabled={pending || reason.trim().length < 3}>
        {pending ? 'جارٍ الردّ…' : 'ردّ المبلغ عبر المزوّد'}
      </button>
      {error ? (
        <p className="form-error" role="alert">
          {error}
        </p>
      ) : null}
    </form>
  );
}

function Refunds() {
  const { state, reload } = useResource<RefundLedger>('/admin/payments/refunds');

  return (
    <LedgerState state={state} reload={reload}>
      {(page) => (
        <>
          <div className="cards">
            <article className="card">
              <div className="label">استردادات</div>
              <div className="value">
                <bdi dir="ltr">{count(page.summary.refunds)}</bdi>
              </div>
              <p className="card-reason">عدد العمليّات المرتجعة</p>
            </article>
            <article className="card">
              <div className="label">المبلغ المرتجع</div>
              <div className="value">
                <bdi dir="ltr">{money(page.summary.totalMinor)}</bdi> ر.س
              </div>
              <p className="card-reason">مجموع ما رُدّ</p>
            </article>
            <article className={page.summary.unresolved > 0 ? 'card card-alert' : 'card'}>
              <div className="label">غير محسوم</div>
              <div className="value">
                <bdi dir="ltr">{count(page.summary.unresolved)}</bdi>
              </div>
              <p className="card-reason">
                ينتظر المزوّد أو انقطع ردُّه — <bdi dir="ltr">{money(page.summary.unresolvedMinor)}</bdi> ر.س محجوزة
              </p>
            </article>
            <article className={page.summary.unnotified > 0 ? 'card card-alert' : 'card'}>
              <div className="label">بلا إشعار</div>
              <div className="value">
                <bdi dir="ltr">{count(page.summary.unnotified)}</bdi>
              </div>
              <p className="card-reason">العميل لا يعرف أنّ ماله رُدّ</p>
            </article>
          </div>

          <div className="table-scroll" tabIndex={0} role="region" aria-label="الاستردادات">
            <table className="data-table">
              <caption className="sr-only">الاستردادات</caption>
              <thead>
                <tr>
                  <th scope="col">الطلب</th>
                  <th scope="col">المبلغ</th>
                  <th scope="col">الحالة</th>
                  <th scope="col">السبب</th>
                  <th scope="col">سياسة الوصول</th>
                  <th scope="col">الإشعار</th>
                  <th scope="col">حين</th>
                </tr>
              </thead>
              <tbody>
                {page.data.length === 0 ? (
                  <EmptyRows colSpan={7} message="لا استرداد مسجَّل." />
                ) : (
                  page.data.map((row) => (
                    <tr key={row.id}>
                      <td>
                        <a href={`/orders/${row.orderId}`}>
                          <bdi dir="ltr">{row.orderNumber ?? `#${row.orderId}`}</bdi>
                        </a>
                      </td>
                      <td>
                        <bdi dir="ltr">{money(row.amountMinor)}</bdi> ر.س
                      </td>
                      <td title={row.providerError ?? row.providerRefundId ?? undefined}>
                        {row.status === 'SUCCEEDED' || row.status === 'MANUAL' ? (
                          REFUND_STATUS_LABELS[row.status]
                        ) : (
                          <b className="chip chip-failed">{REFUND_STATUS_LABELS[row.status] ?? row.status}</b>
                        )}
                      </td>
                      <td>{row.reason ?? <span className="absent">—</span>}</td>
                      <td>
                        <bdi dir="ltr">{row.policy ?? '—'}</bdi>
                      </td>
                      <td>
                        {row.notifiedAt ? (
                          dateTime(row.notifiedAt)
                        ) : (
                          <b className="chip chip-failed">لم يصل إشعار</b>
                        )}
                      </td>
                      <td>{row.at ? dateTime(row.at) : <span className="absent">—</span>}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </>
      )}
    </LedgerState>
  );
}

/* ─────────────────────────── المطابقة الماليّة ─────────────────────────── */

interface ReconciliationCounts {
  amountMismatch: number;
  currencyMismatch: number;
  duplicateCapture: number;
  paidWithoutCapture: number;
  orphanCapture: number;
  refundExceedsCapture: number;
  refundUnresolved: number;
  refundLedgerMismatch: number;
  providerRefunds: number;
  paidWithoutFulfillment: number;
  settlementFindings: number;
  providerSuccessLocalFailure: number;
  unauthorizedEntitlements: number;
}

interface SettlementFinding {
  code: string;
  severity: string;
  title: string;
  providerVerdict: string;
  repairAction: string;
  evidence: Record<string, unknown>;
}

interface ReconciliationReport {
  capturedPayments: number;
  knownAmount: number;
  unknownAmount: number;
  clean: boolean;
  counts: ReconciliationCounts;
  amountMismatch: { paymentId: number; orderId: number; orderNumber: string | null; chargeId: string | null; gatewayMinor: number; orderMinor: number; differenceMinor: number }[];
  currencyMismatch: { paymentId: number; orderId: number; currency: string; amountMinor: number }[];
  duplicateCapture: { orderId: number; captures: number; totalMinor: number | null }[];
  paidWithoutCapture: { orderId: number; orderNumber: string | null; totalMinor: number }[];
  orphanCapture: { paymentId: number; orderId: number; orderNumber: string | null; orderStatus: string; reason?: string | null; amountMinor?: number | null; refundedMinor?: number }[];
  refundLedgerMismatch: { orderId: number; orderNumber: string | null; orderStatus: string; ledgerMinor: number; settledMinor: number }[];
  providerRefunds: { refundId: number; amountMinor: number; scope: string; providerRefundId: string | null; orderId: number; orderNumber: string | null }[];
  paidWithoutFulfillment: { orderId: number; orderNumber: string | null; totalMinor: number }[];
  refundExceedsCapture: { orderId: number; orderNumber: string | null; refundedMinor: number; capturedMinor: number }[];
  refundUnresolved: { refundId: number; status: string; amountMinor: number; provider: string | null; providerRefundId: string | null; error: string | null; orderId: number; orderNumber: string | null }[];
  unauthorizedEntitlements: { entitlementId: number; customerId: number; orderId: number; orderNumber: string | null; orderStatus: string }[];
  providerSuccessLocalFailure: { deliveryId: number; provider: string | null; chargeId: string | null; orderNumber: string | null; status: string; lastError: string | null }[];
  settlement: {
    coverage: 'NONE' | 'FULL' | 'PARTIAL';
    settledPayments: number;
    unsettledPayments: number;
    findings: SettlementFinding[];
  };
}

/** تسميةٌ لكلّ نوع فرق، وشرحُ ما يعنيه — الرمز وحده لا يقول للمشغّل ما يفعل. */
const FINDING_LABELS: Record<keyof ReconciliationCounts, { title: string; means: string }> = {
  duplicateCapture: {
    title: 'تحصيلان على طلبٍ واحد',
    means: 'مالٌ أُخذ مرّتين — إمّا خطأُ مزوّد وإمّا تحصيلٌ مكرّر. يُراجَع أوّلًا لأنّه الوحيد الذي يدفع الزبون ثمنه مباشرةً.',
  },
  unauthorizedEntitlements: {
    title: 'استحقاقٌ بلا إذنٍ تجاريّ',
    means: 'ملفٌّ سُلّم عن طلبٍ لم يصل مالُه. الحارس يمنع الجديد، وهذه ما وقع قبله — والملفّ المنزَّل لا يعود.',
  },
  orphanCapture: {
    title: 'تحصيلٌ على طلبٍ غير مدفوع',
    means: 'مالٌ وصل ولم يُسلَّم شيءٌ مقابله.',
  },
  paidWithoutCapture: {
    title: 'طلبٌ مدفوع بلا تحصيل',
    means: 'الاتّجاه المعاكس: سُلّمت بضاعةٌ ولا سجلّ دفعٍ يقابلها.',
  },
  refundExceedsCapture: {
    title: 'استردادٌ يتجاوز المحصَّل',
    means: 'القيد يمنعه مقابل إجماليّ الطلب، وهذا يقيسه مقابل ما أبلغ به المزوّد فعلًا — وهما قد يختلفان.',
  },
  refundLedgerMismatch: {
    title: 'دفتر الاسترداد يخالف الاستردادات',
    means: 'الطلب يقول «رُدّ كذا» والاستردادات المحسومة تقول غيره. الزبون يرى حالةً لم تقع — يُراجَع قبل أيّ ردٍّ جديد.',
  },
  providerRefunds: {
    title: 'استردادٌ من لوحة المزوّد',
    means: 'رُدّ مالٌ من لوحة Tap مباشرةً فسُجِّل هنا. الوصول إلى الملفّات لم يُسحب تلقائيًّا — قرّر مصيره من صفحة الطلب.',
  },
  paidWithoutFulfillment: {
    title: 'طلبٌ مدفوع بلا تنفيذ',
    means: 'وصل المال ولم تُنشأ وحدة تنفيذٍ واحدة: الزبون دفع ولم يستلم شيئًا.',
  },
  refundUnresolved: {
    title: 'استردادٌ غير محسوم',
    means: 'انقطع ردّ المزوّد أو تأخّر تأكيده. المبلغ محجوز فلا يُردّ مرّتين؛ يُحسم بإعادة المحاولة من صفحة الطلب (بالمفتاح نفسه) أو بمراجعة لوحة المزوّد.',
  },
  amountMismatch: {
    title: 'مبلغٌ لا يطابق الطلب',
    means: 'ما قبضته البوّابة غير ما يقوله الطلب.',
  },
  currencyMismatch: {
    title: 'عملةٌ غير عملة المتجر',
    means: 'رقمان متطابقان ومبلغان مختلفان. علاجه مراجعةُ الإعداد لا ردُّ فرق.',
  },
  providerSuccessLocalFailure: {
    title: 'نجحَ عند المزوّد وفشل عندنا',
    means: 'خطّافٌ وصل موقَّعًا ولم تكتمل معالجته: المال تحرّك والأثر لم يقع.',
  },
  settlementFindings: {
    title: 'فروق التسوية',
    means: 'الفرق بين ما حُصّل وما وصل الحساب بعد الرسوم. تفصيلها أسفل الجدول.',
  },
};

/** ترتيب العرض = ترتيب الخطورة على المال، لا ترتيب الحقول في الردّ. */
const FINDING_ORDER: readonly (keyof ReconciliationCounts)[] = [
  'duplicateCapture',
  'unauthorizedEntitlements',
  'orphanCapture',
  'paidWithoutFulfillment',
  'refundLedgerMismatch',
  'paidWithoutCapture',
  'refundExceedsCapture',
  'refundUnresolved',
  'providerRefunds',
  'amountMismatch',
  'currencyMismatch',
  'providerSuccessLocalFailure',
  'settlementFindings',
];

const COVERAGE_LABELS: Record<string, string> = {
  NONE: 'لا مصدر تسوية مربوط',
  PARTIAL: 'جزئيّة',
  FULL: 'كاملة',
};

/**
 * جدولٌ صغير لصفوف فرقٍ واحد.
 *
 * ─── لماذا العدد من `counts` لا من طول القائمة ───
 *
 * الخادم يقتطع كلّ قائمة عند حدٍّ ويبثّ العدد الحقيقيّ منفصلًا، وتعليقه في
 * `Reconciliation` يسمّي العطل الذي أوجب ذلك: قائمةٌ مقتطعة عند مئة تُقرأ
 * «١٠٠» عن ١٢٣ فيبدو الخلل أصغر ممّا هو — وقع فعلًا عند أوّل تشغيلٍ على
 * بيانات حقيقيّة. فالعدد هنا من `counts`، والاقتطاع يُعلَن حين يقع.
 */
function FindingBlock({
  kind,
  total,
  columns,
  rows,
}: {
  kind: keyof ReconciliationCounts;
  total: number;
  columns: readonly string[];
  rows: readonly ReactNode[];
}) {
  const label = FINDING_LABELS[kind];

  /* لا فرق ⇒ لا قسم: صفرٌ معروضٌ في تسعة أقسامٍ يدفن الواحد غير الصفر. */
  if (total === 0) return null;

  return (
    <section className="panel">
      <h2>
        {label.title} — <bdi dir="ltr">{count(total)}</bdi>
      </h2>
      <p className="page-note">{label.means}</p>

      {rows.length < total ? (
        <p className="state-line">
          يُعرض <bdi dir="ltr">{count(rows.length)}</bdi> من <bdi dir="ltr">{count(total)}</bdi> — القائمة
          مقتطعة عند حدّ الخادم، والعدد أعلاه هو الحقيقيّ.
        </p>
      ) : null}

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              {columns.map((column) => (
                <th key={column} scope="col">
                  {column}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>{rows}</tbody>
        </table>
      </div>
    </section>
  );
}

/**
 * المطابقة الماليّة — دفترٌ كان يُحسَب ولا يُقرأ.
 *
 * ـ`GET /admin/payments/reconciliation` مبنيٌّ بالكامل: تسعة أنواع فرقٍ
 * مقيسة، وأعدادٌ حقيقيّة بجانب قوائم مقتطعة، وتسويةٌ تنفصل عن الباقي لأنّها
 * تعتمد مصدرًا خارجيًّا. ولم يكن له مستهلكٌ واحد في اللوحة — فالمشغّل لا
 * يعرف أنّ مالًا أُخذ مرّتين إلّا حين يشتكي صاحبه.
 *
 * والصمت هنا نتيجةٌ لا غياب: `clean` يقولها الخادم صراحةً بعد أن جمع
 * الأعداد، فتُعرض «لا فرق» بوصفها قياسًا جرى لا شاشةً لم تُحمَّل.
 */
function Reconciliation() {
  const { state, reload } = useResource<ReconciliationReport>('/admin/payments/reconciliation');

  return (
    <LedgerState state={state} reload={reload}>
      {(report) => (
        <>
          <div className="cards">
            <article className="card">
              <h3>دفعاتٌ محصَّلة</h3>
              <b>
                <bdi dir="ltr">{count(report.capturedPayments)}</bdi>
              </b>
              {/* مبلغٌ مجهول ليس صفرًا: دفعةٌ محصَّلة لا يُعرف مبلغها لا تدخل أيّ مقارنة. */}
              <p className="page-note">
                منها <bdi dir="ltr">{count(report.unknownAmount)}</bdi> بلا مبلغٍ معروف — لا تدخل المقارنة.
              </p>
            </article>

            <article className={report.clean ? 'card' : 'card card-alert'}>
              <h3>الفروق</h3>
              <b>
                <bdi dir="ltr">{count(Object.values(report.counts).reduce((sum, value) => sum + value, 0))}</bdi>
              </b>
              <p className="page-note">
                {report.clean ? 'لا فرق في الدفتر — قياسٌ جرى، لا شاشةٌ فارغة.' : 'كلّ فرقٍ أدناه يُقرأ ويُعالَج بيده.'}
              </p>
            </article>

            <article className="card">
              <h3>تغطية التسوية</h3>
              <b>{COVERAGE_LABELS[report.settlement.coverage] ?? report.settlement.coverage}</b>
              <p className="page-note">
                <bdi dir="ltr">{count(report.settlement.settledPayments)}</bdi> مُسوّاة ·{' '}
                <bdi dir="ltr">{count(report.settlement.unsettledPayments)}</bdi> بلا تسوية.
              </p>
            </article>
          </div>

          {FINDING_ORDER.map((kind) => {
            const total = report.counts[kind];

            if (kind === 'duplicateCapture') {
              return (
                <FindingBlock
                  key={kind}
                  kind={kind}
                  total={total}
                  columns={['الطلب', 'عدد التحصيلات', 'المجموع']}
                  rows={report.duplicateCapture.map((row) => (
                    <tr key={row.orderId}>
                      <td>
                        <bdi dir="ltr">{row.orderId}</bdi>
                      </td>
                      <td>
                        <bdi dir="ltr">{count(row.captures)}</bdi>
                      </td>
                      <td>{row.totalMinor === null ? <span className="absent">—</span> : money(row.totalMinor)}</td>
                    </tr>
                  ))}
                />
              );
            }

            if (kind === 'unauthorizedEntitlements') {
              return (
                <FindingBlock
                  key={kind}
                  kind={kind}
                  total={total}
                  columns={['الاستحقاق', 'العميل', 'الطلب', 'حالة الطلب']}
                  rows={report.unauthorizedEntitlements.map((row) => (
                    <tr key={row.entitlementId}>
                      <td>
                        <bdi dir="ltr">{row.entitlementId}</bdi>
                      </td>
                      <td>
                        <bdi dir="ltr">{row.customerId}</bdi>
                      </td>
                      <td>{row.orderNumber ?? <bdi dir="ltr">{row.orderId}</bdi>}</td>
                      <td>{row.orderStatus}</td>
                    </tr>
                  ))}
                />
              );
            }

            if (kind === 'orphanCapture') {
              return (
                <FindingBlock
                  key={kind}
                  kind={kind}
                  total={total}
                  columns={['الدفعة', 'الطلب', 'حالة الطلب', 'السبب', 'المبلغ · المردود']}
                  rows={report.orphanCapture.map((row) => (
                    <tr key={row.paymentId}>
                      <td>
                        <a href={`/payments?view=transactions&tx=${row.paymentId}`}>
                          <bdi dir="ltr">{row.paymentId}</bdi>
                        </a>
                      </td>
                      <td>{row.orderNumber ?? <bdi dir="ltr">{row.orderId}</bdi>}</td>
                      <td>{row.orderStatus}</td>
                      <td>
                        <bdi dir="ltr">{row.reason ?? '—'}</bdi>
                      </td>
                      <td>
                        {row.amountMinor == null ? '—' : money(row.amountMinor)} · {money(row.refundedMinor ?? 0)}
                      </td>
                    </tr>
                  ))}
                />
              );
            }

            if (kind === 'paidWithoutCapture') {
              return (
                <FindingBlock
                  key={kind}
                  kind={kind}
                  total={total}
                  columns={['الطلب', 'الإجماليّ']}
                  rows={report.paidWithoutCapture.map((row) => (
                    <tr key={row.orderId}>
                      <td>{row.orderNumber ?? <bdi dir="ltr">{row.orderId}</bdi>}</td>
                      <td>{money(row.totalMinor)}</td>
                    </tr>
                  ))}
                />
              );
            }

            if (kind === 'refundLedgerMismatch') {
              return (
                <FindingBlock
                  key={kind}
                  kind={kind}
                  total={total}
                  columns={['الطلب', 'الحالة', 'دفتر الطلب', 'الاستردادات المحسومة']}
                  rows={report.refundLedgerMismatch.map((row) => (
                    <tr key={row.orderId}>
                      <td>
                        <a href={`/orders/${row.orderId}`}>{row.orderNumber ?? <bdi dir="ltr">{row.orderId}</bdi>}</a>
                      </td>
                      <td>{row.orderStatus}</td>
                      <td>{money(row.ledgerMinor)}</td>
                      <td>{money(row.settledMinor)}</td>
                    </tr>
                  ))}
                />
              );
            }

            if (kind === 'providerRefunds') {
              return (
                <FindingBlock
                  key={kind}
                  kind={kind}
                  total={total}
                  columns={['الطلب', 'المبلغ', 'النطاق', 'مرجع المزوّد']}
                  rows={report.providerRefunds.map((row) => (
                    <tr key={row.refundId}>
                      <td>
                        <a href={`/orders/${row.orderId}`}>{row.orderNumber ?? <bdi dir="ltr">{row.orderId}</bdi>}</a>
                      </td>
                      <td>{money(row.amountMinor)}</td>
                      <td>{row.scope === 'capture' ? 'من تحصيلٍ لم يُموِّل' : 'من مال الطلب'}</td>
                      <td>
                        <bdi dir="ltr">{row.providerRefundId ?? '—'}</bdi>
                      </td>
                    </tr>
                  ))}
                />
              );
            }

            if (kind === 'paidWithoutFulfillment') {
              return (
                <FindingBlock
                  key={kind}
                  kind={kind}
                  total={total}
                  columns={['الطلب', 'الإجماليّ']}
                  rows={report.paidWithoutFulfillment.map((row) => (
                    <tr key={row.orderId}>
                      <td>
                        <a href={`/orders/${row.orderId}`}>{row.orderNumber ?? <bdi dir="ltr">{row.orderId}</bdi>}</a>
                      </td>
                      <td>{money(row.totalMinor)}</td>
                    </tr>
                  ))}
                />
              );
            }

            if (kind === 'refundUnresolved') {
              return (
                <FindingBlock
                  key={kind}
                  kind={kind}
                  total={total}
                  columns={['الطلب', 'المبلغ', 'الحالة', 'المزوّد', 'الخطأ']}
                  rows={report.refundUnresolved.map((row) => (
                    <tr key={row.refundId}>
                      <td>
                        <a href={`/orders/${row.orderId}`}>{row.orderNumber ?? <bdi dir="ltr">{row.orderId}</bdi>}</a>
                      </td>
                      <td>{money(row.amountMinor)}</td>
                      <td>{REFUND_STATUS_LABELS[row.status as keyof typeof REFUND_STATUS_LABELS] ?? row.status}</td>
                      <td>
                        <bdi dir="ltr">{row.providerRefundId ?? row.provider ?? '—'}</bdi>
                      </td>
                      <td>{row.error ?? '—'}</td>
                    </tr>
                  ))}
                />
              );
            }

            if (kind === 'refundExceedsCapture') {
              return (
                <FindingBlock
                  key={kind}
                  kind={kind}
                  total={total}
                  columns={['الطلب', 'المستردّ', 'المحصَّل', 'الفرق']}
                  rows={report.refundExceedsCapture.map((row) => (
                    <tr key={row.orderId}>
                      <td>{row.orderNumber ?? <bdi dir="ltr">{row.orderId}</bdi>}</td>
                      <td>{money(row.refundedMinor)}</td>
                      <td>{money(row.capturedMinor)}</td>
                      <td>{money(row.refundedMinor - row.capturedMinor)}</td>
                    </tr>
                  ))}
                />
              );
            }

            if (kind === 'amountMismatch') {
              return (
                <FindingBlock
                  key={kind}
                  kind={kind}
                  total={total}
                  columns={['الطلب', 'رقم العمليّة', 'عند البوّابة', 'في الطلب', 'الفرق']}
                  rows={report.amountMismatch.map((row) => (
                    <tr key={row.paymentId}>
                      <td>{row.orderNumber ?? <bdi dir="ltr">{row.orderId}</bdi>}</td>
                      <td>
                        {row.chargeId === null ? <span className="absent">—</span> : <bdi dir="ltr">{row.chargeId}</bdi>}
                      </td>
                      <td>{money(row.gatewayMinor)}</td>
                      <td>{money(row.orderMinor)}</td>
                      <td>{money(row.differenceMinor)}</td>
                    </tr>
                  ))}
                />
              );
            }

            if (kind === 'currencyMismatch') {
              return (
                <FindingBlock
                  key={kind}
                  kind={kind}
                  total={total}
                  columns={['الدفعة', 'الطلب', 'العملة', 'المبلغ']}
                  rows={report.currencyMismatch.map((row) => (
                    <tr key={row.paymentId}>
                      <td>
                        <bdi dir="ltr">{row.paymentId}</bdi>
                      </td>
                      <td>
                        <bdi dir="ltr">{row.orderId}</bdi>
                      </td>
                      <td>
                        <bdi dir="ltr">{row.currency}</bdi>
                      </td>
                      {/* بعملتها لا بعملة المتجر: `money` تفترض الريال، وهذا بالضبط ما لا يصحّ هنا. */}
                      <td>
                        <bdi dir="ltr">{count(row.amountMinor)}</bdi> بالوحدة الصغرى
                      </td>
                    </tr>
                  ))}
                />
              );
            }

            if (kind === 'providerSuccessLocalFailure') {
              return (
                <FindingBlock
                  key={kind}
                  kind={kind}
                  total={total}
                  columns={['المزوّد', 'رقم العمليّة', 'الطلب', 'الحالة', 'آخر خطأ']}
                  rows={report.providerSuccessLocalFailure.map((row) => (
                    <tr key={row.deliveryId}>
                      <td>{row.provider ?? <span className="absent">—</span>}</td>
                      <td>
                        {row.chargeId === null ? <span className="absent">—</span> : <bdi dir="ltr">{row.chargeId}</bdi>}
                      </td>
                      <td>{row.orderNumber ?? <span className="absent">—</span>}</td>
                      <td>{row.status}</td>
                      {/* نصّ الخطأ كما سجّله الخادم: هو كلّ ما يدلّ على الإصلاح. */}
                      <td>{row.lastError ?? <span className="absent">—</span>}</td>
                    </tr>
                  ))}
                />
              );
            }

            return (
              <FindingBlock
                key={kind}
                kind={kind}
                total={total}
                columns={['الفرق', 'الخطورة', 'حكم المزوّد', 'الإصلاح المقترح']}
                rows={report.settlement.findings.map((finding) => (
                  <tr key={finding.code + finding.title}>
                    <td>{finding.title}</td>
                    <td>{finding.severity}</td>
                    <td>{finding.providerVerdict}</td>
                    <td>{finding.repairAction}</td>
                  </tr>
                ))}
              />
            );
          })}
        </>
      )}
    </LedgerState>
  );
}
