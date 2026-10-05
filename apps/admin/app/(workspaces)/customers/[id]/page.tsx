'use client';
// UNIT: PAGE-CUSTOMERS-ID
// FILE: page.tsx
// ROUTE: /customers/[id]
// STYLE: none
// PARENT: none
// USES: ENTITLEMENT-REVOKE, LIB-FORMAT, NOTES, RESOURCE
// API: /admin/customers
// MAP: docs/PROJECT_MAP.md

/**
 * تفصيل العميل — نقطة نهاية كاملة كانت بلا باب.
 *
 * `GET /admin/customers/{id}` مبنيّة ومحروسة ومُختبَرة منذ البداية: تعيد
 * الطلبات وصلاحيات الوصول بحالة كلٍّ منها وعدّاد تنزيلاته وسقفه وسبب سحبه.
 * ولم يكن في اللوحة رابطٌ واحد إليها — القائمة تعرض الأسماء والأعداد، ثمّ
 * تقف. فمن أراد أن يعرف **أيّ** الملفّات نزّلها عميل، أو لماذا سُحبت صلاحيته،
 * لم يكن أمامه إلا قاعدة البيانات.
 *
 * ولذلك هذه الشاشة ليست ميزة جديدة بقدر ما هي فتحُ ما كان مغلقًا.
 */

import Link from 'next/link';
import { use } from 'react';
import { EmptyRows, PageHeader, ResourceView, useResource, type ResourceState } from '../../../../components/resource';
import { EntitlementRevoke } from '../../../../components/entitlement-revoke';
import { Notes } from '../../../../components/notes';
import { count, dateTime, money, ENTITLEMENT_STATUS_LABELS, ORDER_STATUS_LABELS } from '../../../../lib/format';

interface CustomerOrder {
  id: number;
  number: string;
  status: string;
  totalMinor: number;
  createdAt: string | null;
}

interface CustomerEntitlement {
  id: number;
  productId: number;
  productTitle: string | null;
  orderId: number | null;
  status: string;
  downloadCount: number;
  downloadLimit: number | null;
  grantedAt: string | null;
  expiresAt: string | null;
  revokedAt: string | null;
  revocationReason: string | null;
}

interface CustomerDetail {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  locale: string | null;
  marketingConsent: boolean;
  createdAt: string | null;
  ordersCount: number;
  entitlementsCount: number;
  activeEntitlementsCount: number;
  orders: CustomerOrder[];
  entitlements: CustomerEntitlement[];
}

/**
 * الخطأ يُقرأ برمزه لا بنصّ الخادم وحده.
 *
 * `Customer::findOrFail` يردّ 404 و`AdminAuthorize:customers.read` يردّ 403،
 * وكلاهما يصل هنا رسالةً عامّة تنتهي بزرّ «إعادة المحاولة». والمشغّل الذي فتح
 * رابطًا قديمًا لا ينقصه زرّ: العميل محذوف أو المعرّف خاطئ، وإعادة الطلب
 * ألف مرّة تعيد 404 نفسها. فالزرّ يُنزع ويوضع مكانه الطريق الوحيد المفيد —
 * العودة إلى القائمة. أمّا 403 فيُسمّى فيها النقص باسمه حتّى يعرف من يطلب
 * الصلاحية ماذا يطلب.
 */
function CustomerState({ state, reload, children }: {
  state: ResourceState<{ data: CustomerDetail }>;
  reload: () => void;
  children: (customer: CustomerDetail) => React.ReactNode;
}) {
  if (state.status === 'error' && state.code === 404) {
    return (
      <div className="state-error" role="alert">
        <p>لا عميل بهذا المعرّف. قد يكون حُذف، أو يكون الرابط قديمًا.</p>
        <Link className="btn btn-ghost" href="/customers">
          العودة إلى القائمة
        </Link>
      </div>
    );
  }

  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>لا تملك صلاحية <bdi dir="ltr">customers.read</bdi> لقراءة بطاقات العملاء.</p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {(payload) => children(payload.data)}
    </ResourceView>
  );
}

