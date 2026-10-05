'use client';
// UNIT: SPOTLIGHTLAYER
// FILE: SpotlightLayer.tsx
// STYLE: SpotlightLayer.module.css
// PARENT: UI-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/*
 * SpotlightLayer — طبقة ضوء تتبع المؤشّر.
 *
 * بديل أصليّ لـ‎aceternity/spotlight‎ و‎following-pointer‎ و‎glowing-effect‎، كُتب
 * من الصفر. حاوية استوديو: تلفّ أبناءها ولا تحمل محتوى خاصًّا بها.
 *
 * ‎color‎ خاصيّة برمجيّة لا ضابط مؤلِّف: لونٌ حرّ في يد المؤلّف يكسر انضباط
 * الرموز، فالمفتّش لا يعرضه والافتراض رمز العلامة.
 *
 * التنفيذ متغيّرَا CSS يحدَّثان على العنصر مباشرةً لا حالةُ React: تحديث الحالة
 * في كلّ ‎mousemove‎ يعيد تصيير الشجرة عشرات المرّات في الثانية. الكتابة على
 * ‎style‎ تبقى في المُركِّب ولا تمسّ React أصلًا.
 *
 * الإحداثيّات نسبيّة إلى الحاوية (نسبة مئويّة)، فلا تنقلب مع ‎dir‎ ولا تحتاج
 * حسابًا خاصًّا بـRTL: ‎50%‎ هو المنتصف في الاتجاهين.
 *
 * ‎prefers-reduced-motion‎: الطبقة تثبت في المنتصف ولا تتبع المؤشّر. الضوء
 * يبقى ظاهرًا — إخفاؤه كان سيغيّر التصميم لا الحركة وحدها.
 */
import { useRef, type CSSProperties, type ReactNode } from 'react';
import styles from './SpotlightLayer.module.css';

export interface SpotlightLayerProps {
  /** الأبناء — حاوية بلا أبناء تُصيَّر فارغة، والمحرّر يحذّر منها. */
  children?: ReactNode;
  /** نصف قطر الضوء بالبكسل. */
  size?: number;
  /** لون الضوء — أيّ قيمة لون CSS. الافتراضي رمز العلامة. */
  color?: string;
  /** شدّة الضوء بين 0 و1. */
  opacity?: number;
  className?: string;
}

export function SpotlightLayer({
  children,
  size = 340,
  color = 'var(--teal-600)',
  opacity = 0.16,
  className,
}: SpotlightLayerProps) {
  const ref = useRef<HTMLDivElement>(null);

  const onMove = (e: React.MouseEvent<HTMLDivElement>) => {
    const el = ref.current;
    if (!el) return;
    const r = el.getBoundingClientRect();
    el.style.setProperty('--sl-x', `${((e.clientX - r.left) / r.width) * 100}%`);
    el.style.setProperty('--sl-y', `${((e.clientY - r.top) / r.height) * 100}%`);
  };

  const reset = () => {
    const el = ref.current;
    if (!el) return;
    el.style.setProperty('--sl-x', '50%');
    el.style.setProperty('--sl-y', '50%');
  };

  return (
    <div
      ref={ref}
      onMouseMove={onMove}
      onMouseLeave={reset}
      className={[styles.root, className].filter(Boolean).join(' ')}
      style={
        {
          '--sl-size': `${size}px`,
          '--sl-color': color,
          '--sl-opacity': String(opacity),
        } as CSSProperties
      }
    >
      <span aria-hidden className={styles.glow} />
      <div className={styles.content}>{children}</div>
    </div>
  );
}
