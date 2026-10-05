// UNIT: MEDIA-STATE
// FILE: media-state.tsx
// STYLE: media-state.module.css
// PARENT: LIBRARY-PANEL, OPTIMIZE-PANEL, _INSPECT-DIALOG, _MEDIA-CARD, _SCAN-PANELS
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * الحالات الصريحة لشاشتَي الوسائط (MIP §1.3).
 *
 * ثلاث حالات لا رابع لها، وكلٌّ تُسمّي ما ينقصها بالاسم:
 *   NOT_CONFIGURED    — لا نقطة نهاية أصلًا؛ لا يمكن قياس هذا.
 *   NO_DATA           — النقطة موجودة وردّت فارغًا؛ الفراغ هو الجواب الصادق.
 *   INSUFFICIENT_DATA — المُدخلات ناقصة، فالاستنتاج ممتنع لا مؤجَّل.
 *
 * الصفر الذي يعني «لا ربط» كذب، فلا تُصدِر هذه المكوّنات صفرًا أبدًا.
 */

import type { ReactNode } from 'react';
import styles from './media-state.module.css';

export type HonestState = 'NOT_CONFIGURED' | 'NO_DATA' | 'INSUFFICIENT_DATA';

const STATE_LABEL: Record<HonestState, string> = {
  NOT_CONFIGURED: 'غير مُهيَّأ',
  NO_DATA: 'لا بيانات',
  INSUFFICIENT_DATA: 'بيانات غير كافية',
};

/**
 * لوح حالة كامل: الوسم ثمّ ما الذي لا يمكن عرضه ولماذا، ثمّ النقاط الغائبة
 * بالاسم. `missing` قد تكون فارغة حين يكون النقص في المُدخلات لا في النقطة.
 */
export function HonestPanel({
  state,
  title,
  missing = [],
  children,
}: {
  state: HonestState;
  title: string;
  missing?: readonly string[];
  children: ReactNode;
}) {
  return (
    <section className={`${styles.panel} ${styles[state] ?? ''}`} role="status" aria-live="polite">
      <div className={styles.head}>
        <bdi dir="ltr" className={styles.token}>
          {state}
        </bdi>
        <span className={styles.stateLabel}>{STATE_LABEL[state]}</span>
        <h3 className={styles.title}>{title}</h3>
      </div>
      <div className={styles.body}>{children}</div>
      {missing.length > 0 ? (
        <ul className={styles.missing}>
          {missing.map((endpoint) => (
            <li key={endpoint}>
              <span className={styles.missingLabel}>غائبة</span>
              <bdi dir="ltr">
                <code>{endpoint}</code>
              </bdi>
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}

/** وسم حالة مضغوط داخل خلية أو بطاقة. */
export function HonestTag({ state, note }: { state: HonestState; note?: string }) {
  return (
    <span className={`${styles.tag} ${styles[state] ?? ''}`}>
      <bdi dir="ltr">{state}</bdi>
      {note ? <small>{note}</small> : null}
    </span>
  );
}

/**
 * سطر «لماذا هذا الزرّ معطَّل». الزرّ المعطَّل يشير إليه بـ aria-describedby،
 * فلا يبقى التعطيل سرًّا بصريًا.
 */
export function LockedReason({ id, children }: { id: string; children: ReactNode }) {
  return (
    <p className={styles.locked} id={id}>
      <span aria-hidden="true" className={styles.lockedDot} />
      {children}
    </p>
  );
}
