'use client';
/**
 * The genuinely interactive pieces of SiteHeader, split out so the rest of the
 * header stays a Server Component:
 *
 *  · PrimaryNav — reads the live pathname so aria-current="page" and the
 *    active pill are REAL route state, never a hardcoded «الرئيسية».
 *  · CartLink — subscribes to the real cart so the gold badge and the running
 *    total reflect the server's cart, not props nobody passes.
 */
import { usePathname } from 'next/navigation';
import {
  useEffect,
  useRef,
  useState,
  type CSSProperties,
  type FocusEvent as ReactFocusEvent,
  type PointerEvent as ReactPointerEvent,
  type ReactNode,
} from 'react';
import { formatNumber, formatMoney, qty } from '@tahdheer/design-system/format';
import { getCart } from '../lib/commerce-api';
import type { NavigationItem } from '../lib/navigation';
import { routes } from '../lib/routes';
import { useWishlist } from '../lib/wishlist';
import { CART_EVENT } from './product/useAddToCart';
import type { BrowseTree } from './chrome/browse-tree';
import { focusFirstMegaLink, MegaMenu } from './chrome/MegaMenu';
import styles from './SiteHeader.module.css';

const cx = (...parts: Array<string | false | undefined>) => parts.filter(Boolean).join(' ');

/** #i-chev from TahIcons.dc.html — a start-pointing chevron. */
function ChevronIcon({ className }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      className={className}
      width="11"
      height="11"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M14 6l-6 6 6 6" />
    </svg>
  );
}

/**
 * الشريط المتحرّك مع زرّ إيقافه — WCAG 2.2.2: حركةٌ تدوم فوق خمس ثوانٍ لها
 * آليّةُ إيقافٍ يملكها المستعمل، لا التحويم وحده (اللمس لا يحوّم، ولوحة
 * المفاتيح لا تجد ما تركّز عليه حين لا رابط في الشريط). الرسائل مكرّرةً
 * مرّتين للحلقة البصريّة؛ النسخة الثانية مخفيّةٌ عن قارئ الشاشة.
 */
export function AnnouncementMarquee({ items, seconds }: { items: readonly string[]; seconds: number }) {
  const [paused, setPaused] = useState(false);
  return (
    <>
      <div className={styles.announceTrack}>
        <ul
          className={styles.announceMarquee}
          role="list"
          data-paused={paused ? 'true' : 'false'}
          style={{ '--ch-marquee-s': `${seconds}s` } as CSSProperties}
        >
          {[...items, ...items].map((text, index) => {
            const dup = index >= items.length;
            return (
              <li key={`${index}-${text}`} className={cx(styles.announceLoopItem, dup && styles.announceLoopDup)} aria-hidden={dup ? true : undefined}>
                <span>{text}</span>
              </li>
            );
          })}
        </ul>
      </div>
      <button
        type="button"
        className={styles.announcePause}
        aria-pressed={paused}
        aria-label="إيقاف حركة الشريط"
        title={paused ? 'تشغيل الحركة' : 'إيقاف الحركة'}
        onClick={() => setPaused((current) => !current)}
      >
        {paused ? (
          <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
            <path d="M7 4.5v15l12-7.5z" />
          </svg>
        ) : (
          <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
            <path d="M6 4.5h4.5v15H6zM13.5 4.5H18v15h-4.5z" />
          </svg>
        )}
      </button>
    </>
  );
}

export interface PrimaryNavProps {
  /** 'bar' = الشريط الأفقيّ على سطح المكتب · 'panel' = داخل إفصاح الجوّال. */
  variant: 'bar' | 'panel';
  /** الشجرة المنشورة بأبنائها. الفارغة تُصيَّر ملاحةً بلا روابط — لا روابط مخترَعة. */
  items: readonly NavigationItem[];
  /**
   * شجرة الكتالوج للّوحة الكبرى — تُعلَّق على بند «المراحل» في الشريط حين لا
   * يحمل من اللوحة أبناءً (الأبناء المنشورون أولى دائمًا).
   */
  browse?: BrowseTree | null;
}

