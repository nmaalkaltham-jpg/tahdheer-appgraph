import type { CSSProperties } from 'react';
import Image from 'next/image';
import { accountRoutes } from '../app/account/nav';
import { apiGetResource } from '../lib/api';
import { routes } from '../lib/routes';
import { normalizeAnnouncements, type Announcements } from './announcements';
import styles from './SiteHeader.module.css';
import { AnnouncementMarquee, CartLink, MobileMenu, PrimaryNav, WishlistLink } from './SiteHeaderClient';
import type { NavigationTree } from '../lib/navigation';
import { SearchBox } from './search/SearchBox';
import type { BrowseTree } from './chrome/browse-tree';
import { BrowseDrawer } from './chrome/BrowseDrawer';
import { ThemeToggle } from './chrome/ThemeToggle';

/**
 * Site header.
 *
 * Desktop: announcement bar (when the API has one), then ONE compact row on a
 * grid: logo · nav · search (the flexible column) · cart · theme · wishlist ·
 * «مكتبتي» · «حسابي». Tablet folds the nav onto a second row; mobile is a
 * dedicated compact bar (logo · library · wishlist · cart · menu) with the
 * search underneath and the nav inside the drawer (SiteHeader.module.css).
 *
 * Honesty: the announcement bar is GET /api/v1/home/announcements (messages,
 * trailing link, static|marquee mode, optional bg/fg/height) — the design's
 * bar variants bound to a real, admin-edited setting. No data ⇒ no bar. The
 * design's «تبديل الرسائل» rotate mode has no API value and is not built.
 * «المفضلة» reads the browser wishlist store (`lib/wishlist.ts`, /wishlist).
 * The cart count and total come from the live cart (`CartLink`), never a sample.
 */
export interface SiteHeaderProps {
  announcements?: Announcements | null;
  cartCount?: number;
  cartTotalMinor?: number;
  nav: NavigationTree;
  mobileNav?: NavigationTree;
  /** المراحل بصفوفها والمواد (GET /api/v1/catalog/*) — لوحة «المراحل» الكبرى وأكورديون الدرج. */
  browse?: BrowseTree | null;
}

/** تُقرأ في التخطيط لكلّ طلب؛ 404 أو ردٌّ مشوَّه ⇒ لا شريط. */
export async function getAnnouncements(): Promise<Announcements | null> {
  const { data } = await apiGetResource<unknown>('/api/v1/home/announcements');
  return normalizeAnnouncements(data);
}

/** Storefront Home.dc.html: مدّة الشريط المتحرّك الافتراضيّة (barSpeed = 28s). */
const MARQUEE_SECONDS = 28;

function announceVars(a: Announcements): CSSProperties | undefined {
  if (a.style === null) return undefined;
  const vars: Record<string, string> = {};
  if (a.style.bg !== undefined) vars['--ch-bar-bg'] = a.style.bg;
  if (a.style.fg !== undefined) vars['--ch-bar-fg'] = a.style.fg;
  if (a.style.height !== undefined) vars['--ch-bar-h'] = `${a.style.height}px`;
  return vars as CSSProperties;
}

function SearchIcon() {
  return (
    <svg aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
      <circle cx="11" cy="11" r="7" />
      <path d="M21 21l-4-4" />
    </svg>
  );
}

function ChevronIcon() {
  return (
    <svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M14 6l-6 6 6 6" />
    </svg>
  );
}

function UserIcon() {
  return (
    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="8" r="3.4" />
      <path d="M5.9 19.4a6.2 6.2 0 0 1 12.2 0" />
    </svg>
  );
}

function LibraryIcon() {
  return (
    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
      <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h2A1.5 1.5 0 0 1 9 5.5v13A1.5 1.5 0 0 1 7.5 20h-2A1.5 1.5 0 0 1 4 18.5z" />
      <path d="M10 5.5A1.5 1.5 0 0 1 11.5 4h2A1.5 1.5 0 0 1 15 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-2A1.5 1.5 0 0 1 10 18.5z" />
      <path d="M16.2 6.3l1.9-.6a1.5 1.5 0 0 1 1.9 1l3 11.6a1.5 1.5 0 0 1-1 1.9l-1.9.5" />
    </svg>
  );
}

