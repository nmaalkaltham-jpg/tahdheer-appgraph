// UNIT: EMPTYSTATE
// FILE: EmptyState.tsx
// STYLE: EmptyState.module.css
// PARENT: UI-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * EmptyState — the dashed «nothing here» box of
 * Tahdheer - Search Results.dc.html, bound to the empty-state law: admin
 * surfaces get an explicit box explaining what is missing; visitors get
 * NOTHING (the component returns null). Never fabricate content to fill a gap.
 * Server-component-friendly.
 */
import type { ReactNode } from 'react';
import styles from './EmptyState.module.css';

export interface EmptyStateProps {
  /** 'admin' renders the dashed box; 'visitor' renders nothing. */
  audience: 'admin' | 'visitor';
  title: string;
  hint?: string;
  /** Registry stroke icon (decorative). */
  icon?: ReactNode;
  /** Optional action slot (e.g. a link to the admin screen that fills the gap). */
  action?: ReactNode;
}

export function EmptyState({ audience, title, hint, icon, action }: EmptyStateProps) {
  if (audience === 'visitor') return null;

  return (
    <div className={styles.box}>
      {/* زخرفة: العنوان والتلميح يحملان المعنى، فالغلاف يخفيها عن شجرة الوصول. */}
      {icon ? (
        <span className={styles.icon} aria-hidden="true">
          {icon}
        </span>
      ) : null}
      <p className={styles.title}>{title}</p>
      {hint ? <p className={styles.hint}>{hint}</p> : null}
      {action ? <div className={styles.action}>{action}</div> : null}
    </div>
  );
}
