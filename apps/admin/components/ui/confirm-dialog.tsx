'use client';
// UNIT: CONFIRM-DIALOG
// FILE: confirm-dialog.tsx
// STYLE: confirm-dialog.module.css
// PARENT: INTEGRATIONS-PANEL, PAGE-SHIPPING
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * حوار تأكيد — للأفعال التي لا تُرَدّ.
 *
 * ─── ما يجعله تأكيدًا حقيقيًّا ───
 *
 * «هل أنت متأكّد؟» بلا ذكرِ ما سيقع سؤالٌ بلا معنى: يُنقر «نعم» بالعادة.
 * فالحوار هنا يُلزم بذكر **الأثر** (`consequence`) — ماذا يتغيّر، وعلى كم
 * صفًّا، وهل يُرَدّ.
 *
 * ─── وحلقة التركيز ───
 *
 * التركيز يدخل عند الفتح ويعود إلى ما فتحه عند الإغلاق، ولا يخرج من الحوار
 * بالـTab. بدون ذلك يتجوّل مستعمل لوحة المفاتيح في صفحةٍ محجوبةٍ بصريًّا
 * ولا يجد زرّ التأكيد.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import styles from './confirm-dialog.module.css';

export function ConfirmDialog({
  open,
  title,
  consequence,
  confirmLabel = 'تأكيد',
  cancelLabel = 'إلغاء',
  tone = 'danger',
  onConfirm,
  onCancel,
}: {
  open: boolean;
  title: string;
  /** ماذا يقع بالضبط — لا «هل أنت متأكّد؟» وحدها. */
  consequence: string;
  confirmLabel?: string;
  cancelLabel?: string;
  tone?: 'danger' | 'default';
  onConfirm: () => Promise<void> | void;
  onCancel: () => void;
}) {
  const dialogRef = useRef<HTMLDivElement | null>(null);
  const openerRef = useRef<HTMLElement | null>(null);
  const confirmRef = useRef<HTMLButtonElement | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!open) return;

    openerRef.current = document.activeElement as HTMLElement | null;
    confirmRef.current?.focus();

    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onCancel();
        return;
      }

      if (e.key !== 'Tab' || dialogRef.current === null) return;

      /* حلقةٌ مغلقة: آخرُ عنصرٍ يعيد إلى الأوّل والعكس. */
      const focusable = dialogRef.current.querySelectorAll<HTMLElement>(
        'button:not(:disabled), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
      );
      if (focusable.length === 0) return;

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last?.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first?.focus();
      }
    };

    window.addEventListener('keydown', onKey);
    return () => {
      window.removeEventListener('keydown', onKey);
      openerRef.current?.focus();
    };
  }, [open, onCancel]);

  const confirm = useCallback(() => {
    // المنع أثناء التنفيذ: نقرتان تُنفّذان الفعل مرّتين.
    setBusy(true);
    void Promise.resolve(onConfirm()).finally(() => setBusy(false));
  }, [onConfirm]);

  if (!open) return null;

  /*
   * إلى `<body>`: الحوار يُفتح غالبًا من داخل لوحٍ زجاجيّ (`.panel` يحمل
   * `backdrop-filter`)، وذاك يحبس `position: fixed` في صندوق اللوح — فيصير
   * الغشاء بحجم اللوح لا الشاشة.
   */
  return createPortal(
    <div className={styles.backdrop} onPointerDown={(e) => e.target === e.currentTarget && onCancel()}>
      <div
        ref={dialogRef}
        className={styles.dialog}
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="confirm-title"
        aria-describedby="confirm-body"
      >
        <h2 id="confirm-title" className={styles.title}>
          {title}
        </h2>
        <p id="confirm-body" className={styles.body}>
          {consequence}
        </p>
        <div className={styles.actions}>
          <button type="button" className={styles.cancel} onClick={onCancel} disabled={busy}>
            {cancelLabel}
          </button>
          <button
            ref={confirmRef}
            type="button"
            className={tone === 'danger' ? styles.confirmDanger : styles.confirm}
            onClick={confirm}
            disabled={busy}
          >
            {busy ? 'جارٍ…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>,
    document.body,
  );
}
