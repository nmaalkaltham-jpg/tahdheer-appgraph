'use client';
/**
 * قطعُ شريحة العرض الحيّة: العدّاد التنازليّ ونسخ رمز القسيمة.
 *
 * العدّاد لا يُصيَّر إلّا بتاريخ انتهاءٍ حقيقيّ من `/home/promo` (`ends_at`)
 * — القالب يرسم «٠٢ · ١٤ · ٣٦» عيّنةً، ولا يُنسخ. وحين ينقضي الموعد يختفي
 * العدّاد بدل أن يقف على أصفار.
 */
import { useEffect, useRef, useState } from 'react';
import { SPEEDS } from '@tahdheer/design-system/motion';
import styles from './design.module.css';

const MINUTE = 60_000;

function parts(endsAt: number, now: number): { d: number; h: number; m: number } | null {
  const left = endsAt - now;
  if (left <= 0) return null;
  const minutes = Math.floor(left / MINUTE);
  return { d: Math.floor(minutes / 1440), h: Math.floor((minutes % 1440) / 60), m: minutes % 60 };
}

const pad = (n: number) => String(n).padStart(2, '0');

export function Countdown({ endsAt }: { endsAt: string }) {
  const end = new Date(endsAt).getTime();
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 15_000);
    return () => window.clearInterval(timer);
  }, []);

  if (Number.isNaN(end)) return null;
  const left = parts(end, now);
  if (!left) return null;

  const cells: [number, string][] = [
    [left.d, 'يوم'],
    [left.h, 'ساعة'],
    [left.m, 'دقيقة'],
  ];

  return (
    <div className={styles.countWrap}>
      <span className={styles.countLabel}>ينتهي العرض خلال</span>
      <time className={styles.countdown} dateTime={endsAt} suppressHydrationWarning>
        {cells.map(([value, unit]) => (
          <span key={unit} className={styles.countCell}>
            <b className={styles.countValue} suppressHydrationWarning>
              {pad(value)}
            </b>
            <span className={styles.countUnit}>{unit}</span>
          </span>
        ))}
      </time>
    </div>
  );
}

/** رمز القسيمة في خانةٍ بطراز خانات العدّاد، مع نسخٍ حقيقيّ إلى الحافظة. */
export function CopyCode({ code }: { code: string }) {
  const [copied, setCopied] = useState(false);
  const ref = useRef<HTMLElement>(null);

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
      window.setTimeout(() => setCopied(false), SPEEDS.verySlow);
    } catch {
      /* لا وصول إلى الحافظة: يُحدَّد النصّ ليُنسخ يدويًّا. */
      const node = ref.current;
      const selection = window.getSelection();
      if (!node || !selection) return;
      const range = document.createRange();
      range.selectNodeContents(node);
      selection.removeAllRanges();
      selection.addRange(range);
    }
  };

  return (
    <div className={styles.countdown}>
      <span className={styles.countCell}>
        <code className={styles.countValue} ref={ref}>
          <bdi dir="ltr">{code}</bdi>
        </code>
        <span className={styles.countUnit}>رمز القسيمة</span>
      </span>
      <button type="button" className={styles.copyBtn} onClick={copy} data-copied={copied ? 'true' : undefined}>
        {copied ? 'نُسخ ✓' : 'نسخ الرمز'}
      </button>
      <span role="status" aria-live="polite" className={styles.srOnly}>
        {copied ? 'نُسخ رمز القسيمة' : ''}
      </span>
    </div>
  );
}
