'use client';
// UNIT: PAGE-ORDERS-ID
// FILE: page.tsx
// ROUTE: /orders/[id]
// STYLE: none
// PARENT: none
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-FORMAT, LIB-IDEMPOTENCY, NOTES, RESOURCE
// API: POST /admin/orders
// MAP: docs/PROJECT_MAP.md

/**
 * تفاصيل الطلب + حوار الاسترداد. الاسترداد ينفَّذ على الخادم عبر
 * RefundService (سطر الاسترداد + تحويل حالة الطلب + تطبيق سياسة السحب +
 * حدث refund.completed) — الواجهة لا تحسب ولا تفترض شيئًا منه.
 */

import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useState } from 'react';
import { useSession } from '../../../../components/admin-session';
import { PageHeader, ResourceView, useResource, type ResourceState } from '../../../../components/resource';
import { Notes } from '../../../../components/notes';
import { api, firstError, type OrderDetail } from '../../../../lib/admin-api';
import { idempotencyKey } from '../../../../lib/idempotency';
import { REFUND_STATUS_LABELS } from '../../../../lib/admin-api-types';
import {
  count,
  dateTime,
  money,
  ENTITLEMENT_STATUS_LABELS,
  ORDER_STATUS_LABELS,
  REVOCATION_POLICY_LABELS,
  riyalsToMinor,
} from '../../../../lib/format';

/**
 * الخطأ يُقرأ برمزه لا بنصّ الخادم وحده.
 *
 * `Order::findOrFail` يردّ 404 و`AdminAuthorize:orders.read` يردّ 403، وكلاهما
 * يصل هنا رسالةً عامّة تنتهي بزرّ «إعادة المحاولة». وزرٌّ على 404 وعدٌ كاذب:
 * الطلب غير موجود، وإعادة الطلب لا تُنشئه — فيُنزع الزرّ ويبقى الطريق الوحيد
 * المفيد، العودة إلى القائمة. أمّا 403 فتُسمّى فيها الصلاحية الناقصة باسمها
 * حتّى لا يُخلط منعُ الدور بعطبٍ في الشاشة.
 */
function OrderState({ state, reload, children }: {
  state: ResourceState<{ data: OrderDetail }>;
  reload: () => void;
  children: (order: OrderDetail) => React.ReactNode;
}) {
  if (state.status === 'error' && state.code === 404) {
    return (
      <div className="state-error" role="alert">
        <p>لا طلب بهذا المعرّف. قد يكون حُذف، أو يكون الرابط قديمًا.</p>
        <Link className="btn btn-ghost" href="/orders">
          العودة إلى القائمة
        </Link>
      </div>
    );
  }

  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>لا تملك صلاحية <bdi dir="ltr">orders.read</bdi> لقراءة تفاصيل الطلبات.</p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {(payload) => children(payload.data)}
    </ResourceView>
  );
}

export default function OrderDetailPage() {
  const params = useParams<{ id: string }>();
  const { can } = useSession();
  const { state, reload } = useResource<{ data: OrderDetail }>(`/admin/orders/${params.id}`);

  return (
    <section>
      <PageHeader
        trail="الطلبات › تفاصيل"
        title="تفاصيل الطلب"
        actions={
          <Link className="btn btn-ghost" href="/orders">
            رجوع للقائمة
          </Link>
        }
      />
      <OrderState state={state} reload={reload}>
        {(order) => (
          <>
            <OrderDetailView order={order} canRefund={can('orders.refund')} onRefunded={reload} />
            {/* أسفل الطلب لا أعلاه: القرار يُقرأ بعد وقائعه. */}
            <Notes entityType="order" entityId={order.id} />
          </>
        )}
      </OrderState>
    </section>
  );
}

