'use client';
// UNIT: TOAST
// FILE: Toast.tsx
// STYLE: Toast.module.css
// PARENT: UI-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * Toast primitives — the dark card of Tahdheer - Account Portal.dc.html.
 *
 * CONTRACT:
 * - The viewport is a polite live region: role="status" aria-live="polite".
 * - MAX 2 toasts stacked at once — TOAST_LIMIT is enforced structurally
 *   (the viewport slices to the newest TOAST_LIMIT entries).
 * - Auto-dismiss runs at TOAST_DURATION_MS (3s, inside the 2.8–3.2s spec
 *   window) and PAUSES while the toast is hovered or holds focus, so the
 *   dismiss button is always reachable (WCAG 2.2.1).
 */
import { useEffect, useRef, useState, type ReactNode } from 'react';
import styles from './Toast.module.css';

/** Max simultaneously stacked toasts (spec: max 2). */
export const TOAST_LIMIT = 2;

/** Auto-dismiss delay in ms (spec window 2.8–3.2s). */
export const TOAST_DURATION_MS = 3000;

export type ToastTone = 'status' | 'error';

export interface ToastData {
  id: string;
  tone: ToastTone;
  message: string;
}

export interface ToastProps extends ToastData {
  onDismiss?: (id: string) => void;
  /** Auto-dismiss delay; pass 0 to keep the toast until it is dismissed. */
  durationMs?: number;
}

export function Toast({ id, tone, message, onDismiss, durationMs = TOAST_DURATION_MS }: ToastProps) {
  const [held, setHeld] = useState(false);
  const dismissRef = useRef(onDismiss);
  const canDismiss = onDismiss !== undefined;

  useEffect(() => {
    dismissRef.current = onDismiss;
  }, [onDismiss]);

  useEffect(() => {
    if (durationMs <= 0 || held || !canDismiss) return;
    const timer = setTimeout(() => dismissRef.current?.(id), durationMs);
    return () => clearTimeout(timer);
  }, [id, durationMs, held, canDismiss]);

  return (
    <div
      className={`${styles.toast} ${tone === 'error' ? styles.error : ''}`}
      onMouseEnter={() => setHeld(true)}
      onMouseLeave={() => setHeld(false)}
      onFocus={() => setHeld(true)}
      onBlur={() => setHeld(false)}
    >
      <span className={styles.message}>{message}</span>
      {onDismiss ? (
        <button
          type="button"
          className={styles.dismiss}
          aria-label="إغلاق التنبيه"
          onClick={() => onDismiss(id)}
        >
          <span aria-hidden="true">×</span>
        </button>
      ) : null}
    </div>
  );
}

export interface ToastViewportProps {
  toasts: readonly ToastData[];
  onDismiss?: (id: string) => void;
  /** Auto-dismiss delay applied to every toast; 0 disables it. */
  durationMs?: number;
  /** Extra content rendered inside the live region (rarely needed). */
  children?: ReactNode;
}

/**
 * Live-region viewport. Present in the DOM from mount so screen readers
 * announce additions politely. Enforces TOAST_LIMIT (newest win).
 */
export function ToastViewport({ toasts, onDismiss, durationMs, children }: ToastViewportProps) {
  const visible = toasts.slice(-TOAST_LIMIT);
  return (
    <div className={styles.viewport} role="status" aria-live="polite">
      {visible.map((t) => (
        <Toast key={t.id} {...t} onDismiss={onDismiss} durationMs={durationMs} />
      ))}
      {children}
    </div>
  );
}
