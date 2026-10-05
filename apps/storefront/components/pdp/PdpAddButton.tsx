'use client';
import { useAddToCart } from '../product/useAddToCart';
import { productCardCartIcon, productCardCtaClass } from '@tahdheer/ui/ProductCard';
import { PdpToast } from './PdpToast';
import styles from '../product/CardAddToCart.module.css';

/**
 * «أضف للسلة» on a related card — adds the real product and reports the server's answer.
 *
 * The visible label IS the accessible name (WCAG 2.5.3), with the product title
 * appended visually-hidden so a rail of identical buttons stays distinguishable.
 * Busy is `aria-disabled` (focus stays put; the handler refuses a second POST);
 * `disabled` is reserved for a product with no active offer.
 */
export function PdpAddButton({
  productId,
  title,
  className = productCardCtaClass,
  disabled = false,
}: {
  productId: number;
  title: string;
  className?: string;
  disabled?: boolean;
}) {
  const cart = useAddToCart(productId);
  const busy = cart.status === 'busy';
  return (
    <>
      <button
        type="button"
        className={className}
        onClick={() => {
          if (!busy) cart.add();
        }}
        disabled={disabled}
        aria-disabled={busy || undefined}
        aria-busy={busy || undefined}
        data-state={cart.status}
      >
        {productCardCartIcon}
        {cart.status === 'added' ? 'أُضيف ✓' : 'أضف للسلة'}
        <span className={styles.srOnly}> «{title}»</span>
      </button>
      <PdpToast message={cart.message} tone={cart.status === 'error' ? 'error' : 'success'} />
    </>
  );
}
