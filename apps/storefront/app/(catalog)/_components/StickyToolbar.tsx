'use client';
/**
 * شريط أدوات الأرشيف الملتصق — [تصفية] + عدد النتائج + تبويبات الترتيب تبقى تحت
 * الترويسة أثناء التمرير، وترتفع ظلًّا خفيفًا (‎--e-1‎) حين تلتصق فعلًا.
 *
 * الترويسة ملتصقةٌ في الجوّال وحده (SiteHeader.module.css ‎≤767px‎) وارتفاعها دالّةُ
 * التفافها، فلا ثابت يلاحقه: يُقاس `[data-theme-component=site-header]` —
 * إن كان `position: sticky|fixed` فارتفاعه هو نقطة الالتصاق، وإلّا فالصفر.
 * القيمتان (`--catalog-stick-top` ونقطة الالتصاق + ارتفاع الشريط) تُنشران على الجذر
 * ليشتقّ منهما `html` حشوَ التمرير: العنصر المركَّز لا يختبئ تحت الشريط (‎WCAG 2.4.11‎).
 * الحافّة السفلى لا تُمسّ: الشريط في أعلى الشاشة، وعقد `data-bottom-chrome` لأسفلها.
 *
 * بلا JavaScript يبقى الشريط ملتصقًا عند الصفر — والترويسة ملتصقة فوقه في الجوّال
 * بطبقةٍ أعلى (‎--z-header‎)، فلا يُحجب شيء؛ الذي يغيب هو الظلّ وحده.
 */
import { useEffect, useRef, useState, type ReactNode } from 'react';
import styles from './catalog.module.css';

const TOP = '--catalog-stick-top';
const BLOCK = '--catalog-stick-block';

export function StickyToolbar({ children }: { children: ReactNode }) {
  const barRef = useRef<HTMLDivElement>(null);
  const [stuck, setStuck] = useState(false);

  useEffect(() => {
    const bar = barRef.current;
    if (!bar) return;
    const root = document.documentElement;
    const header = document.querySelector<HTMLElement>('[data-theme-component="site-header"]');
    let observer: IntersectionObserver | null = null;
    let top = -1;

    const observe = (offset: number) => {
      observer?.disconnect();
      /*
       * الشريط الملتصق يقف عند `offset` بالضبط، فهامشُ جذرٍ يقصّ ‎1px‎ فوقه يجعله
       * «غير مرئيٍّ كلّه» ما دام ملتصقًا. وشرطُ القمّة يستبعد شريطًا ما يزال تحت الشاشة.
       */
      observer = new IntersectionObserver(
        ([entry]) => {
          if (entry) setStuck(entry.intersectionRatio < 1 && entry.boundingClientRect.top <= offset + 1);
        },
        { rootMargin: `${-offset - 1}px 0px 0px 0px`, threshold: [1] },
      );
      observer.observe(bar);
    };

    const measure = () => {
      const position = header ? getComputedStyle(header).position : 'static';
      const next = header && (position === 'sticky' || position === 'fixed') ? Math.round(header.offsetHeight) : 0;
      root.style.setProperty(TOP, `${next}px`);
      root.style.setProperty(BLOCK, `${next + Math.round(bar.offsetHeight)}px`);
      if (next !== top) {
        top = next;
        observe(next);
      }
    };

    measure();
    const resize = new ResizeObserver(measure);
    resize.observe(bar);
    if (header) resize.observe(header);
    window.addEventListener('resize', measure);
    return () => {
      resize.disconnect();
      observer?.disconnect();
      window.removeEventListener('resize', measure);
      root.style.removeProperty(TOP);
      root.style.removeProperty(BLOCK);
    };
  }, []);

  return (
    <div ref={barRef} className={styles.toolbar} data-stuck={stuck ? '' : undefined}>
      {children}
    </div>
  );
}
