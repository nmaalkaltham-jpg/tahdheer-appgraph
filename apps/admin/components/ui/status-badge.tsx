'use client';
// UNIT: STATUS-BADGE
// FILE: status-badge.tsx
// STYLE: status-badge.module.css
// PARENT: COMMAND-CENTER, INTEGRATIONS-PANEL, LIST-PANEL, PAGE-DASHBOARD, PAGE-OPERATIONS-INVENTORY, PAGE-ORDERS +2
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * شارة حالة — نصٌّ ونقطةٌ ولون، بهذا الترتيب.
 *
 * اللون آخر الإشارات لا أوّلها: من لا يميّزه يقرأ النصّ ويرى شكل النقطة.
 * ولذلك لا شارةَ بلا تسمية، ولا تسميةَ تُختصر إلى حرف.
 */

import styles from './status-badge.module.css';

export type StatusTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

/**
 * نبرة كلّ حالة — مكتوبةٌ مرّة هنا.
 *
 * كانت كلّ شاشةٍ تلوّن حالاتها بيدها، فصار «مسترد» رماديًّا في صفحةٍ وأحمر
 * في أخرى — والمشغّل يقرأ اللون قبل الكلمة.
 */
const ORDER_TONES: Record<string, StatusTone> = {
  PENDING: 'neutral',
  AWAITING_PAYMENT: 'warning',
  PAID: 'success',
  PROCESSING: 'info',
  COMPLETED: 'success',
  FAILED: 'danger',
  CANCELLED: 'neutral',
  REFUNDED: 'warning',
  PARTIALLY_REFUNDED: 'warning',
};

export function orderTone(status: string): StatusTone {
  return ORDER_TONES[status] ?? 'neutral';
}

export function StatusBadge({ label, tone = 'neutral' }: { label: string; tone?: StatusTone }) {
  return (
    <span className={styles.badge} data-tone={tone}>
      <span className={styles.dot} aria-hidden="true" />
      {label}
    </span>
  );
}
