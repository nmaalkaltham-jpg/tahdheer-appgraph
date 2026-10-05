'use client';
/**
 * Bundle purchase control.
 *
 * It reuses the ONE add-to-cart action (`useAddToCart`) rather than
 * re-implementing the request, so a bundle line reports exactly the state a
 * product line does — including the honest failure when the API is
 * unreachable. It does NOT reuse `ProductCta`, whose stylesheet hides it below
 * 768px because the PDP hands over to a sticky purchase bar there; the bundle
 * page has no sticky bar, so that control would vanish on mobile.
 *
 * This component only ever renders when the bundle DTO carries a
 * `cartProductId`. With no purchasable id the page shows a visibly disabled
 * control with its reason instead — never a button that pretends to work.
 */
import { useAddToCart } from '../../../components/product/useAddToCart';
import styles from './BundleAddToCart.module.css';

export interface BundleAddToCartProps {
  productId: number | string;
}

export function BundleAddToCart({ productId }: BundleAddToCartProps) {
  const cart = useAddToCart(productId);
  const busy = cart.status === 'busy';

  return (
    <div className={styles.wrap}>
      <button type="button" className={styles.cta} onClick={cart.add} disabled={busy}>
        <svg
          aria-hidden="true"
          className={styles.icon}
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <circle cx="9" cy="20" r="1.4" />
          <circle cx="17.5" cy="20" r="1.4" />
          <path d="M2.5 3.5h2.6l2.3 11.2h11l1.9-8H6.4" />
        </svg>
        أضف الحزمة إلى السلة
      </button>

      {/* The server's own message, or the shared connection-failure line. */}
      <p
        role="status"
        aria-live="polite"
        className={`${styles.status} ${cart.status === 'error' ? styles.statusError : ''}`}
      >
        {cart.message ?? ''}
      </p>
    </div>
  );
}