/** مهلة نيّة التحويم: عبورُ المؤشّر فوق البند في طريقه لا يفتح لوحة. */
const HOVER_OPEN_MS = 140;
const HOVER_CLOSE_MS = 220;

/** متى تُصيَّر الأبناء لوحةً كبرى بأعمدة بدل قائمةٍ واحدة. */
function isMega(item: NavigationItem): boolean {
  return item.children.some((child) => child.children.length > 0);
}

function linkAttrs(item: NavigationItem) {
  return item.target === '_blank'
    ? { target: '_blank', rel: item.rel !== '' ? item.rel : 'noopener noreferrer' }
    : item.rel !== ''
      ? { rel: item.rel }
      : {};
}

/**
 * المصيِّر القانونيّ للتنقّل — هو نفسه للمنشور وللمسوّدة.
 *
 * كان يُصيَّر المستوى الأوّل وحده ويرسم شيفرونًا لا ينفتح: يبني المحرّر شجرةً
 * عمقها أربعة فلا يرى الزائر منها إلّا جذورها، والشيفرون يَعِد بانسدالٍ لا
 * وجود له. الآن: انسدالٌ حقيقيّ، ولوحةٌ كبرى حين يكون للأبناء أبناء، وأكورديون
 * على الجوّال — والشيفرون لا يُرسَم إلّا حيث ينفتح شيء.
 *
 * نمط الإفصاح (زرّ + `aria-expanded` + `aria-controls`) لا `menubar`: القائمة
 * روابط تنقّلٍ لا أوامر تطبيق، و`role="menu"` يجعل قارئ الشاشة يَعِد بدلالة
 * قائمة أوامر فيُربك المستعمل بمفاتيح لا تعمل.
 */
