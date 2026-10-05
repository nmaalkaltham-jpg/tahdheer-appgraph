'use client';
// UNIT: TRACINGBEAM
// FILE: TracingBeam.tsx
// STYLE: TracingBeam.module.css
// PARENT: UI-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/*
 * TracingBeam — خيط يمتدّ بجانب المحتوى بمقدار ما قُرئ منه.
 *
 * حاوية استوديو: تلفّ أبناءها ولا تحمل محتوى خاصًّا بها.
 *
 * بلا مكتبة حركة — وهذا شرط لا تفضيل. هذه الحزمة هي ما يصيّره متجر العملاء،
 * وقانونها المكتوب في ‎StoreMotion‎ صريح. النسخة الأولى كانت تستعمل
 * ‎useScroll‎/‎useSpring‎ من ‎motion‎، فكان انتقالها إلى هنا يجرّ محرّك حركة
 * كاملًا إلى حزمة الزائر من أجل خيطٍ يمتلئ.
 *
 * البديل: مستمع تمرير مخنوق بـ‎requestAnimationFrame‎ يكتب متغيّر CSS واحدًا
 * (‎--beam-progress‎)، والامتلاء ‎scaleY‎ في الورقة. حلقة الرسم لا تعمل إلّا
 * أثناء التمرير، وتتوقّف من نفسها.
 *
 * ‎passive: true‎ على المستمع: بدونه يفترض المتصفّح أنّنا قد نلغي التمرير
 * فيؤخّره حتى ينتهي المعالج — تقطيعٌ محسوس على الجوّال.
 *
 * الخيط على الحافّة المنطقيّة (‎inset-inline-start‎) فينتقل مع اتّجاه الكتابة،
 * و‎aria-hidden‎ لأنّه يكرّر ما يعرفه القارئ من موضعه في المستند.
 *
 * ‎prefers-reduced-motion‎: يمتلئ كاملًا ولا يتتبّع — لا يختفي.
 */
import { useEffect, useRef, type ReactNode } from 'react';
import styles from './TracingBeam.module.css';

export interface TracingBeamProps {
  children?: ReactNode;
  className?: string;
}

export function TracingBeam({ children, className }: TracingBeamProps) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const el = ref.current;
    if (el === null) return;
    if (typeof window === 'undefined') return;

    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    if (reduced) {
      el.style.setProperty('--beam-progress', '1');
      return;
    }

    let frame = 0;
    const measure = () => {
      frame = 0;
      const r = el.getBoundingClientRect();
      const vh = window.innerHeight || 1;
      /*
       * صفر عند وصول أعلى العنصر إلى ٩٠٪ من الشاشة، وواحد عند بلوغ أسفله
       * ٤٠٪ منها — نفس المدى الذي كان يعبّر عنه ‎offset‎ في النسخة السابقة،
       * فلا يتغيّر السلوك بتغيّر التنفيذ.
       */
      const start = vh * 0.9;
      const end = -r.height + vh * 0.4;
      const span = start - end || 1;
      const p = (start - r.top) / span;
      el.style.setProperty('--beam-progress', String(Math.min(1, Math.max(0, p))));
    };

    const onScroll = () => {
      if (frame === 0) frame = window.requestAnimationFrame(measure);
    };

    measure();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    return () => {
      if (frame !== 0) window.cancelAnimationFrame(frame);
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onScroll);
    };
  }, []);

  return (
    <div ref={ref} className={[styles.root, className].filter(Boolean).join(' ')}>
      <div aria-hidden className={styles.track}>
        <div className={styles.beam} />
      </div>
      <div className={styles.content}>{children}</div>
    </div>
  );
}
