'use client';
// UNIT: POPUPSHELL
// FILE: PopupShell.tsx
// STYLE: PopupShell.module.css
// PARENT: UI-INDEX
// USES: REGISTRY-INDEX
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * PopupShell — «النافذة المنبثقة» tah/popup.1907 (STUDIO-PHASE3-CONTRACT §2.7،
 * قراران C35/C38): حاوية جذرية يملك المستندُ إعدادَها كاملة — المحفّز
 * والتكرار سلوك المستند وحده، لا أعمدة قاعدة بيانات.
 *
 * قوانين مُلزِمة (العقد §2.7):
 *   · الأبناء لا يُركَّبون في DOM قبل التحفيز — ‎{open && …}‎ بنيويًّا.
 *   · منبثقة واحدة مفتوحة في آن — مزلاج على مستوى الوحدة (module latch):
 *     المحفّز التلقائي لا يسرق مزلاجًا محجوزًا؛ الفتح الصريح يسرقه (معاينة
 *     المحرّر تغلق ما سواها).
 *   · سقوف التكرار تحت المفتاح ‎tah.popup.{pageKey}:{nodeId}‎ في
 *     localStorage/sessionStorage — تُتخطّى كليًّا تحت ‎decorated‎ (الكانفس).
 *     التصفّح الخاص يصفّر السقوف — مُصرَّح به في المفتّش، لا خلل.
 *   · a11y مستنسَخ ومعمَّم من حوار «مركز الاختصارات» (command-palette):
 *     ‎role="dialog"‎ + ‎aria-modal‎ + مصيدة تركيز على العناصر المُستعلَمة
 *     فعلًا + Escape + استعادة التركيز + إغلاق بضغط الغشاء + قفل تمرير
 *     الجسد + زرّ إغلاق مُصيَّر دائمًا.
 *   · حدث window ‎tah:popup‎ بـ‎{nodeId, open}‎ (C35) — تبثّه أفعال
 *     open_modal/close_modal ومعالجُ ‎POPUP_PREVIEW‎ في الكانفس؛ الفتح
 *     الصريح يتجاوز المحفّز والسقف معًا.
 *   · نيّة الخروج سطح مكتب فقط (‎pointer: fine‎) — التحذير يُعرض غير قابل
 *     للإغلاق بجوار الضابط في المفتّش (ملكية WP3-E2).
 *   · حركة الدخول keyframes في ‎PopupShell.module.css‎ — ملف ساكن كي يفرض
 *     حارس تقليل الحركة كتلة الكشف تلقائيًّا (قانون C33 نفسه).
 */
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import type { POPUP_SCHEMA } from '@tahdheer/registry';
import styles from './PopupShell.module.css';

export type PopupTrigger = 'delay' | 'scroll' | 'exit';
export type PopupFrequency = 'always' | 'session' | 'daily' | 'once';
export type PopupWidth = 'sm' | 'md' | 'lg';

/** خصائص المنبثقة — مفاتيح POPUP_SCHEMA حرفيًّا (لا طبقة ترجمة). */
export interface PopupShellSettings {
  trigger: PopupTrigger;
  /** مهلة الظهور بالثواني (محفّز delay) — 0-60. */
  delaySec: number;
  /** نسبة التمرير المئوية (محفّز scroll) — 10-90. */
  scrollPct: number;
  frequency: PopupFrequency;
  width: PopupWidth;
  dismissLabel: string;
  ariaLabel: string;
}

/*
 * فحص الانجراف وقت الترجمة (عرف PromoBanner — استيراد نوعي، صفر أثر
 * تشغيلي): مفتاح في POPUP_SCHEMA بلا خاصية هنا يكسر البناء.
 */
type PopupSchemaKey = (typeof POPUP_SCHEMA)[number]['key'];
void (null as unknown as PopupSchemaKey satisfies keyof PopupShellSettings);

