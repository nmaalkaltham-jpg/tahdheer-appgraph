/**
 * Cart route (MIP §3.2: noindex, no public cache). Cart contents live behind
 * the HttpOnly «tahdheer_cart» cookie ⇒ the page shell is server-rendered and
 * the cart itself is client-driven via lib/commerce-api.
 */
import type { Metadata } from 'next';
import { CartView } from '../../components/cart/CartView';
import { Icon } from '../../components/home/icons';
import { routes } from '../../lib/routes';
import styles from './page.module.css';

export const metadata: Metadata = {
  title: 'سلة التسوق',
  robots: { index: false, follow: false },
};

export default function CartPage() {
  return (
    <div className={styles.page}>
      <div className={styles.head}>
        <div className={styles.headText}>
          <h1 className={styles.title}>سلة التسوق</h1>
          {/*
           * The design's line reads «يمكنك تعديل الكمية أو حذف أي منتج قبل
           * الدفع». Digital lines are quantity-locked (MIP §5.3), so the
           * quantity clause is dropped — promising a control the page does not
           * offer is a false affordance. The line count itself is not stated
           * here: it lives behind the HttpOnly cart cookie and is unknown to
           * this server component.
           */}
          <p className={styles.status}>يمكنك حذف أي منتج قبل الدفع</p>
        </div>
        <a className={styles.continue} href={routes.shop}>
          <Icon name="chev" size={15} strokeWidth={2.2} className={styles.continueChev} />
          متابعة التسوق
        </a>
      </div>
      <CartView />
    </div>
  );
}
