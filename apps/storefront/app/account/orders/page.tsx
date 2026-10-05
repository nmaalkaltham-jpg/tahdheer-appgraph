/**
 * /account/orders — the design's «أحدث الطلبات» card.
 *
 * With no `?token=` there is nothing to list: orders belong to a customer, and
 * this backend has no customer session. With a token the row is REAL — number,
 * status, date, lines and totals all come from `/api/v1/orders/{token}` — and
 * the full view stays where it already lives, at `/orders/{token}`, rather than
 * being rebuilt here.
 */
import type { Metadata } from 'next';
import { getOrder } from '../../../lib/commerce-api';
import { Money, TotalsRows } from '../../../components/cart/TotalsRows';
import { ConnectionError } from '../../../components/search/ConnectionError';
import { accountRoutes } from '../nav';
import { EmptyCard, NoSessionPanel, OrderContextLinks, StatusChip, UnknownToken, orderDate } from '../parts';
import { EMPTY_ART } from '../../../lib/art';
import { routes } from '../../../lib/routes';
import shared from '../shared.module.css';
import styles from './page.module.css';

export const metadata: Metadata = {
  title: 'طلباتي',
  robots: { index: false, follow: true },
};

type Props = { searchParams: Promise<{ token?: string }> };

export default async function AccountOrdersPage({ searchParams }: Props) {
  const { token } = await searchParams;

  return (
    <>
      <header className={shared.section}>
        <h1 className={shared.pageTitle}>طلباتي</h1>
        <p className={shared.lead}>
          كل طلب يُفتح برمزه الموقَّع من رسالة التأكيد. لا يوجد سجلّ طلبات مرتبط بحساب، لأن تسجيل
          دخول العملاء غير مربوط بعد.
        </p>
      </header>

      {token ? (
        <OrderPanel token={token} />
      ) : (
        <>
          <NoSessionPanel body="ألصق رابط طلبك ليظهر هنا بحالته وبنوده وإجماليه." />
          <section className={shared.section} aria-labelledby="acc-orders-empty">
            <h2 className={shared.sectionTitle} id="acc-orders-empty">
              الطلبات
            </h2>
            <EmptyCard
              art={EMPTY_ART.orders}
              title="لا طلبات معروضة الآن."
              body="افتح طلبًا سابقًا برابطه أعلاه، أو ابدأ طلبك الأوّل من المتجر."
              action={{ href: routes.shop, label: 'تصفّح المتجر' }}
            />
          </section>
        </>
      )}
    </>
  );
}

async function OrderPanel({ token }: { token: string }) {
  const res = await getOrder(token);

  if (res.status === 0) {
    return <ConnectionError href={`${accountRoutes.orders}?token=${encodeURIComponent(token)}`} />;
  }

  const order = res.data;
  if (!order) return <UnknownToken />;

  const paid = orderDate(order.paidAt);

  return (
    <section className={shared.section} aria-labelledby="acc-order">
      <div className={shared.sectionHead}>
        <h2 className={shared.sectionTitle} id="acc-order">
          الطلب
        </h2>
        <OrderContextLinks token={token} current={accountRoutes.orders} />
      </div>

      <div className={shared.orderRow}>
        <div className={shared.orderHead}>
          <bdi className={shared.orderId} dir="ltr">
            {order.orderNumber}
          </bdi>
          <StatusChip status={order.status} />
          {paid ? (
            <time className={shared.orderDate} dateTime={paid.iso}>
              {paid.text}
            </time>
          ) : null}
        </div>

        <ul className={styles.lines}>
          {order.items.map((item) => (
            <li key={item.title} className={styles.line}>
              <span>{item.title}</span>
              <Money minor={item.priceMinor} />
            </li>
          ))}
        </ul>

        <div className={styles.totals}>
          <TotalsRows totals={order.totals} />
        </div>

        <div className={shared.orderFoot}>
          <a className={shared.orderLink} href={`/orders/${encodeURIComponent(token)}`}>
            تفاصيل الطلب وملفاته
          </a>
        </div>
      </div>

      {/*
        كان هنا «أرسلنا تفاصيل الطلب إلى …» — ولا مُرسِل بريد في الخلفية، وصفحة
        الطلب نفسها تقول «لا تُرسَل نسخةٌ منه على البريد». يبقى ما هو صحيح.
      */}
      <p className={shared.meta}>
        بريد الطلب: <bdi dir="ltr">{order.emailMasked}</bdi>
      </p>
    </section>
  );
}
