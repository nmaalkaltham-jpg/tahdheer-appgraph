'use client';
/**
 * مفتاح المظهر — فاتح · داكن · حسب النظام.
 *
 *  · أزرار راديو **أصليّة** في `role=radiogroup`: الأسهم
 *    تتنقّل بين الخيارات وتحترم RTL بلا شيفرة، وقارئ الشاشة يعلن «١ من ٣».
 *  · كلّ خيارٍ هدفُ لمسٍ ‎44×44‎ — الحقل نفسه يغطّي خيارَه، فالهدف المقيس هو
 *    الحقل لا بكسلٌ مخفيّ.
 *  · نسختان: `bar` زرٌّ دوّار واحد في صفّ أدوات الترويسة (‎≥768‎)، و`panel`
 *    مجموعة الراديو المسمّاة في درج الجوّال. تتزامنان بحدث `THEME_EVENT`.
 *  · في الخادم يُعرض «حسب النظام» مختارًا؛ والاختيار المحفوظ يُقرأ عند الترطيب
 *    (السمة نفسها مكتوبةٌ قبل الرسم، فلا ومضة في الصفحة).
 */
import { useEffect, useId, useSyncExternalStore, type ReactNode } from 'react';
import { THEME_EVENT, applyTheme, readThemePref, setThemePref, type ThemePref } from '../../lib/theme';
import styles from './ThemeToggle.module.css';

const OPTIONS: ReadonlyArray<{ value: ThemePref; label: string; icon: ReactNode }> = [
  {
    value: 'light',
    label: 'فاتح',
    icon: (
      <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round">
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4" />
      </svg>
    ),
  },
  {
    value: 'dark',
    label: 'داكن',
    icon: (
      <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
        <path d="M20.5 14.2A8.5 8.5 0 1 1 9.8 3.5a6.8 6.8 0 0 0 10.7 10.7Z" />
      </svg>
    ),
  },
  {
    value: 'system',
    label: 'حسب النظام',
    icon: (
      <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
        <rect x="3" y="4" width="18" height="12" rx="2" />
        <path d="M8 20h8M12 16v4" />
      </svg>
    ),
  },
];

const NEXT: Record<ThemePref, ThemePref> = { light: 'dark', dark: 'system', system: 'light' };
const optionOf = (value: ThemePref) => OPTIONS.find((o) => o.value === value) ?? OPTIONS[OPTIONS.length - 1] ?? { value, label: value, icon: null };

/** الاختيار مخزنٌ خارجيّ: يتبدّل بحدث النافذة (النسخة الأخرى) أو بالتخزين (تبويبٌ آخر). */
function subscribe(onChange: () => void): () => void {
  window.addEventListener(THEME_EVENT, onChange);
  window.addEventListener('storage', onChange);
  return () => {
    window.removeEventListener(THEME_EVENT, onChange);
    window.removeEventListener('storage', onChange);
  };
}

export function ThemeToggle({ variant }: { variant: 'bar' | 'panel' }) {
  const name = useId();
  const pref = useSyncExternalStore(subscribe, readThemePref, () => 'system' as ThemePref);

  /* السمة تتبع الاختيار (ولو تبدّل من تبويبٍ آخر)، و«حسب النظام» يتبع النظام حيًّا. */
  useEffect(() => {
    applyTheme(readThemePref()); // لا `pref`: أوّل التزامٍ يحمل لقطة الخادم «system»
    if (readThemePref() !== 'system') return undefined;
    const query = window.matchMedia('(prefers-color-scheme: dark)');
    const follow = () => applyTheme(readThemePref());
    query.addEventListener('change', follow);
    return () => query.removeEventListener('change', follow);
  }, [pref]);

  const group = (
    <div
      className={`${styles.group} ${styles.segments}`}
      role="radiogroup"
      aria-labelledby={`${name}-label`}
    >
      {OPTIONS.map((option) => (
        <label key={option.value} className={styles.option}>
          <input
            className={styles.input}
            type="radio"
            name={name}
            value={option.value}
            checked={pref === option.value}
            onChange={() => setThemePref(option.value)}
          />
          {option.icon}
          <span className={styles.text}>{option.label}</span>
        </label>
      ))}
    </div>
  );

  if (variant === 'bar') {
    /* صفّ الترويسة: زرٌّ واحد ‎46×46‎ يدور فاتح ← داكن ← حسب النظام — ثلاثة خيارات
       بعرض ‎134px‎ كانت تطوي الصفّ إلى سطرين عند ‎1440‎. الاختيار الصريح في الدرج. */
    const current = optionOf(pref);
    const next = optionOf(NEXT[pref]);
    return (
      <button
        type="button"
        className={styles.cycle}
        aria-label={`المظهر: ${current.label} — التبديل إلى ${next.label}`}
        title={`المظهر: ${current.label}`}
        onClick={() => setThemePref(next.value)}
      >
        {current.icon}
      </button>
    );
  }
  return (
    <div className={styles.panel}>
      <p className={styles.legend} id={`${name}-label`}>
        المظهر
      </p>
      {group}
    </div>
  );
}
