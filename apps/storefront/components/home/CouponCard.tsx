'use client';
/**
 * CouponCard — the coupon half of the TahHomeBody promo pair: dashed code
 * plate plus a copy button that flips to «نُسخ ✓».
 *
 * HONESTY DOCTRINE: the code and its description arrive as props from
 * /api/v1/home/promo. Nothing here invents a coupon, and the card is never
 * rendered without one.
 *
 * The button is never a dead affordance: when the Clipboard API is missing or
 * refused (insecure context, permission denied) it selects the code instead,
 * so a manual copy still works.
 */
import { useRef, useState } from 'react';
import { SPEEDS } from '@tahdheer/design-system/motion';
import { Icon } from './icons';
import styles from './sections.module.css';

export interface CouponCardProps {
  code: string;
  description?: string;
}

export function CouponCard({ code, description }: CouponCardProps) {
  const [copied, setCopied] = useState(false);
  const codeRef = useRef<HTMLElement>(null);

  const selectCode = () => {
    const node = codeRef.current;
    const selection = typeof window === 'undefined' ? null : window.getSelection();
    if (!node || !selection) return;
    const range = document.createRange();
    range.selectNodeContents(node);
    selection.removeAllRanges();
    selection.addRange(range);
  };

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
      window.setTimeout(() => setCopied(false), SPEEDS.verySlow);
    } catch {
      // No clipboard access — hand the user a selection they can copy manually.
      selectCode();
    }
  };

  return (
    <div className={styles.coupon}>
      <span className={styles.couponIcon}>
        <Icon name="tag" size={23} strokeWidth={1.9} />
      </span>
      <h3 className={styles.couponTitle}>قسيمة خصم</h3>
      {description ? <p className={styles.couponDesc}>{description}</p> : null}
      <div className={styles.couponPlate}>
        <code className={styles.couponCode} ref={codeRef}>
          <bdi dir="ltr">{code}</bdi>
        </code>
        <button type="button" className={styles.couponCopy} onClick={handleCopy}>
          {copied ? 'نُسخ ✓' : 'نسخ'}
        </button>
      </div>
      <span role="status" aria-live="polite" className={styles.srOnly}>
        {copied ? 'نُسخ رمز القسيمة' : ''}
      </span>
    </div>
  );
}