function OrderDetailView({
  order,
  canRefund,
  onRefunded,
}: {
  order: OrderDetail;
  canRefund: boolean;
  onRefunded: () => void;
}) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <div className="detail-head">
        <bdi dir="ltr" className="mono">
          {order.number}
        </bdi>
        <span className={`chip chip-${order.status.toLowerCase()}`}>
          {ORDER_STATUS_LABELS[order.status] ?? order.status}
        </span>
      </div>

      <section className="panel">
        <h2>الملخّص</h2>
        {/* الطوق منطقةُ بلوغٍ بلوحة المفاتيح: كروم لا يبئّر منطقة تمرير من تلقائه (globals.css). */}
        <div className="table-scroll" tabIndex={0} role="region" aria-label="ملخّص الطلب">
          <table className="data-table">
            <tbody>
              <tr>
                <th scope="row">البريد</th>
                <td>
                  <bdi dir="ltr">{order.email}</bdi>
                </td>
              </tr>
              <tr>
                <th scope="row">الإجمالي (السعر نهائي)</th>
                <td>
                  <bdi dir="ltr">{money(order.totalMinor)}</bdi> ر.س
                </td>
              </tr>
              <tr>
                <th scope="row">الضريبة المستخرَجة</th>
                <td>
                  <bdi dir="ltr">{money(order.vatMinor)}</bdi> ر.س
                </td>
              </tr>
              <tr>
                <th scope="row">المسترد حتى الآن</th>
                <td>
                  <bdi dir="ltr">{money(order.refundedMinor)}</bdi> ر.س
                </td>
              </tr>
              {order.refundReservedMinor > 0 ? (
                <tr>
                  <th scope="row">محجوز بانتظار المزوّد</th>
                  <td>
                    <bdi dir="ltr">{money(order.refundReservedMinor)}</bdi> ر.س
                  </td>
                </tr>
              ) : null}
              <tr>
                <th scope="row">المتبقي القابل للاسترداد</th>
                <td>
                  <bdi dir="ltr">{money(order.refundableMinor)}</bdi> ر.س
                </td>
              </tr>
              <tr>
                <th scope="row">تاريخ الدفع</th>
                <td>
                  <bdi>{dateTime(order.paidAt)}</bdi>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section className="panel">
        <h2>البنود</h2>
        <div className="table-scroll" tabIndex={0} role="region" aria-label="بنود الطلب">
          <table className="data-table">
            <thead>
              <tr>
                <th scope="col">المنتج</th>
                <th scope="col">السعر</th>
              </tr>
            </thead>
            <tbody>
              {order.items.length === 0 ? (
                <tr>
                  <td className="empty-cell" colSpan={2}>
                    لا بنود مسجَّلة على هذا الطلب.
                  </td>
                </tr>
              ) : (
                order.items.map((item) => (
                  <tr key={item.id}>
                    <td>{item.title}</td>
                    <td>
                      <bdi dir="ltr">{money(item.priceMinor)}</bdi> ر.س
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>

      <section className="panel">
        <h2>صلاحيات الوصول</h2>
        <div className="table-scroll" tabIndex={0} role="region" aria-label="صلاحيات الوصول على الطلب">
          <table className="data-table">
            <thead>
              <tr>
                <th scope="col">المعرّف</th>
                <th scope="col">الحالة</th>
                <th scope="col">مرات التنزيل</th>
              </tr>
            </thead>
            <tbody>
              {order.entitlements.length === 0 ? (
                <tr>
                  <td className="empty-cell" colSpan={3}>
                    لا صلاحيات مرتبطة بهذا الطلب.
                  </td>
                </tr>
              ) : (
                order.entitlements.map((entitlement) => (
                  <tr key={entitlement.id}>
                    <td>
                      <bdi dir="ltr">{entitlement.id}</bdi>
                    </td>
                    <td>{ENTITLEMENT_STATUS_LABELS[entitlement.status] ?? entitlement.status}</td>
                    <td>
                      <bdi dir="ltr">{count(entitlement.downloadCount)}</bdi>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>

      <section className="panel">
        <h2>الاستردادات</h2>
        {order.refunds.length === 0 ? (
          <p className="absent">لا استردادات على هذا الطلب.</p>
        ) : (
          <div className="table-scroll" tabIndex={0} role="region" aria-label="استردادات الطلب">
            <table className="data-table">
              <thead>
                <tr>
                  <th scope="col">المبلغ</th>
                  <th scope="col">الحالة</th>
                  <th scope="col">السياسة</th>
                  <th scope="col">السبب</th>
                  <th scope="col">التاريخ</th>
                </tr>
              </thead>
              <tbody>
                {order.refunds.map((refund) => (
                  <tr key={refund.id}>
                    <td>
                      <bdi dir="ltr">{money(refund.amountMinor)}</bdi> ر.س
                    </td>
                    <td title={refund.providerError ?? refund.providerRefundId ?? undefined}>
                      {REFUND_STATUS_LABELS[refund.status] ?? refund.status}
                    </td>
                    <td>{refund.policy ? (REVOCATION_POLICY_LABELS[refund.policy] ?? refund.policy) : '—'}</td>
                    <td>{refund.reason ?? '—'}</td>
                    <td>
                      <bdi>{dateTime(refund.createdAt)}</bdi>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {canRefund ? (
          order.refundableMinor > 0 ? (
            <button type="button" className="btn btn-primary" onClick={() => setOpen((value) => !value)}>
              {open ? 'إلغاء' : 'استرداد مبلغ'}
            </button>
          ) : (
            <p className="absent">لا يوجد مبلغ متبقٍ قابل للاسترداد.</p>
          )
        ) : (
          <p className="absent">دورك لا يملك صلاحية الاسترداد.</p>
        )}

        {open && canRefund ? (
          <RefundForm
            orderId={order.id}
            refundableMinor={order.refundableMinor}
            onDone={() => {
              setOpen(false);
              onRefunded();
            }}
          />
        ) : null}
      </section>
    </>
  );
}

function RefundForm({
  orderId,
  refundableMinor,
  onDone,
}: {
  orderId: number;
  refundableMinor: number;
  onDone: () => void;
}) {
  const [amount, setAmount] = useState((refundableMinor / 100).toFixed(2));
  const [reason, setReason] = useState('');
  const [policy, setPolicy] = useState('grace_3d');
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  async function submit() {
    const amountMinor = riyalsToMinor(amount);
    if (amountMinor === null || amountMinor <= 0) {
      setError('أدخل مبلغًا بالريالات أكبر من صفر.');
      return;
    }

    setPending(true);
    setError(null);

    /*
     * ─── مفتاح خمولٍ على أثرٍ يُخرج مالًا ───
     *
     * الجسم كان يُرسَل بلا مفتاح، و`RefundService` لا يُبطل التكرار إلّا
     * حين يجده: يبحث عن استردادٍ سابق بالمفتاح نفسه **تحت قفل الصفّ** ويردّه
     * بدل أن يُنشئ ثانيًا. فبلا مفتاحٍ لا شيء يمنع استردادين.
     *
     * والحال التي تقع فعلًا ليست نقرةً مزدوجة — الزرّ معطَّل أثناء التنفيذ —
     * بل ردٌّ يضيع في الشبكة أو مهلةٌ عند الوكيل: يرى المشغّل فشلًا فيعيد،
     * وقد نجح الأوّل. فيخرج المال مرّتين ولا خطأ يُرفع (قاعدة ٣).
     *
     * والمفتاح من البدائيّة القائمة: هويّة التبويب + بصمة الجسم. فالإعادة
     * نفسها مفتاحٌ واحد، ومبلغٌ مُصحَّح مفتاحٌ جديد — وهو استردادٌ آخر بحقّ.
     */
    const body = {
      amountMinor,
      reason: reason.trim() === '' ? null : reason.trim(),
      policy,
    };

    const result = await api.post(`/admin/orders/${orderId}/refund`, {
      ...body,
      idempotencyKey: idempotencyKey('refund', { orderId, ...body }),
    });
    setPending(false);

    if (!result.ok) {
      setError(firstError(result));
      return;
    }
    onDone();
  }

  return (
    <form
      className="refund-form"
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
      }}
    >
      <h3>استرداد</h3>

      {error ? (
        <p className="form-error" role="alert">
          {error}
        </p>
      ) : null}

      <label className="field" htmlFor="refund-amount">
        <span>المبلغ (ر.س)</span>
        <input
          id="refund-amount"
          dir="ltr"
          inputMode="decimal"
          value={amount}
          onChange={(event) => setAmount(event.target.value)}
        />
      </label>

      <label className="field" htmlFor="refund-policy">
        <span>سياسة سحب الوصول</span>
        <select id="refund-policy" value={policy} onChange={(event) => setPolicy(event.target.value)}>
          {Object.entries(REVOCATION_POLICY_LABELS).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </select>
      </label>

      <label className="field field-wide" htmlFor="refund-reason">
        <span>السبب (يُحفظ في سجل التدقيق)</span>
        <input id="refund-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
      </label>

      <button type="submit" className="btn btn-primary" disabled={pending}>
        {pending ? 'جارٍ التنفيذ…' : 'تأكيد الاسترداد'}
      </button>
    </form>
  );
}
