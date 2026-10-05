'use client';
// UNIT: BEAMBACKDROP
// FILE: BeamBackdrop.tsx
// STYLE: BeamBackdrop.module.css
// PARENT: UI-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/*
 * BeamBackdrop — خلفيّة أشعّة متدرّجة بطيئة.
 *
 * بديل أصليّ لـ‎aceternity/background-beams‎، مكتوب من الصفر. حاوية استوديو:
 * تلفّ أبناءها وتضع خلفها الأشعّة.
 *
 * الفرق الجوهريّ عن الأصل: ذاك يرسم عشرات مسارات SVG بحركة دائمة (صُنِّف
 * «كلفة عالية» في السجلّ). هذا ثلاث طبقات تدرّج CSS تتحرّك بـ‎transform‎
 * وحده — المُركِّب يتولّاها على وحدة الرسم بلا إعادة تخطيط ولا JavaScript
 * في حلقة الرسم. الأثر البصريّ نفسه بكلفةٍ أقلّ مرتبة.
 *
 * ولوحته لا تُثبَّت داكنة كما كان الأصل: الألوان من رموز النظام، فتصلح فوق
 * سطحٍ فاتح وداكن معًا. تثبيت لوحة داكنة كان يجعله غير صالح لأكثر أسطح اللوحة.
 *
 * ‎prefers-reduced-motion‎: الأشعّة تثبت ولا تتحرّك. تبقى ظاهرة — هي خلفيّة
 * التصميم لا حركته.
 */
import type { CSSProperties, ReactNode } from 'react';
import styles from './BeamBackdrop.module.css';

export interface BeamBackdropProps {
  children?: ReactNode;
  /** مدّة الدورة الكاملة بالثواني — أبطأ = أهدأ. */
  duration?: number;
  /** شدّة الأشعّة بين 0 و1. */
  intensity?: number;
  className?: string;
}

export function BeamBackdrop({
  children,
  duration = 18,
  intensity = 0.5,
  className,
}: BeamBackdropProps) {
  return (
    <div
      className={[styles.root, className].filter(Boolean).join(' ')}
      style={
        {
          '--beam-duration': `${duration}s`,
          '--beam-intensity': String(intensity),
        } as CSSProperties
      }
    >
      <span aria-hidden className={`${styles.beam} ${styles.one}`} />
      <span aria-hidden className={`${styles.beam} ${styles.two}`} />
      <span aria-hidden className={`${styles.beam} ${styles.three}`} />
      {children ? <div className={styles.content}>{children}</div> : null}
    </div>
  );
}
