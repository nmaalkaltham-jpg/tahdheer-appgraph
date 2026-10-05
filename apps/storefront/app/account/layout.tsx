/**
 * Account shell — «Tahdheer - My Account» body grid (256px rail + main) with
 * the block-end bar on small screens.
 *
 * The design draws its own dark header (brand, language switch, bell). None of
 * that is rebuilt here: the storefront's SiteHeader already renders the brand
 * chrome one level up, the store is single-locale, and there is no notification
 * source — duplicating the bar would mean two headers and one fake bell.
 *
 * Every account surface is private and token-shaped, so the whole subtree is
 * noindex (MIP §3.2).
 */
import type { Metadata } from 'next';
import { AccountBottomNav, AccountSidebar } from './AccountNav';
import styles from './layout.module.css';

export const metadata: Metadata = {
  robots: { index: false, follow: true },
};

export default function AccountLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className={styles.shell}>
      <div className={styles.grid}>
        <AccountSidebar />
        {/* A <div>, not a <main>: the root layout already owns the document's
            single <main> landmark. */}
        <div className={styles.main}>{children}</div>
      </div>
      <AccountBottomNav />
    </div>
  );
}
