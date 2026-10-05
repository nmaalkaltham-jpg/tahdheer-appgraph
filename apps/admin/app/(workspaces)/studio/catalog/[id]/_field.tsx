// UNIT: _FIELD
// FILE: _field.tsx
// STYLE: studio.module.css
// PARENT: NEW-STUDIO, STUDIO-CLIENT, WIRED-PANELS
// USES: STUDIO-DATA
// API: none
// MAP: docs/PROJECT_MAP.md

import type { ReactNode } from 'react';
import { cx } from '../studio-data';
import styles from './studio.module.css';

/**
 * حقلٌ في الاستوديو — يُصدَّر ليستعمله وضع الإنشاء أيضًا.
 *
 * نسختان من الحقل تعنيان تسميةً تنزلق وحشوًا يختلف، فيبدو «منتج جديد»
 * شاشةً أخرى لا الخطوة الأولى من الاستوديو نفسه.
 */
export function Field({
  id,
  label,
  hint,
  wide,
  children,
}: {
  id: string;
  label: string;
  hint?: string;
  wide?: boolean;
  children: ReactNode;
}) {
  return (
    <div className={cx(styles.field, wide && styles.fieldWide)}>
      <label htmlFor={id}>{label}</label>
      {children}
      {hint ? <span className={styles.hint}>{hint}</span> : null}
    </div>
  );
}
