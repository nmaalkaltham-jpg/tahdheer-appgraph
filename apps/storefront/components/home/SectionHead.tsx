/**
 * SectionHead — the homepage section header from TahHomeBody: muted eyebrow
 * over the shared h2 scale, with an optional bordered action link opposite it.
 *
 * RTL NOTE: the 1440 artboards put the action link first in source order (so it
 * lands at the inline-start edge, with the h2 pushed to inline-end), while the
 * 390 ones put the heading first. Heading-first is the correct Arabic reading
 * and tab order, so that is the DOM on both breakpoints and the desktop swap is
 * done visually in CSS (`.head > .headText { order: 2 }`) — the same trick
 * TahHomeReviews spells out with `statsOrder: compact ? '2' : '0'`.
 *
 * The h2 itself comes from the shared @tahdheer/ui SectionHeading (one type
 * scale for every section on the site); only the eyebrow is local, because
 * the homepage eyebrow is muted (--text-muted) rather than the shared teal.
 */
import type { ReactNode } from 'react';
import { SectionHeading } from '@tahdheer/ui';
import { Icon } from './icons';
import styles from './sections.module.css';

export interface SectionHeadAction {
  label: string;
  /** The 390 artboard shortens these links to «الكل»; absent ⇒ keep `label`. */
  shortLabel?: string;
  href: string;
}

export interface SectionHeadProps {
  title: string;
  /** Small tracked over-line, e.g. «تصفّح» / «مختارات» / «هذا الأسبوع». */
  eyebrow?: string;
  /** Bordered link opposite the heading (inline-start on desktop) — real routes only. */
  action?: SectionHeadAction;
  /** Anything else that shares the head row with the heading (the featured tabs). */
  children?: ReactNode;
  align?: 'start' | 'center';
}

export function SectionHead({ title, eyebrow, action, children, align = 'start' }: SectionHeadProps) {
  return (
    <div className={align === 'center' ? `${styles.head} ${styles.headCenter}` : styles.head}>
      <div className={styles.headText}>
        {eyebrow ? <span className={styles.eyebrow}>{eyebrow}</span> : null}
        <SectionHeading title={title} align={align} />
      </div>
      {action ? (
        <a className={styles.headLink} href={action.href}>
          {/* Only one of the two is in the DOM flow at a time (`display:none`
              also removes the other from the accessible name). */}
          <span className={action.shortLabel ? styles.headLinkFull : undefined}>{action.label}</span>
          {action.shortLabel ? (
            <span className={styles.headLinkShort}>{action.shortLabel}</span>
          ) : null}
          <Icon name="chev" size={14} strokeWidth={2.2} />
        </a>
      ) : null}
      {children}
    </div>
  );
}
