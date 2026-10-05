/**
 * Checkout route (MIP §3.2: noindex, no public cache, guest-first session).
 * The 3-step flow (بياناتك → الدفع → التأكيد) is client-driven — cart identity
 * lives behind the HttpOnly cookie, payment confirmation is interactive.
 */
import type { Metadata } from 'next';
import { CheckoutFlow } from '../../components/checkout/CheckoutFlow';
import { routes } from '../../lib/routes';
import styles from './page.module.css';

export const metadata: Metadata = {
  title: 'إتمام الطلب',
  robots: { index: false, follow: false },
};

export default function CheckoutPage() {
  return (
    <div className={styles.page}>
      {/*
       * The design reduces the chrome to a bar carrying the logo, the encrypted
       * chip and the back link. The shared SiteHeader is owned by the root
       * layout, so the chip and the link ride on the page's own heading row and
       * the h1 stays — a route that keeps full navigation still needs one.
       */}
      <div className={styles.chrome}>
        <h1 className={styles.title}>إتمام الطلب</h1>

        <span className={styles.secure}>
          <svg
            aria-hidden="true"
            className={styles.secureIcon}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="round"
            strokeLinejoin="round"
            focusable="false"
          >
            <rect x="4.5" y="10.5" width="15" height="10" rx="2.4" />
            <path d="M8.2 10.5V7.6a3.8 3.8 0 0 1 7.6 0v2.9" />
          </svg>
          اتصال مُشفّر
        </span>

        <a className={styles.back} href={routes.cart}>
          {/* Mirrored so the chevron points back towards the cart in RTL. */}
          <svg
            aria-hidden="true"
            className={styles.backIcon}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.4"
            strokeLinecap="round"
            strokeLinejoin="round"
            focusable="false"
          >
            <path d="M14 6l-6 6 6 6" />
          </svg>
          رجوع للسلة
        </a>
      </div>

      <CheckoutFlow />
    </div>
  );
}
