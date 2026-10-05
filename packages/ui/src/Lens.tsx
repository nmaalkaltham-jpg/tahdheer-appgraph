'use client';
// UNIT: LENS
// FILE: Lens.tsx
// STYLE: Lens.module.css
// PARENT: UI-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/*
 * Lens — مكبِّرة تتبع المؤشّر فوق صورة.
 *
 * بديل أصليّ لـ‎aceternity/lens‎ (و‎magicui/lens‎ التي حُذفت في التقليم)،
 * مكتوب من الصفر.
 *
 * ليس حاوية: يلفّ صورةً لا أبناءً. لذلك يأخذ ‎src‎/‎alt‎ لا ‎children‎، ومستند
 * الاستوديو يمرّرهما من خاصيّة صورة مملوكة للمستند (‎StudioImage‎) يفحصها
 * الخادم كما يفحص صورة العرض.
 *
 * التكبير بخلفيّة ثانية من الصورة نفسها بحجمٍ مضاعف وموضعٍ يتبع المؤشّر —
 * لا ‎<img>‎ ثانية تُحمَّل: المتصفّح يعيد استعمال المورد نفسه من الذاكرة،
 * فلا طلب شبكة إضافيّ ولا وميض عند أوّل تحويم.
 *
 * تُعطَّل على الأجهزة اللمسيّة (‎pointer: coarse‎): مكبِّرةٌ تتبع مؤشّرًا لا وجود
 * له تعني إصبعًا يحجب ما يُفترض أن يُكبَّر. الصورة تبقى كاملة وسليمة.
 */
import { useRef, useState, type CSSProperties } from 'react';
import styles from './Lens.module.css';

export interface LensProps {
  src: string;
  alt: string;
  /** قوّة التكبير. */
  zoom?: number;
  /** قطر المكبِّرة بالبكسل. */
  size?: number;
  className?: string;
}

export function Lens({ src, alt, zoom = 2, size = 150, className }: LensProps) {
  const ref = useRef<HTMLDivElement>(null);
  const [active, setActive] = useState(false);

  const onMove = (e: React.MouseEvent<HTMLDivElement>) => {
    const el = ref.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const x = ((e.clientX - r.left) / r.width) * 100;
    const y = ((e.clientY - r.top) / r.height) * 100;
    el.style.setProperty('--lens-x', `${x}%`);
    el.style.setProperty('--lens-y', `${y}%`);
  };

  return (
    <div
      ref={ref}
      className={[styles.root, className].filter(Boolean).join(' ')}
      onMouseMove={onMove}
      onMouseEnter={() => setActive(true)}
      onMouseLeave={() => setActive(false)}
      style={
        {
          '--lens-size': `${size}px`,
          '--lens-zoom': String(zoom),
          '--lens-image': `url(${JSON.stringify(src)})`,
        } as CSSProperties
      }
    >
      {/* eslint-disable-next-line @next/next/no-img-element -- مكوّن حزمة لا يعرف مُحسِّن الصور في التطبيق المستضيف. */}
      <img src={src} alt={alt} className={styles.image} />
      <span aria-hidden data-active={active || undefined} className={styles.lens} />
    </div>
  );
}
