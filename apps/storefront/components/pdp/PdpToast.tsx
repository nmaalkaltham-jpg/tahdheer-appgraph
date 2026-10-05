'use client';
import { useEffect, useState } from 'react';
import { routes } from '../../lib/routes';
import { CheckIcon } from './icons';
import styles from './pdp.module.css';

const DISMISS_MS = 2800;

/**
 * Toast — the server's own message and «عرض السلة», in the state triads
 * (success teal · error danger) with a check / alert disc. Errors stay until the next action (no
 * auto-dismiss, no cart link). `mobile` lifts it above the sticky bar
 * (the mobile design's `bottom: 118px`).
 */
export function PdpToast({
  message,
  tone = 'success',
  mobile = false,
  cartLink = true,
}: {
  message: string | null;
  tone?: 'success' | 'error';
  mobile?: boolean;
  /** «عرض السلة» — لإشعارات السلة وحدها؛ إشعار نسخ الرابط بلا وجهة. */
  cartLink?: boolean;
}) {
  const [seen, setSeen] = useState<string | null>(null);
  const [hidden, setHidden] = useState(false);

  if (message !== seen) {
    setSeen(message);
    setHidden(false);
  }

  useEffect(() => {
    if (message === null || tone === 'error') return;
    const timer = window.setTimeout(() => setHidden(true), DISMISS_MS);
    return () => window.clearTimeout(timer);
  }, [message, tone]);

  const open = message !== null && !hidden;

  return (
    <div className={mobile ? styles.toastRegionMobile : styles.toastRegion} role="status" aria-live="polite">
      {open ? (
        <div className={styles.toast} data-tone={tone}>
          <span className={styles.toastIcon} aria-hidden="true">
            {tone === 'success' ? (
              <CheckIcon strokeWidth={2.8} />
            ) : (
              <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                <path d="M7.1 3.5h1.8l-.3 6H7.4l-.3-6Zm.9 7.6a1.1 1.1 0 1 1 0 2.2 1.1 1.1 0 0 1 0-2.2Z" />
              </svg>
            )}
          </span>
          <span>{message}</span>
          {tone === 'success' && !mobile && cartLink ? (
            <a className={styles.toastLink} href={routes.cart}>
              عرض السلة
            </a>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
