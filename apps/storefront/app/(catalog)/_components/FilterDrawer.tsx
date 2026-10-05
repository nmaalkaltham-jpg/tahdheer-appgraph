'use client';
/**
 * درجُ الترشيح للجوّال — يحلّ محلّ رفّ الترشيح دون ‎1200px‎.
 *
 * `<dialog>` أصليّ بـ`showModal()`: المتصفّح يجعل ما خلفه خاملًا (حبسُ التركيز)،
 * ويغلقه بـEsc، ويعيد التركيز إلى زرّ الفتح عند الإغلاق. الدرج نفسه وصفةُ
 * `drawer` (يخرج من الحافّة الختاميّة) فوق `scrim`، والنقرُ على الستارة يغلقه.
 *
 * المرشّحات روابط GET (Archive.tsx)، فالنقرُ على أحدها تنقّلٌ كامل. ولكي لا
 * يُضطرّ القارئ إلى إعادة فتح الدرج بعد كلّ مرشّح، تُحفظ علامةٌ للجلسة قبل
 * التنقّل فيُفتح الدرج في الصفحة التالية فورًا (بلا حركة: الفتح لم يطلبه الآن)،
 * وزرّ «اعرض N نتيجة» يحمل العدد الذي أعاده الخادم لهذه المرشّحات بعينها.
 */
import { useCallback, useEffect, useRef, useState, type MouseEvent, type ReactNode } from 'react';
import { formatNumber } from '@tahdheer/design-system/format';
import { CatalogIcon } from './icons';
import styles from './catalog.module.css';

const REOPEN_KEY = 'tahdheer:catalog-drawer';

export interface FilterDrawerProps {
  /** عدد المرشّحات المفعّلة (يظهر على زرّ الفتح). */
  activeCount: number;
  /** تسمية زرّ التأكيد مشتقّةً من عدد الخادم («اعرض 27 نتيجة»). */
  applyLabel: string;
  /** رابط «امسح الكل» — يُرسم فقط مع مرشّحٍ مفعّل. */
  clearHref?: string;
  children: ReactNode;
}

export function FilterDrawer({ activeCount, applyLabel, clearHref, children }: FilterDrawerProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  /* الفتح التلقائيّ بعد التنقّل بلا حركة انزلاق. */
  const [instant, setInstant] = useState(false);

  const show = useCallback((withMotion: boolean) => {
    const dialog = dialogRef.current;
    if (!dialog || dialog.open) return;
    setInstant(!withMotion);
    dialog.showModal();
    /* التركيز الأوّل على الدرج نفسه لا على زرّ الإغلاق: قارئ الشاشة يسمع
       عنوانه، وTab التالية تبلغ الإغلاق ثمّ المرشّحات بترتيبها المرئيّ. */
    panelRef.current?.focus();
    setOpen(true);
  }, []);

  const close = useCallback(() => dialogRef.current?.close(), []);

  useEffect(() => {
    try {
      if (window.sessionStorage.getItem(REOPEN_KEY) === '1') {
        window.sessionStorage.removeItem(REOPEN_KEY);
        /* الدرج للجوّال وحده: على العريض الرفّ ظاهرٌ ولا حاجة إليه. */
        if (window.matchMedia('(max-width: 1199.98px)').matches) show(false);
      }
    } catch {
      /* تخزين الجلسة محجوب (وضعٌ خاصّ) — الدرج يعمل، وإعادة الفتح وحدها تغيب. */
    }
  }, [show]);

  /* قفلُ تمرير الصفحة خلف الدرج، وتحريره عند كلّ إغلاق (Esc، الستارة، الزرّ). */
  useEffect(() => {
    if (!open) return;
    const root = document.documentElement;
    const previous = root.style.overflow;
    root.style.overflow = 'hidden';
    return () => {
      root.style.overflow = previous;
    };
  }, [open]);

  /* نقرةٌ على رابط مرشّح داخل الدرج: علامةٌ لإعادة فتحه في الصفحة التالية. */
  const onBodyClick = (event: MouseEvent<HTMLElement>) => {
    const link = (event.target as HTMLElement).closest('a[href]');
    if (!link) return;
    try {
      window.sessionStorage.setItem(REOPEN_KEY, '1');
    } catch {
      /* انظر أعلاه. */
    }
  };

  return (
    <>
      <button
        type="button"
        className={styles.sheetBtn}
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={() => show(true)}
      >
        <CatalogIcon name="sliders" className={styles.btnIcon} />
        تصفية
        {activeCount > 0 ? (
          <span className={styles.countBadge}>
            <bdi dir="ltr">{formatNumber(activeCount)}</bdi>
            <span className={styles.srOnly}> مرشّحات مفعّلة</span>
          </span>
        ) : null}
      </button>

      <dialog
        ref={dialogRef}
        className={styles.drawerHost}
        aria-labelledby="catalog-drawer-title"
        data-instant={instant ? '' : undefined}
        onClose={() => setOpen(false)}
      >
        <div className={styles.drawerScrim} aria-hidden="true" onClick={close} />
        <div
          ref={panelRef}
          className={styles.drawerPanel}
          tabIndex={-1}
        >
          <div className={styles.drawerHead}>
            <h2 id="catalog-drawer-title" className={styles.drawerTitle}>
              تصفية النتائج
            </h2>
            <button type="button" className={styles.drawerClose} onClick={close} aria-label="أغلق التصفية">
              <CatalogIcon name="close" strokeWidth={2.4} className={styles.btnIcon} />
            </button>
          </div>

          <div className={styles.drawerBody} onClick={onBodyClick}>
            {children}
          </div>

          <div className={styles.drawerFoot}>
            {clearHref && activeCount > 0 ? (
              <a className={styles.drawerClear} href={clearHref} onClick={onBodyClick}>
                امسح الكل
              </a>
            ) : null}
            <button type="button" className={styles.drawerApply} onClick={close}>
              {applyLabel}
            </button>
          </div>
        </div>
      </dialog>
    </>
  );
}