function MenuIcon() {
  return (
    <svg aria-hidden="true" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
      <path d="M4 7h16M4 12h16M4 17h10" />
    </svg>
  );
}

export function SiteHeader({ announcements, cartCount, cartTotalMinor, nav, mobileNav, browse = null }: SiteHeaderProps) {
  const panelNav = mobileNav !== undefined && mobileNav.items.length > 0 ? mobileNav : nav;

  return (
    <header className={styles.header} data-theme-component="site-header">
      {announcements ? (
        <div className={styles.announce} style={announceVars(announcements)}>
          <div className={styles.announceInner}>
            {announcements.mode === 'marquee' ? (
              /* «شريط متحرك»: الرسائل مكرّرةً مرّتين وبينها «•»، ومعها زرّ إيقافٍ (SiteHeaderClient). */
              <AnnouncementMarquee items={announcements.items} seconds={MARQUEE_SECONDS} />
            ) : (
              <div className={styles.announceTrack}>
                {announcements.items.length > 0 ? (
                  <ul className={styles.announceItems} role="list">
                    {/* الفاصل «|» زخرفةٌ في ::before لا نصٌّ يُعدّ في فحص التباين. */}
                    {announcements.items.map((text, index) => (
                      <li key={`${index}-${text}`} className={index > 0 ? styles.announceSep : undefined}>
                        <span>{text}</span>
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            )}
            {announcements.link ? (
              <a className={styles.announcePill} href={announcements.link.href}>
                {announcements.link.label} <ChevronIcon />
              </a>
            ) : null}
          </div>
        </div>
      ) : null}

      <div className={styles.bar}>
        <div className={styles.barInner}>
          <a className={styles.logo} href={routes.home}>
            <Image className={styles.logoImage} src="/brand/logo-full.png" alt="التحاضير الحديثة" width={512} height={289} priority />
          </a>

          <PrimaryNav variant="bar" items={nav.items} browse={browse} />

          <form className={styles.search} action={routes.search} method="get" role="search">
            <label className={styles.srOnly} htmlFor="site-search">
              البحث في المتجر
            </label>
            <span className={styles.searchIcon}>
              <SearchIcon />
            </span>
            {/* جذر SearchBox ابنٌ مباشر للصدفة: هالة التركيز على أبٍ قريب من الحقل (‎§2.4.7‎). */}
            <SearchBox />
            <button className={styles.searchBtn} type="submit">
              بحث
            </button>
          </form>

          {/*
            ترتيب المستند هو الترتيب البصريّ من اليمين في RTL: السلة تلي البحث،
            ثمّ المظهر والمفضّلة، ثمّ «مكتبتي» و«حسابي» في الطرف. التسميتان
            تُطوَيان إلى أيقونتين تحت ‎1440‎ (النصّ يبقى للقارئ) و`title` يسمّيهما.
          */}
          <div className={styles.actions}>
            <CartLink initialCount={cartCount} initialTotalMinor={cartTotalMinor} />

            <ThemeToggle variant="bar" />

            <WishlistLink />

            <a className={styles.account} href={accountRoutes.library} title="مكتبتي">
              <LibraryIcon /> <span className={styles.actionLabel}>مكتبتي</span>
            </a>

            <a className={`${styles.account} ${styles.accountDesktop}`} href={routes.login} title="حسابي">
              <UserIcon /> <span className={styles.actionLabel}>حسابي</span>
            </a>

            <MobileMenu icon={<MenuIcon />}>
              <PrimaryNav variant="panel" items={panelNav.items} />
              {browse ? <BrowseDrawer tree={browse} /> : null}
              <ThemeToggle variant="panel" />
            </MobileMenu>
          </div>
        </div>
      </div>
    </header>
  );
}
