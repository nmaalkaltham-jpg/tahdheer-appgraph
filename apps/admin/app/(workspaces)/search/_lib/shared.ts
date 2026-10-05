'use client';
// UNIT: LIB-SHARED
// FILE: shared.ts
// STYLE: none
// PARENT: GLOSSARY, SEARCH-PANEL
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مشتركات مركز ذكاء البحث — أرقام هندية للعدّادات المرجعية (سلوك ar() في
 * اللوحة)، ونسب مئوية، ومضيف الإشعارات الصادقة.
 *
 * عقد الشاشات: كل شاشة مُصدَّرة افتراضيًا بلا خصائص، وتصل إلى الإشعارات عبر
 * ToastContext — الغلاف في page.tsx يوفّر ToastContext. الإشعار تغذية راجعة
 * لفعل حقيقي وقع فقط، لا وعد ولا محاكاة.
 */

import { createContext, useCallback, useEffect, useRef, useState } from 'react';

/** أرقام هندية كما في اللوحة: ar(30) ← «٣٠» والفاصلة العشرية «٫». */
export function arDigits(value: number | string): string {
  return String(value)
    .replace(/[0-9]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.charAt(Number(d)))
    .replace('.', '٫');
}

/** كسر (0..1) ← «12.4%». القيمة الغائبة تعود null — لا صفر يُقرأ قياسًا. */
export function percent(value: number | null | undefined): string | null {
  if (value === null || value === undefined || Number.isNaN(value)) return null;
  return `${(value * 100).toFixed(1)}%`;
}

export interface ToastItem {
  id: string;
  message: string;
}

/**
 * مضيف الإشعارات — سلوك اللوحة حرفيًا: أحدث إشعارين فقط، واختفاء تلقائي بعد
 * ثلاث ثوانٍ، وتنظيف المؤقتات عند التفكيك.
 */
export function useToasts(): { toasts: ToastItem[]; push: (message: string) => void } {
  const [toasts, setToasts] = useState<ToastItem[]>([]);
  const seq = useRef(0);
  const timers = useRef<ReturnType<typeof setTimeout>[]>([]);

  const push = useCallback((message: string) => {
    seq.current += 1;
    const id = `toast-${seq.current}`;
    setToasts((current) => current.concat([{ id, message }]).slice(-2));
    timers.current.push(
      setTimeout(() => setToasts((current) => current.filter((toast) => toast.id !== id)), 3000),
    );
  }, []);

  useEffect(
    () => () => {
      timers.current.forEach(clearTimeout);
    },
    [],
  );

  return { toasts, push };
}

/** تصل الشاشات إلى الإشعارات من هنا — لا تمرير خصائص عبر المبدّل. */
export const ToastContext = createContext<(message: string) => void>(() => {});
