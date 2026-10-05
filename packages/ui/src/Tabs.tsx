'use client';
// UNIT: TABS
// FILE: Tabs.tsx
// STYLE: Tabs.module.css
// PARENT: UI-INDEX
// USES: DISCLOSUREBODY
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * Tabs — «التبويبات» tah/tabs.1903 (خطّ تحتي) وtah/tabs.1908 (جانبية) —
 * عقد المرحلة ٣٫١٠. النسق من مدخل السجل (C107)، والقيم من لوح
 * «Tabs & Accordion» 1a/1c حرفيًّا.
 *
 * عقد السلوك (C111 — MIP §266):
 *   · ‎role=tablist/tab/tabpanel‎ مع tabindex متنقّل: التبويب النشط وحده في
 *     ترتيب Tab، والأسهم تتنقّل بين البقيّة وتفعّلها فورًا.
 *   · الأسهم منطقية: الاتّجاه يُحسب من ‎direction‎ المحلول للقائمة نفسها —
 *     في RTL السهم الأيسر «التالي» — لا من لغة الصفحة ولا من افتراض.
 *   · Home/End إلى الطرفين.
 *   · المحتوى **دائمًا في DOM**: اللوحات غير النشطة ‎hidden‎ لا محذوفة، فيراها
 *     محرّك البحث والطابعة وقارئ الرابط.
 *   · الوصول العميق: ‎#<domId>-panel-<i>‎ يُقرأ عند التركيب ولا يُكتَب أبدًا
 *     (كتابة hash داخل إطار الكانفس تعبث برابط الإطار).
 *
 * عقيدة الصدق (C110): بلا تبويبات لا شيء يُصيَّر — قائمة فارغة كذبة.
 */
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { DisclosureBody } from './DisclosureBody';
import styles from './Tabs.module.css';

export type TabsLayout = 'underline' | 'side';

export interface TabItem {
  label: string;
  text?: string;
  points?: readonly string[];
}

export interface TabsProps {
  items: readonly TabItem[];
  /** النسق من المدخل: ‎underline‎ (1a) أو ‎side‎ (1c). */
  layout?: TabsLayout;
  /** معرّف فريد للمكوّن — من معرّف العقدة (قرار C55). */
  domId?: string;
  /** تسمية قائمة التبويبات للقارئ الصوتي. */
  ariaLabel?: string;
}

const HASH_INDEX = /-panel-(\d+)$/;

export function Tabs({ items, layout = 'underline', domId, ariaLabel }: TabsProps) {
  const base = domId ?? 'tabs';
  const [active, setActive] = useState(0);
  const listRef = useRef<HTMLDivElement>(null);
  const tabRefs = useRef<(HTMLButtonElement | null)[]>([]);

  /* الوصول العميق بالرابط — قراءة فقط (C111). */
  useEffect(() => {
    const hash = window.location.hash;
    if (!hash.startsWith(`#${base}-panel-`)) return;
    const match = HASH_INDEX.exec(hash);
    const index = match ? Number(match[1]) : NaN;
    if (Number.isInteger(index) && index >= 0 && index < items.length) setActive(index);
  }, [base, items.length]);

  if (items.length === 0) return null;

  const focusAndActivate = (index: number) => {
    const next = (index + items.length) % items.length;
    setActive(next);
    tabRefs.current[next]?.focus();
  };

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    const list = listRef.current;
    const rtl = list !== null && getComputedStyle(list).direction === 'rtl';
    let target: number | null = null;
    switch (event.key) {
      case 'ArrowRight':
        target = active + (rtl ? -1 : 1);
        break;
      case 'ArrowLeft':
        target = active + (rtl ? 1 : -1);
        break;
      case 'ArrowDown':
        target = active + 1;
        break;
      case 'ArrowUp':
        target = active - 1;
        break;
      case 'Home':
        target = 0;
        break;
      case 'End':
        target = items.length - 1;
        break;
      default:
        return;
    }
    event.preventDefault();
    focusAndActivate(target);
  };

  return (
    <div
      className={`${styles.root} ${layout === 'side' ? styles.side : styles.underline}`}
      data-theme-component="tabs"
    >
      {/* الخطّ الفاصل على الغلاف لا على المُنزلِق كي لا يتلاشى معه (C113). */}
      <div className={styles.bar}>
        <div className={styles.list} role="tablist" aria-label={ariaLabel} ref={listRef} onKeyDown={onKeyDown}>
          {items.map((item, index) => (
            <button
              key={`${index}:${item.label}`}
              type="button"
              role="tab"
              id={`${base}-tab-${index}`}
              className={styles.tab}
              aria-selected={index === active}
              aria-controls={`${base}-panel-${index}`}
              tabIndex={index === active ? 0 : -1}
              onClick={() => setActive(index)}
              ref={(el) => {
                tabRefs.current[index] = el;
              }}
            >
              {item.label}
            </button>
          ))}
        </div>
      </div>
      {items.map((item, index) => (
        <div
          key={`${index}:${item.label}`}
          role="tabpanel"
          id={`${base}-panel-${index}`}
          className={styles.panel}
          aria-labelledby={`${base}-tab-${index}`}
          hidden={index !== active}
          tabIndex={0}
        >
          <DisclosureBody text={item.text} points={item.points} />
        </div>
      ))}
    </div>
  );
}