/** القيم الافتراضية — قيم ‎default‎ في POPUP_SCHEMA نفسها. */
export const POPUP_DEFAULTS: PopupShellSettings = {
  trigger: 'delay',
  delaySec: 3,
  scrollPct: 50,
  frequency: 'session',
  width: 'md',
  dismissLabel: 'إغلاق',
  ariaLabel: 'نافذة منبثقة',
};

/** اسم حدث window المتّفق عليه (C35) — واجهة داخلية واحدة، لا مسار ثانٍ. */
export const TAH_POPUP_EVENT = 'tah:popup';

/** حمولة الحدث — ‎nodeId‎ هو معرّف عقدة المنبثقة في المستند الحالي. */
export interface TahPopupDetail {
  nodeId: string;
  open: boolean;
}

/* ── مزلاج «منبثقة واحدة في آن» — حالة وحدة، لا حالة React ─────────── */

let openPopupId: string | null = null;
const latchWatchers = new Set<(claimant: string) => void>();

function claimLatch(id: string): void {
  openPopupId = id;
  for (const watch of latchWatchers) watch(id);
}

function releaseLatch(id: string): void {
  if (openPopupId === id) openPopupId = null;
}

/* ── قفل تمرير الجسد — عدّاد وحدة يصمد أمام سرقة المزلاج ──────────── */

let scrollLocks = 0;
let bodyOverflowBefore = '';

function lockBodyScroll(): void {
  if (scrollLocks === 0) {
    bodyOverflowBefore = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  }
  scrollLocks += 1;
}

function unlockBodyScroll(): void {
  scrollLocks -= 1;
  if (scrollLocks === 0) document.body.style.overflow = bodyOverflowBefore;
}

/* ── سقوف التكرار — تخزين المتصفح؛ فشله (تصفّح خاص) ⇒ لا سقف ─────── */

const DAY_MS = 86_400_000;

function capBlocks(frequency: PopupFrequency, key: string): boolean {
  try {
    if (frequency === 'session') return sessionStorage.getItem(key) !== null;
    if (frequency === 'once') return localStorage.getItem(key) !== null;
    if (frequency === 'daily') {
      const at = Number(localStorage.getItem(key));
      return Number.isFinite(at) && at > 0 && Date.now() - at < DAY_MS;
    }
  } catch {
    return false;
  }
  return false; // 'always'
}

function recordShown(frequency: PopupFrequency, key: string): void {
  try {
    if (frequency === 'session') sessionStorage.setItem(key, '1');
    else if (frequency === 'once') localStorage.setItem(key, '1');
    else if (frequency === 'daily') localStorage.setItem(key, String(Date.now()));
  } catch {
    /* تصفّح خاص — السقف يسقط بصمت، وهو مُصرَّح به */
  }
}

/* ── تطبيع الإعدادات — دفاع في العمق فوق تطبيع الخادم ─────────────── */

const TRIGGERS: readonly PopupTrigger[] = ['delay', 'scroll', 'exit'];
const FREQUENCIES: readonly PopupFrequency[] = ['always', 'session', 'daily', 'once'];
const WIDTHS: readonly PopupWidth[] = ['sm', 'md', 'lg'];

function pick<T extends string>(list: readonly T[], value: unknown, fallback: T): T {
  return (list as readonly unknown[]).includes(value) ? (value as T) : fallback;
}

function clampNum(value: unknown, min: number, max: number, fallback: number): number {
  return typeof value === 'number' && Number.isFinite(value)
    ? Math.min(max, Math.max(min, value))
    : fallback;
}

