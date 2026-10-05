'use client';
/**
 * «العودة إلى الأعلى» — زرّ أيقونةٍ عامّ يظهر بعد تمرير الصفحة أكثر من
 * شاشتين، فوق كومة الحافّة السفلى (عقد ‎--chrome-bottom-*‎ في globals.css)
 * لا تحتها.
 *
 *  · الصفحة القصيرة لا تبلغ العتبة أصلًا (أقصى تمريرٍ = طولها − الشاشة)، فلا
 *    زرّ عليها بلا شرطٍ ثانٍ.
 *  · التمرير ناعمٌ إلّا مع تقليل الحركة، ثمّ ينتقل التركيز إلى هدف التخطّي
 *    (`#main`) فيبدأ Tab التالي من أوّل المحتوى — لا من زرٍّ اختفى.
 *  · مخفيًّا: ‎visibility: hidden‎ يُخرجه من ترتيب Tab ومن شجرة الوصول معًا.
 */
import { useEffect, useState } from 'react';
import styles from './BackToTop.module.css';

/** العتبة بالشاشات: بعد شاشتين يصير الطريق إلى الترويسة طويلًا. */
const SCREENS = 2;

export function BackToTop() {
  const [shown, setShown] = useState(false);

  useEffect(() => {
    let frame = 0;
    const measure = () => {
      frame = 0;
      setShown(window.scrollY > window.innerHeight * SCREENS);
    };
    const schedule = () => {
      if (frame === 0) frame = requestAnimationFrame(measure);
    };
    measure();
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);
    return () => {
      if (frame !== 0) cancelAnimationFrame(frame);
      window.removeEventListener('scroll', schedule);
      window.removeEventListener('resize', schedule);
    };
  }, []);

  const toTop = () => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
    document.getElementById('main')?.focus({ preventScroll: true });
  };

  return (
    <button
      type="button"
      className={styles.btn}
      data-shown={shown ? 'true' : 'false'}
      aria-label="العودة إلى أعلى الصفحة"
      title="العودة إلى الأعلى"
      onClick={toTop}
    >
      <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M12 19V5" />
        <path d="M6 11l6-6 6 6" />
      </svg>
    </button>
  );
}