export function PrimaryNav({ variant, items, browse }: PrimaryNavProps) {
  const pathname = usePathname();
  const [openId, setOpenId] = useState<string | null>(null);
  const navRef = useRef<HTMLElement | null>(null);
  const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const openTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  /* لحظة فتحٍ بالتحويم: نقرةٌ على الزرّ بعدها مباشرةً تأكيدٌ لا طيّ. */
  const hoverOpenedAt = useRef(0);

  /*
   * تغيّر المسار يغلق: لوحةٌ تبقى مفتوحة فوق الصفحة الجديدة تحجبها.
   *
   * تعديلُ الحالة أثناء الرسم لا داخل أثر (قاعدة المستودع ٢٢): الأثر يرسم
   * مرّةً باللوحة مفتوحةً ثمّ يُعيد الرسم مغلقةً — ومضةٌ مرئيّة عند كلّ تنقّل.
   */
  const [seenPath, setSeenPath] = useState(pathname);
  if (seenPath !== pathname) {
    setSeenPath(pathname);
    setOpenId(null);
  }

  // النقر خارج الملاحة يغلق — والمفتاح `Escape` يغلق ويُعيد التركيز.
  useEffect(() => {
    if (openId === null) return;

    const onPointer = (event: PointerEvent) => {
      if (navRef.current !== null && !navRef.current.contains(event.target as Node)) setOpenId(null);
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      const trigger = navRef.current?.querySelector<HTMLButtonElement>(`[data-branch="${CSS.escape(openId)}"]`);
      setOpenId(null);
      trigger?.focus();
    };

    document.addEventListener('pointerdown', onPointer);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('pointerdown', onPointer);
      document.removeEventListener('keydown', onKey);
    };
  }, [openId]);

  /*
   * إطفاء المسلك الاحتياطيّ بعد أن يعمل JavaScript.
   *
   * لوحات الفروع كانت تُخفى بسمة `hidden` المحسوبة من حالة React وحدها،
   * فبلا JavaScript لا تُفتح واحدةٌ منها أبدًا: المستوى الثاني والثالث من
   * الملاحة المنشورة — على الشاشة الواسعة وفي درج الجوّال معًا — يصيران
   * غير قابلين للوصول، وهما روابط أقسامٍ حقيقيّة.
   *
   * فالـCSS يفتح بالتحويم والتركيز حين تكون السمة `off`، وهذا الأثر
   * يقلبها إلى `on` عند التركيب فيعود القرار كلّه إلى الحالة. ولولا هذا
   * القلب لانفتحت اللوحة بالتركيز و`aria-expanded` تقول «مطويّة» — وعدٌ
   * يخالف ما يُرى.
   *
   * وسمةٌ تُكتب بالمرجع لا حالةٌ تُضبط: لا إعادة تصيير ولا فرقٌ بين ترميز
   * الخادم وأوّل تصييرٍ في المتصفّح.
   */
  useEffect(() => {
    navRef.current?.setAttribute('data-js', 'on');
  }, []);

  useEffect(
    () => () => {
      if (closeTimer.current !== null) clearTimeout(closeTimer.current);
      if (openTimer.current !== null) clearTimeout(openTimer.current);
    },
    [],
  );

  const isActive = (href: string) =>
    href === routes.home ? pathname === href : pathname === href || pathname.startsWith(`${href}/`);

  /** فرعٌ فيه المسار الحاليّ يُعدّ نشطًا وإن لم يكن رابطه هو المفتوح. */
  const branchActive = (item: NavigationItem): boolean =>
    isActive(item.href) || item.children.some((child) => branchActive(child));

  const bar = variant === 'bar';

  /*
   * نيّة التحويم — على مؤشّرٍ دقيق وحده (فأرة): اللمس يفتح بالنقر على الزرّ.
   * الفتح بمهلةٍ قصيرة فلا يفتح لوحةً مرورُ المؤشّر إلى الشريط، والإغلاق
   * بمهلةٍ تسمح بعبور الفجوة من البند إلى اللوحة. وفرعٌ مفتوح يُبدَّل فورًا.
   */
  const clearTimers = () => {
    if (closeTimer.current !== null) clearTimeout(closeTimer.current);
    if (openTimer.current !== null) clearTimeout(openTimer.current);
  };
  const fine = (event: ReactPointerEvent<HTMLElement>) =>
    event.pointerType === 'mouse' && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  const hoverOpen = (id: string, event: ReactPointerEvent<HTMLElement>) => {
    if (!bar || !fine(event)) return;
    clearTimers();
    const openNow = () => {
      hoverOpenedAt.current = performance.now();
      setOpenId(id);
    };
    if (openId !== null) openNow();
    else openTimer.current = setTimeout(openNow, HOVER_OPEN_MS);
  };
  const hoverClose = (event: ReactPointerEvent<HTMLElement>) => {
    if (!bar || !fine(event)) return;
    clearTimers();
    closeTimer.current = setTimeout(() => setOpenId(null), HOVER_CLOSE_MS);
  };
  /* التركيز يغادر الفرع كلّه (Tab بعد آخر رابط) ⇒ تُطوى لوحته — لا لوحةٌ مفتوحة خلف التركيز. */
  const blurClose = (id: string) => (event: ReactFocusEvent<HTMLElement>) => {
    const next = event.relatedTarget as Node | null;
    if (next !== null && !event.currentTarget.contains(next)) setOpenId((current) => (current === id ? null : current));
  };

  const renderSub = (children: readonly NavigationItem[], depth: number) => (
    <ul className={depth === 1 ? styles.subList : styles.subSubList} role="list">
      {children.map((child) => (
        <li key={child.id}>
          <a
            className={cx(styles.subLink, isActive(child.href) && styles.subLinkActive)}
            href={child.href}
            aria-current={isActive(child.href) ? 'page' : undefined}
            aria-label={child.aria !== '' ? child.aria : undefined}
            id={child.cssId !== '' ? child.cssId : undefined}
            {...linkAttrs(child)}
          >
            <span className={styles.subLabel}>
              {child.label}
              {child.badge !== '' ? <span className={styles.navBadge}>{child.badge}</span> : null}
            </span>
            {child.desc !== '' ? <span className={styles.subDesc}>{child.desc}</span> : null}
          </a>
          {child.children.length > 0 ? renderSub(child.children, depth + 1) : null}
        </li>
      ))}
    </ul>
  );

  return (
    <nav
      ref={navRef}
      className={bar ? styles.nav : undefined}
      data-js="off"
      // الفيض يصير مرئيًّا وقت الفتح فتهرب اللوحة من مسطح التمرير (انظر الورقة).
      data-open={openId === null ? 'false' : 'true'}
      aria-label={bar ? 'التنقل الرئيسي' : 'قائمة التنقل'}
    >
      <ul className={styles.navList} role="list">
        {items.map((item) => {
          const active = branchActive(item);
          const mega = bar && item.children.length === 0 && item.href === routes.stages && browse ? browse : null;
          const hasChildren = item.children.length > 0 || mega !== null;
          const open = openId === item.id;
          const panelId = `nav-panel-${item.id}`;

          if (!hasChildren) {
            return (
              <li key={item.id}>
                <a
                  className={cx(
                    styles.navLink,
                    active && styles.navLinkActive,
                    item.href === routes.offers && styles.navLinkOffers,
                  )}
                  href={item.href}
                  aria-current={isActive(item.href) ? 'page' : undefined}
                  aria-label={item.aria !== '' ? item.aria : undefined}
                  id={item.cssId !== '' ? item.cssId : undefined}
                  {...linkAttrs(item)}
                >
                  {item.label}
                  {item.badge !== '' ? <span className={styles.navBadge}>{item.badge}</span> : null}
                </a>
              </li>
            );
          }

          return (
            <li
              key={item.id}
              className={cx(styles.navBranch, mega !== null && styles.navBranchMega)}
              data-nav-branch=""
              onPointerEnter={(event) => hoverOpen(item.id, event)}
              onPointerLeave={hoverClose}
              onBlur={bar ? blurClose(item.id) : undefined}
            >
              {/*
                الوجهة والانسدال فعلان مختلفان، فلهما عنصران.
                زرٌّ واحد يحمل الاثنين يسرق من مستعمل لوحة المفاتيح إمكان
                زيارة صفحة القسم نفسها.
              */}
              <span
                className={cx(
                  styles.navLink,
                  styles.navBranchHead,
                  active && styles.navLinkActive,
                  item.href === routes.offers && styles.navLinkOffers,
                )}
              >
                <a
                  className={styles.navBranchLink}
                  href={item.href}
                  aria-current={isActive(item.href) ? 'page' : undefined}
                  aria-label={item.aria !== '' ? item.aria : undefined}
                  {...linkAttrs(item)}
                >
                  {item.label}
                  {item.badge !== '' ? <span className={styles.navBadge}>{item.badge}</span> : null}
                </a>
                <button
                  type="button"
                  className={styles.navToggle}
                  data-branch={item.id}
                  /* اسمٌ في السمة لا نصٌّ مخفيّ داخل الزرّ: الزرّ صار مموضَعًا (لتمديد هدفه)، فصار النصّ ‎1px‎ يُرسم فوق الشيفرون ويُقاس تباينه. */
                  aria-label={`${open ? 'إغلاق' : 'فتح'} قائمة ${item.label}`}
                  aria-expanded={open}
                  aria-controls={panelId}
                  onClick={() => {
                    clearTimers();
                    if (open && performance.now() - hoverOpenedAt.current < 400) return;
                    setOpenId(open ? null : item.id);
                  }}
                  onKeyDown={
                    mega !== null
                      ? (event) => {
                          // ↓ على الزرّ: يفتح ويضع التركيز على أوّل رابطٍ في اللوحة.
                          if (event.key !== 'ArrowDown') return;
                          event.preventDefault();
                          setOpenId(item.id);
                          requestAnimationFrame(() => focusFirstMegaLink(document.getElementById(panelId)));
                        }
                      : undefined
                  }
                >
                  <ChevronIcon className={cx(styles.navChevron, open && styles.navChevronOpen)} />
                </button>
              </span>

              {mega !== null ? (
                <MegaMenu id={panelId} open={open} tree={mega} pathname={pathname} label={item.label} />
              ) : (
                <div
                  id={panelId}
                  className={cx(
                    bar ? styles.dropdown : styles.drawerPanel,
                    bar && isMega(item) && styles.dropdownMega,
                  )}
                  data-open={open ? 'true' : 'false'}
                >
                  {renderSub(item.children, 1)}
                </div>
              )}
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

/**
 * درج الجوّال — ‎<details>‎ يعمل بلا JavaScript (الملخّص يفتح، والستار
 * ‎::before‎ الملخّص يطوي)، وهذا المكوّن يضيف سلوك الحوار حين يعمل:
 *  · التركيز ينتقل إلى الدرج ويُحبس فيه (‎Tab‎ يدور)، ويعود إلى الزرّ عند الطيّ.
 *  · ‎Escape‎ وزرّ «إغلاق» يطويانه؛ وتغيّر المسار يطويه.
 *  · تمرير الصفحة يُقفل ما دام مفتوحًا.
 */
const FOCUSABLE = 'a[href], button:not([disabled]), summary, input, select, textarea, [tabindex]:not([tabindex="-1"])';

export function MobileMenu({ icon, children }: { icon: ReactNode; children: ReactNode }) {
  const ref = useRef<HTMLDetailsElement | null>(null);
  const panelRef = useRef<HTMLDivElement | null>(null);
  const [open, setOpen] = useState(false);
  const pathname = usePathname();

  useEffect(() => {
    if (ref.current) ref.current.open = false;
  }, [pathname]);

  useEffect(() => {
    const details = ref.current;
    const panel = panelRef.current;
    if (!open || !details || !panel) return undefined;
    const summary = details.querySelector('summary');
    const root = document.documentElement;
    const overflow = root.style.overflow;
    root.style.overflow = 'hidden';
    /*
     * محتوى ‎<details>‎ يصير قابلًا للتركيز حين يُرسم مفتوحًا — وقد يتأخّر إطارًا
     * أو أكثر (انتقال الطيّ السابق، ‎content-visibility‎). يُعاد المحاولة كلّ
     * إطارٍ حتّى يستقرّ التركيز داخل الدرج، بحدٍّ أقصى عشرة.
     */
    let frame = 0;
    let tries = 0;
    const place = () => {
      if (panel.contains(document.activeElement)) return;
      panel.querySelector<HTMLElement>(FOCUSABLE)?.focus();
      if (!panel.contains(document.activeElement) && ++tries < 10) frame = requestAnimationFrame(place);
    };
    frame = requestAnimationFrame(place);

    const close = () => {
      details.open = false;
      summary?.focus();
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        close();
        return;
      }
      if (event.key !== 'Tab') return;
      const items = [...panel.querySelectorAll<HTMLElement>(FOCUSABLE)].filter((el) => el.offsetParent !== null && el.checkVisibility());
      const first = items[0];
      const last = items[items.length - 1];
      if (!first || !last) return;
      const current = document.activeElement;
      if (event.shiftKey && (current === first || !panel.contains(current))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (current === last || !panel.contains(current))) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => {
      cancelAnimationFrame(frame);
      document.removeEventListener('keydown', onKey);
      root.style.overflow = overflow;
    };
  }, [open]);

  return (
    <details ref={ref} className={styles.menu} onToggle={(event) => setOpen(event.currentTarget.open)}>
      <summary className={styles.menuBtn}>
        {icon}
        <span className={styles.srOnly}>القائمة</span>
      </summary>
      <div ref={panelRef} className={styles.menuPanel} role="dialog" aria-modal="true" aria-labelledby="site-drawer-title">
        <div className={styles.drawerHead}>
          <b className={styles.drawerTitle} id="site-drawer-title">
            القائمة
          </b>
          <button
            type="button"
            className={styles.drawerClose}
            aria-label="إغلاق القائمة"
            onClick={() => {
              if (!ref.current) return;
              ref.current.open = false;
              ref.current.querySelector('summary')?.focus();
            }}
          >
            <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
              <path d="M6 6l12 12M18 6L6 18" />
            </svg>
          </button>
        </div>
        {children}
      </div>
    </details>
  );
}

/** #i-cart from TahIcons.dc.html. */
function CartIcon() {
  return (
    <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
      <path d="M3 4h2l2.4 11.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L21 8H6" />
      <circle cx="9.5" cy="20" r="1.4" />
      <circle cx="17" cy="20" r="1.4" />
    </svg>
  );
}

const ITEM_FORMS = {
  zero: 'لا منتجات',
  one: 'منتج واحد',
  two: 'منتجان',
  few: 'منتجات',
  many: 'منتجًا',
} as const;

interface CartSummary {
  count: number;
  /** null ⇒ no total is known, so none is shown. Never 0.00 as a stand-in. */
  totalMinor: number | null;
}

const EMPTY_CART: CartSummary = { count: 0, totalMinor: null };

export interface CartLinkProps {
  /** Server-known line count, when a caller has one. Absent ⇒ 0 until the live read lands. */
  initialCount?: number;
  /** Server-known total in minor units. Absent ⇒ nothing is shown. */
  initialTotalMinor?: number;
}

/**
 * الترويسة تقرأ السلة الحقيقية.
 *
 * كانت الشارة والمجموع موجودَين في CSS وفي الخصائص، ولا أحد يمرّرهما في
 * `app/layout.tsx` — فلم يظهرا قطّ. هنا يُوصَل `getCart()` بهما.
 *
 * الحالة الصادقة عند الفشل أو عند سلّة فارغة هي **لا شيء**: لا شارة صفرية
 * ولا «0.00 ر.س». وقراءةٌ فاشلة لا تعني «السلة فارغة» بل «لا نعلم»، وكلتاهما
 * لا تُعرض.
 *
 * التحديث: عند التركيب، وعند كل تغيّر مسار (الإضافة تقع في صفحة المنتج ثم
 * ينتقل المستخدم)، وعند عودة اللسان إلى الظهور (الدفع في لسان آخر)، وعند كل
 * حدث CART_EVENT — أي تعديل ناجح للسلة في الصفحة نفسها دون تنقّل.
 */
/**
 * ملخّص السلة الحيّ — خطّافٌ واحد يقرؤه زرّ السلة في الترويسة وبلاطة السلة في
 * الشريط السفليّ للجوّال، فلا تختلف الشارتان على العدد نفسه.
 */
export function useCartSummary(initialCount?: number, initialTotalMinor?: number): CartSummary {
  const [cart, setCart] = useState<CartSummary>(() => ({
    count: initialCount ?? 0,
    totalMinor: initialTotalMinor ?? null,
  }));
  const pathname = usePathname();
  // تذكرة لكل قراءة: ردٌّ بطيء سابق لا يستبدل ردًّا أحدث، وزيادتها عند
  // التفكيك تُبطل كل ما هو في الطريق.
  const ticketRef = useRef(0);

  useEffect(() => {
    const read = () => {
      const ticket = ticketRef.current + 1;
      ticketRef.current = ticket;
      void getCart().then((res) => {
        if (ticket !== ticketRef.current) return;
        const payload = res.data;
        setCart(
          payload && payload.count > 0
            ? { count: payload.count, totalMinor: payload.totals.totalMinor }
            : EMPTY_CART,
        );
      });
    };

    read();

    const onVisibility = () => {
      if (document.visibilityState === 'visible') read();
    };
    document.addEventListener('visibilitychange', onVisibility);
    // Same-page cart mutations (line removed on /cart, coupon applied at
    // checkout) announce themselves — same protected read, same ticket.
    window.addEventListener(CART_EVENT, read);
    return () => {
      ticketRef.current += 1;
      document.removeEventListener('visibilitychange', onVisibility);
      window.removeEventListener(CART_EVENT, read);
    };
  }, [pathname]);

  return cart;
}

export function CartLink({ initialCount, initialTotalMinor }: CartLinkProps) {
  const cart = useCartSummary(initialCount, initialTotalMinor);
  const total = cart.totalMinor === null ? null : formatMoney(cart.totalMinor);

  return (
    <a className={styles.cart} href={routes.cart}>
      <span className={styles.cartIcon}>
        <CartIcon />
        {cart.count > 0 ? (
          <span className={styles.cartBadge} aria-hidden="true" dir="ltr">
            {formatNumber(cart.count)}
          </span>
        ) : null}
      </span>
      <span className={styles.srOnly}>سلة التسوق</span>
      {/*
        عمود النصّ: العدد سطرًا صغيرًا ثمّ المبلغ. ولا يُصيَّر إلّا لعددٍ حقيقيّ
        فوق الصفر — والمبلغ حين يعرفه الخادم فقط. وسلّةٌ فارغة (أو مجهولة)
        تحمل تسمية «السلة» لا رقمًا صفريًّا — مخفيّةً عن قارئ الشاشة لأنّ الاسم
        «سلة التسوق» أعلاه يكفيه.
      */}
      {cart.count > 0 ? (
        <span className={styles.cartText}>
          <span className={styles.cartCount}>{qty(cart.count, ITEM_FORMS)}</span>
          {total ? (
            <b className={styles.cartTotal} dir="ltr">
              {total.amount} <span className={styles.cartUnit}>{total.currency}</span>
            </b>
          ) : null}
        </span>
      ) : (
        <span className={styles.cartLabel} aria-hidden="true">
          السلة
        </span>
      )}
    </a>
  );
}

/** #i-heart — القلب المفرَّغ كما في Storefront Home/Product.dc.html. */
function HeartIcon({ size }: { size: number }) {
  return (
    <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 20s-7-4.4-7-9.5A3.9 3.9 0 0 1 12 8a3.9 3.9 0 0 1 7 2.5C19 15.6 12 20 12 20z" />
    </svg>
  );
}

/**
 * زرّ المفضّلة في الترويسة — العدّاد من مخزن المتصفّح (`useWishlist`)،
 * ولا شارة إلّا لعددٍ فوق الصفر. لقطة الخادم فارغة دائمًا فلا اختلاف إماهة.
 */
export function WishlistLink() {
  const count = useWishlist().length;
  return (
    /* الاسم نصٌّ مخفيّ لا ‎aria-label‎: التسمية كانت تطمس العدّاد فلا يُعلَن رقم الشارة. */
    <a className={styles.wishlist} href={routes.wishlist} title="المفضلة">
      <span className={styles.wishlistIconDesktop}>
        <HeartIcon size={19} />
      </span>
      <span className={styles.wishlistIconMobile}>
        <HeartIcon size={20} />
      </span>
      {count > 0 ? (
        <span className={styles.wishlistBadge} aria-hidden="true" dir="ltr">
          {formatNumber(count)}
        </span>
      ) : null}
      <span className={styles.srOnly}>{count > 0 ? `المفضلة، ${formatNumber(count)} في المفضلة` : 'المفضلة'}</span>
    </a>
  );
}
