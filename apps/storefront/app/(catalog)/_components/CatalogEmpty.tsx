/**
 * The dashed «nothing here» box of «Tahdheer - Category».
 *
 * A storefront empty state is a real answer, not a gap to be filled: it says
 * what is missing and offers a REAL destination. Every action is a working
 * link — no disabled-looking control, no filler products.
 */
import type { ReactNode } from 'react';
import { CatalogIcon, type CatalogIconName } from './icons';
import styles from './catalog.module.css';

export interface EmptyAction {
  label: string;
  href: string;
  /** The one recommended way out; the rest render as secondary links. */
  primary?: boolean;
}

export interface CatalogEmptyProps {
  title: string;
  text?: ReactNode;
  actions?: EmptyAction[];
  icon?: CatalogIconName;
}

export function CatalogEmpty({ title, text, actions, icon = 'search' }: CatalogEmptyProps) {
  return (
    <div className={styles.empty}>
      <span className={styles.emptyBadge} aria-hidden="true">
        <CatalogIcon name={icon} strokeWidth={2} className={styles.emptyIcon} />
      </span>
      <h2 className={styles.emptyTitle}>{title}</h2>
      {text ? <p className={styles.emptyText}>{text}</p> : null}
      {actions && actions.length > 0 ? (
        <div className={styles.emptyActions}>
          {actions.map((action) => (
            <a
              key={action.label}
              className={action.primary ? styles.emptyPrimary : styles.emptySecondary}
              href={action.href}
            >
              {action.label}
            </a>
          ))}
        </div>
      ) : null}
    </div>
  );
}
