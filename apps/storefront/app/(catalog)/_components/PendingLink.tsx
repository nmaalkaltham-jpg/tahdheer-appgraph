'use client';
/**
 * رابطٌ يعلن انشغاله لحظة النقر — «حمّل المزيد» في الأرشيف.
 *
 * التحميل تنقّلٌ GET كامل (عنوانٌ قابل للمشاركة، يعمل بلا JavaScript)، فبين
 * النقرة ووصول الصفحة التالية لا شيء يقول إنّ الطلب انطلق. هنا يأخذ الرابط
 * `aria-busy` فترسم وصفة الزرّ دوّارها والعرض ثابت، ولا يُنقر مرّتين.
 * العودة بزرّ الرجوع (ذاكرة bfcache) تعيده ساكنًا.
 */
import { useEffect, useState, type MouseEvent, type ReactNode } from 'react';

export function PendingLink({
  href,
  className,
  children,
}: {
  href: string;
  className?: string;
  children: ReactNode;
}) {
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const reset = (event: PageTransitionEvent) => {
      if (event.persisted) setBusy(false);
    };
    window.addEventListener('pageshow', reset);
    return () => window.removeEventListener('pageshow', reset);
  }, []);

  const onClick = (event: MouseEvent<HTMLAnchorElement>) => {
    // فتحٌ في تبويبٍ جديد لا يشغل هذه الصفحة.
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
    if (busy) {
      event.preventDefault();
      return;
    }
    setBusy(true);
  };

  return (
    <a className={className} href={href} onClick={onClick} aria-busy={busy || undefined}>
      <span>{children}</span>
    </a>
  );
}