export default function CustomerDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { state, reload } = useResource<{ data: CustomerDetail }>(`/admin/customers/${id}`);

  return (
    <section>
      <PageHeader trail="المبيعات والعملاء › العملاء" title="بطاقة العميل" />

      <p className="page-note">
        <Link href="/customers">العودة إلى قائمة العملاء</Link>
      </p>

      <CustomerState state={state} reload={reload}>
        {(customer) => (
            <>
              <section className="panel">
                <h2>{customer.name}</h2>
                {/* الطوق منطقةُ بلوغٍ بلوحة المفاتيح: كروم لا يبئّر منطقة تمرير من تلقائه (globals.css). */}
                <div className="table-scroll" tabIndex={0} role="region" aria-label="بيانات العميل">
                  <table className="data-table">
                    <tbody>
                      <tr>
                        <th scope="row">البريد</th>
                        <td>
                          <bdi dir="ltr">{customer.email}</bdi>
                        </td>
                      </tr>
                      <tr>
                        <th scope="row">الجوال</th>
                        <td>{customer.phone === null ? 'غير مسجَّل' : <bdi dir="ltr">{customer.phone}</bdi>}</td>
                      </tr>
                      <tr>
                        <th scope="row">موافقة التسويق</th>
                        <td>{customer.marketingConsent ? 'ممنوحة' : 'غير ممنوحة'}</td>
                      </tr>
                      <tr>
                        <th scope="row">أُنشئ</th>
                        <td>{dateTime(customer.createdAt)}</td>
                      </tr>
                      <tr>
                        <th scope="row">الطلبات · الصلاحيات · الفعّالة</th>
                        <td>
                          <bdi dir="ltr">{count(customer.ordersCount)}</bdi> ·{' '}
                          <bdi dir="ltr">{count(customer.entitlementsCount)}</bdi> ·{' '}
                          <bdi dir="ltr">{count(customer.activeEntitlementsCount)}</bdi>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </section>

              <section className="panel">
                <h2>الطلبات</h2>
                <div className="table-scroll" tabIndex={0} role="region" aria-label="طلبات العميل">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th scope="col">الرقم</th>
                        <th scope="col">الحالة</th>
                        <th scope="col">الإجمالي</th>
                        <th scope="col">التاريخ</th>
                      </tr>
                    </thead>
                    <tbody>
                      {customer.orders.length === 0 ? (
                        <EmptyRows colSpan={4} message="لا طلبات لهذا العميل." />
                      ) : (
                        customer.orders.map((order) => (
                          <tr key={order.id}>
                            <td>
                              <Link href={`/orders/${order.id}`}>
                                <bdi dir="ltr">{order.number}</bdi>
                              </Link>
                            </td>
                            <td>{ORDER_STATUS_LABELS[order.status] ?? order.status}</td>
                            <td>{money(order.totalMinor)}</td>
                            <td>{dateTime(order.createdAt)}</td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </section>

              <section className="panel">
                <h2>صلاحيات الوصول</h2>
                {/*
                  * عدّاد التنزيل وسقفه في عمود واحد: الرقمان بلا بعضهما لا
                  * يقولان شيئًا — «٣ تنزيلات» تطمئن أو تُقلق بحسب السقف.
                  */}
                <div className="table-scroll" tabIndex={0} role="region" aria-label="صلاحيات وصول العميل">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th scope="col">المنتج</th>
                        <th scope="col">الحالة</th>
                        <th scope="col">التنزيلات</th>
                        <th scope="col">مُنحت</th>
                        <th scope="col">ملاحظة</th>
                        <th scope="col">إجراء</th>
                      </tr>
                    </thead>
                    <tbody>
                      {customer.entitlements.length === 0 ? (
                        <EmptyRows colSpan={6} message="لا صلاحيات وصول." />
                      ) : (
                        customer.entitlements.map((ent) => (
                          <tr key={ent.id}>
                            <td>
                              {ent.productTitle === null ? (
                                `منتج #${ent.productId}`
                              ) : (
                                <Link href={`/studio/catalog/${ent.productId}`}>{ent.productTitle}</Link>
                              )}
                            </td>
                            <td>{ENTITLEMENT_STATUS_LABELS[ent.status] ?? ent.status}</td>
                            <td>
                              <bdi dir="ltr">
                                {count(ent.downloadCount)}
                                {ent.downloadLimit === null ? ' / بلا سقف' : ` / ${count(ent.downloadLimit)}`}
                              </bdi>
                            </td>
                            <td>{dateTime(ent.grantedAt)}</td>
                            <td>
                              {ent.revokedAt !== null
                                ? `سُحبت ${dateTime(ent.revokedAt)}${ent.revocationReason === null ? '' : ` — ${ent.revocationReason}`}`
                                : ent.expiresAt !== null
                                  ? `تنتهي ${dateTime(ent.expiresAt)}`
                                  : '—'}
                            </td>
                            <td>
                              {ent.revokedAt === null ? (
                                <EntitlementRevoke
                                  entitlementId={ent.id}
                                  productLabel={ent.productTitle ?? `منتج #${ent.productId}`}
                                  onDone={reload}
                                />
                              ) : (
                                <span className="absent">—</span>
                              )}
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </section>

              <Notes entityType="customer" entityId={customer.id} />
            </>
        )}
      </CustomerState>
    </section>
  );
}
