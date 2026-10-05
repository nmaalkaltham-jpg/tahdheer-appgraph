'use client';
/**
 * الشريط السفليّ اللاصق للجوّال — Storefront Home Mobile.dc.html، القاع:
 * بلاطة السلة 64×64 · «إتمام الشراء» · Apple Pay (إن أكّدها الخادم والجهاز معًا) ·
 * ثمّ شريط الألسنة الخمسة. يُعلن نفسه لـ`BottomChrome` بـ`data-bottom-chrome="sticky"`.
 *
 * لا يُصيَّر على /products/*: لصفحة المنتج شريط شرائها اللاصق.
 */
import { usePathname } from 'next/navigation';
import { formatNumber } from '@tahdheer/design-system/format';
import { routes } from '../lib/routes';
import { useApplePayAvailable } from '../lib/apple-pay';
import { useCartSummary } from './SiteHeaderClient';
import { activeBottomTab, BOTTOM_TABS, hidesBottomBar, type BottomTabId } from './mobile-bottom-bar';
import styles from './MobileBottomBar.module.css';

const STROKE = {
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.9,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
} as const;

/** أيقونات الألسنة الخمس كما في القالب (21px). */
const TAB_ICONS: Record<BottomTabId, React.ReactNode> = {
  home: (
    <svg aria-hidden="true" width="21" height="21" viewBox="0 0 24 24" {...STROKE}>
      <path d="M4 11l8-6 8 6v8a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1z" />
    </svg>
  ),
  browse: (
    <svg aria-hidden="true" width="21" height="21" viewBox="0 0 24 24" {...STROKE}>
      <rect x="4" y="4" width="7" height="7" rx="1.5" />
      <rect x="13" y="4" width="7" height="7" rx="1.5" />
      <rect x="4" y="13" width="7" height="7" rx="1.5" />
      <rect x="13" y="13" width="7" height="7" rx="1.5" />
    </svg>
  ),
  search: (
    <svg aria-hidden="true" width="21" height="21" viewBox="0 0 24 24" {...STROKE} strokeLinejoin={undefined}>
      <circle cx="11" cy="11" r="7" />
      <path d="M21 21l-4-4" />
    </svg>
  ),
  wishlist: (
    <svg aria-hidden="true" width="21" height="21" viewBox="0 0 24 24" {...STROKE}>
      <path d="M12 20s-7-4.4-7-9.5A3.9 3.9 0 0 1 12 8a3.9 3.9 0 0 1 7 2.5C19 15.6 12 20 12 20z" />
    </svg>
  ),
  account: (
    <svg aria-hidden="true" width="21" height="21" viewBox="0 0 24 24" {...STROKE}>
      <circle cx="12" cy="8" r="3.4" />
      <path d="M5.9 19.4a6.2 6.2 0 0 1 12.2 0" />
    </svg>
  ),
};

function CartIcon() {
  return (
    <svg aria-hidden="true" width="26" height="26" viewBox="0 0 24 24" {...STROKE} strokeWidth={1.8}>
      <path d="M3 4h2l2.4 11.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L21 8H6" />
      <circle cx="9.5" cy="20" r="1.4" />
      <circle cx="17" cy="20" r="1.4" />
    </svg>
  );
}

function AppleIcon() {
  return (
    <svg aria-hidden="true" width="18" height="22" viewBox="0 0 17 21" fill="currentColor">
      <path d="M13.94 11.15c.01-2.02 1.65-2.99 1.72-3.03-.94-1.37-2.4-1.56-2.92-1.58-1.24-.13-2.42.73-3.05.73-.64 0-1.61-.71-2.65-.69-1.36.02-2.62.79-3.32 2-1.42 2.46-.36 6.1 1.02 8.1.68.98 1.48 2.07 2.53 2.03 1.02-.04 1.4-.66 2.63-.66 1.22 0 1.57.66 2.64.64 1.09-.02 1.79-.99 2.46-1.98.77-1.13 1.09-2.22 1.11-2.28-.03-.01-2.12-.81-2.14-3.23M11.9 4.66c.55-.67.92-1.6.82-2.53-.82.03-1.81.55-2.38 1.21-.51.59-.95 1.54-.83 2.44.91.07 1.84-.46 2.39-1.12" />
    </svg>
  );
}

export function MobileBottomBarClient({ applePay }: { applePay: boolean }) {
  const pathname = usePathname();
  const cart = useCartSummary();
  /* القرار نفسه في صفحة المنتج والدفع: علمُ الخادم وحده لا يكفي — الجهاز يؤكّد. */
  const showApplePay = useApplePayAvailable(applePay);
  if (hidesBottomBar(pathname)) return null;
  const active = activeBottomTab(pathname);

  return (
    <div className={styles.bar} data-bottom-chrome="sticky">
      {/*
        صفّ السلّة لا يُصيَّر لسلّةٍ فارغة: «إتمام الشراء» بلا منتجات طريقٌ
        مسدود يقتطع ‎64px‎ من كلّ شاشة جوّال. ويظهر مع أوّل منتجٍ حقيقيّ
        (العدد من `useCartSummary` نفسه الذي تقرؤه الترويسة).
      */}
      {cart.count > 0 ? (
        <div className={styles.cartRow}>
          <a
            className={styles.cartTile}
            href={routes.cart}
            aria-label={`السلة: ${formatNumber(cart.count)}`}
          >
            <CartIcon />
            <span className={styles.cartCount} dir="ltr" aria-hidden="true">
              {formatNumber(cart.count)}
            </span>
          </a>
          <div className={styles.actions}>
            <a className={styles.checkout} href={routes.checkout}>
              <span className={styles.checkoutLabel}>إتمام الشراء</span>
            </a>
            {showApplePay ? (
              /* الدفع نفسه بـApple Pay مختارًا — `pay=apple` تقرؤه `lib/checkout-intent`. */
              <a className={styles.applePay} href={`${routes.checkout}?pay=apple`} aria-label="الدفع عبر Apple Pay">
                <span className={styles.appleInner}>
                  <AppleIcon />
                  <span className={styles.appleWord} dir="ltr">
                    Pay
                  </span>
                </span>
              </a>
            ) : null}
          </div>
        </div>
      ) : null}
      <nav className={styles.tabs} aria-label="التنقل السريع">
        {BOTTOM_TABS.map((tab) => (
          <a
            key={tab.id}
            className={styles.tab}
            href={tab.href}
            aria-current={active === tab.id ? 'page' : undefined}
          >
            {TAB_ICONS[tab.id]}
            {tab.label}
          </a>
        ))}
      </nav>
    </div>
  );
}