/** العناصر القابلة للتركيز — المصيدة تستعلم الموجود فعلًا لا قائمة ثابتة. */
const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export interface PopupShellProps {
  /** معرّف عقدة المنبثقة في المستند — نصف عقد ‎tah:popup‎ وشطر مفتاح السقف. */
  nodeId: string;
  /** مفتاح الصفحة — نطاق مفتاح السقف ‎tah.popup.{pageKey}:{nodeId}‎. */
  pageKey: string;
  /** ‎node.props‎ كما وصلت (جزئية) — الدمج فوق POPUP_DEFAULTS يحدث هنا. */
  settings: Partial<PopupShellSettings>;
  /**
   * الكانفس: لا محفّزات تلقائية ولا سقوف — الفتح الصريح عبر ‎tah:popup‎
   * فقط (معالج ‎POPUP_PREVIEW‎). المسار المنشور لا يمرّره أبدًا.
   */
  decorated?: boolean;
  /** محتوى المنبثقة — لا يُركَّب قبل التحفيز. */
  children?: ReactNode;
}

export function PopupShell({ nodeId, pageKey, settings, decorated = false, children }: PopupShellProps) {
  const trigger = pick(TRIGGERS, settings.trigger, POPUP_DEFAULTS.trigger);
  const delaySec = clampNum(settings.delaySec, 0, 60, POPUP_DEFAULTS.delaySec);
  const scrollPct = clampNum(settings.scrollPct, 10, 90, POPUP_DEFAULTS.scrollPct);
  const frequency = pick(FREQUENCIES, settings.frequency, POPUP_DEFAULTS.frequency);
  const width = pick(WIDTHS, settings.width, POPUP_DEFAULTS.width);
  // زرّ الإغلاق مُصيَّر دائمًا باسم وصفي غير فارغ — قانون §2.7.
  const dismissLabel =
    typeof settings.dismissLabel === 'string' && settings.dismissLabel.trim() !== ''
      ? settings.dismissLabel
      : POPUP_DEFAULTS.dismissLabel;
  const ariaLabel =
    typeof settings.ariaLabel === 'string' && settings.ariaLabel.trim() !== ''
      ? settings.ariaLabel
      : POPUP_DEFAULTS.ariaLabel;

  const [open, setOpen] = useState(false);
  const isOpenRef = useRef(false);
  const panelRef = useRef<HTMLDivElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  /** من كان مركَّزًا قبل الفتح — يُستعاد عند الإغلاق إن بقي في DOM. */
  const restoreRef = useRef<HTMLElement | null>(null);

  useEffect(() => {
    isOpenRef.current = open;
  }, [open]);

  const openNow = useCallback(() => {
    if (isOpenRef.current) return;
    restoreRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    claimLatch(nodeId);
    setOpen(true);
  }, [nodeId]);

  const close = useCallback(() => {
    releaseLatch(nodeId);
    setOpen(false);
  }, [nodeId]);

  // مراقبة المزلاج: ادّعاء منبثقة أخرى (فتح صريح) يغلق هذه فورًا.
  useEffect(() => {
    const watch = (claimant: string) => {
      if (claimant !== nodeId) setOpen(false);
    };
    latchWatchers.add(watch);
    return () => {
      latchWatchers.delete(watch);
      releaseLatch(nodeId);
    };
  }, [nodeId]);

  // عقد ‎tah:popup‎ (C35): الفتح الصريح يتجاوز المحفّز والسقف معًا —
  // open_modal/close_modal في صفحة الزائر و‎POPUP_PREVIEW‎ في الكانفس.
  useEffect(() => {
    const onPopupEvent = (event: Event) => {
      const detail = (event as CustomEvent<Partial<TahPopupDetail>>).detail;
      if (!detail || detail.nodeId !== nodeId) return;
      if (detail.open === true) openNow();
      else close();
    };
    window.addEventListener(TAH_POPUP_EVENT, onPopupEvent);
    return () => window.removeEventListener(TAH_POPUP_EVENT, onPopupEvent);
  }, [nodeId, openNow, close]);

  // محرّكات التحفيز التلقائي — تُطلق مرة واحدة لكل تركيب (قانون
  // play-once)؛ لحظةٌ صادفت مزلاجًا محجوزًا أو سقفًا قائمًا لا تُعاد.
  useEffect(() => {
    if (decorated) return; // الكانفس: المعاينة الصريحة فقط
    const key = `tah.popup.${pageKey}:${nodeId}`;
    let fired = false;
    const fire = () => {
      if (fired) return;
      fired = true;
      if (openPopupId !== null) return; // منبثقة أخرى مفتوحة — قانون الواحدة
      if (capBlocks(frequency, key)) return; // السقف — الفتح التلقائي وحده يُحتسب
      recordShown(frequency, key);
      openNow();
    };

    if (trigger === 'delay') {
      const timer = window.setTimeout(fire, delaySec * 1000);
      return () => window.clearTimeout(timer);
    }

    if (trigger === 'scroll') {
      const onScroll = () => {
        const max = document.documentElement.scrollHeight - window.innerHeight;
        // صفحة غير قابلة للتمرير وحدثَ تمرير ⇒ القاع بلغناه فعليًّا.
        const pct = max > 0 ? (window.scrollY / max) * 100 : 100;
        if (pct >= scrollPct) {
          window.removeEventListener('scroll', onScroll);
          fire();
        }
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      return () => window.removeEventListener('scroll', onScroll);
    }

    // نيّة الخروج — سطح المكتب فقط (لا تُطلق على اللمس أبدًا؛ العقد §7).
    if (!window.matchMedia('(pointer: fine)').matches) return;
    const onMouseOut = (event: MouseEvent) => {
      if (event.relatedTarget === null && event.clientY <= 0) {
        document.removeEventListener('mouseout', onMouseOut);
        fire();
      }
    };
    document.addEventListener('mouseout', onMouseOut);
    return () => document.removeEventListener('mouseout', onMouseOut);
  }, [decorated, trigger, delaySec, scrollPct, frequency, pageKey, nodeId, openNow]);

  // عند الفتح: قفل تمرير الجسد + تركيز أول عنصر قابل للتركيز (زرّ
  // الإغلاق مُصيَّر دائمًا فلا تفرغ القائمة). عند الإغلاق/التفكيك:
  // فكّ القفل واستعادة التركيز لمن كان مركَّزًا إن بقي في DOM.
  useEffect(() => {
    if (!open) return;
    lockBodyScroll();
    const first = panelRef.current?.querySelector<HTMLElement>(FOCUSABLE);
    (first ?? closeRef.current)?.focus();
    return () => {
      unlockBodyScroll();
      const restore = restoreRef.current;
      if (restore !== null && restore.isConnected) restore.focus();
    };
  }, [open]);

  // مصيدة التركيز المعمَّمة: Tab يدور على العناصر المُستعلَمة داخل اللوحة
  // في الاتجاهين؛ Escape يغلق (عرف حوار «مركز الاختصارات» حرفيًّا).
  const onDialogKeyDown = (event: React.KeyboardEvent) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
      return;
    }
    if (event.key !== 'Tab') return;
    const panel = panelRef.current;
    if (panel === null) return;
    const nodes = Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE));
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (first === undefined || last === undefined) return;
    const active = document.activeElement;
    if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    } else if (active === null || !panel.contains(active)) {
      event.preventDefault();
      first.focus();
    }
  };

  // الأبناء غير مركَّبين قبل التحفيز — بنيويًّا لا شرطيًّا داخل العرض.
  if (!open) return null;

  const widthClass =
    width === 'sm' ? (styles.wSm ?? '') : width === 'lg' ? (styles.wLg ?? '') : (styles.wMd ?? '');

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={ariaLabel}
      className={styles.overlay}
      onKeyDown={onDialogKeyDown}
      onMouseDown={(event) => {
        // إغلاق بضغط الغشاء نفسه فقط — لا يتسرّب من ضغطة داخل اللوحة.
        if (event.target === event.currentTarget) close();
      }}
    >
      <div ref={panelRef} className={`${styles.panel} ${widthClass}`}>
        <button ref={closeRef} type="button" className={styles.close} onClick={close}>
          <span aria-hidden="true" className={styles.closeGlyph}>
            ×
          </span>
          {dismissLabel}
        </button>
        <div className={styles.body}>{children}</div>
      </div>
    </div>
  );
}
