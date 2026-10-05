'use client';
/**
 * Account navigation — the design's sticky 256px rail (desktop) and its
 * block-end bar (small screens), sharing one item model.
 *
 * Client-side only because `aria-current` needs the live path. Sections without
 * a backend are listed as static text under one «قريبًا» heading — visible and
 * honest, and impossible to mistake for a working link.
 */
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { ACCOUNT_MOBILE_NAV, ACCOUNT_NAV } from './nav';
import { AccountIcon } from './icons';
import styles from './AccountNav.module.css';

export function AccountSidebar() {
  const pathname = usePathname();
  const live = ACCOUNT_NAV.filter((item) => item.href);
  const soon = ACCOUNT_NAV.filter((item) => !item.href);

  return (
    <nav className={styles.sidebar} aria-label="أقسام الحساب">
      {live.map((item) => (
        <Link
          key={item.label}
          href={item.href as string}
          className={pathname === item.href ? `${styles.item} ${styles.active}` : styles.item}
          aria-current={pathname === item.href ? 'page' : undefined}
        >
          <AccountIcon name={item.icon} className={styles.itemIcon} />
          <span className={styles.itemLabel}>{item.label}</span>
        </Link>
      ))}
      {/*
        ما لم يُربط بعد: قائمةٌ ساكنة تحت عنوانٍ واحد «قريبًا» — لا أزرار معطّلة
        ولا شارةٌ مكرّرة على كلّ سطر. تُذكر بصدقٍ ولا تُعرض كروابط.
      */}
      {soon.length > 0 ? (
        <>
          <p className={styles.groupLabel} id="account-soon">
            قريبًا
          </p>
          <ul className={styles.soonList} aria-labelledby="account-soon">
            {soon.map((item) => (
              <li key={item.label} className={styles.soonItem}>
                <AccountIcon name={item.icon} className={styles.itemIcon} />
                <span className={styles.itemLabel}>{item.label}</span>
              </li>
            ))}
          </ul>
        </>
      ) : null}
    </nav>
  );
}

export function AccountBottomNav() {
  const pathname = usePathname();

  return (
    <nav className={styles.bottomBar} data-bottom-chrome="sticky" aria-label="أقسام الحساب">
      {ACCOUNT_MOBILE_NAV.map((item) => (
        <Link
          key={item.href}
          href={item.href}
          className={
            pathname === item.href ? `${styles.bottomItem} ${styles.bottomActive}` : styles.bottomItem
          }
          aria-current={pathname === item.href ? 'page' : undefined}
        >
          <AccountIcon name={item.icon} className={styles.bottomIcon} />
          <span className={styles.bottomLabel}>{item.label}</span>
        </Link>
      ))}
    </nav>
  );
}
