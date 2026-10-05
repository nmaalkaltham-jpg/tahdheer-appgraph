// UNIT: TRUSTSTRIP
// FILE: TrustStrip.tsx
// STYLE: TrustStrip.module.css
// PARENT: UI-INDEX
// USES: CX
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * TrustStrip — the four presets of TahTrustStrip.dc.html (classic · minimal ·
 * glass · smart) plus the four-column trust band of TahTrust.dc.html.
 *
 * HONESTY DOCTRINE: every claim is a PROP. The component ships no built-in
 * copy, no counts and no «live» state of its own; with an empty list it
 * renders nothing at all. Colour resolves from a light/dark token pair, so the
 * same items work on the page surface and on the dark chrome.
 *
 * Server-component-friendly (no client hooks).
 */
import type { ReactNode } from 'react';
import { cx } from './cx';
import styles from './TrustStrip.module.css';

export type TrustStripVariant = 'band' | 'classic' | 'minimal' | 'glass' | 'smart';

/** Dot tone for the `smart` preset (TahTrustStrip.dc.html dot palette). */
export type TrustItemTone = 'primary' | 'bright' | 'gold';

export interface TrustItem {
  id: string;
  /** The claim itself, e.g. «دفع آمن». */
  title: string;
  /** Secondary line — the band's sub-copy and the smart preset's state chip. */
  description?: string;
  /** Registry stroke icon element (decorative — the wrapper carries aria-hidden). */
  icon?: ReactNode;
  /** `smart` preset only: which dot colour this row carries. */
  tone?: TrustItemTone;
  /**
   * `smart` preset only: draw the pulsing ring. Pass true ONLY for a state
   * that is genuinely live (e.g. support is actually online right now).
   */
  live?: boolean;
}

export interface TrustStripProps {
  variant?: TrustStripVariant;
  items: readonly TrustItem[];
  /** Use the dark token pair (for placement on --ink chrome). */
  dark?: boolean;
  /** Tighter padding + gap (TahTrustStrip.dc.html `compact`). */
  compact?: boolean;
}

const VARIANT_CLASS: Record<TrustStripVariant, string | undefined> = {
  band: styles.band,
  classic: styles.classic,
  minimal: styles.minimal,
  glass: styles.glass,
  smart: styles.smart,
};

const TONE_CLASS: Record<TrustItemTone, string | undefined> = {
  primary: undefined,
  bright: styles.toneBright,
  gold: styles.toneGold,
};

/**
 * `classic` stays the default so existing call sites keep the row they have
 * today; TahTrustStrip.dc.html declares `minimal` as its editor default.
 */
export function TrustStrip({ variant = 'classic', items, dark, compact }: TrustStripProps) {
  if (items.length === 0) return null; // nothing to visitors without real facts

  return (
    <ul
      className={cx(styles.strip, VARIANT_CLASS[variant], dark && styles.dark, compact && styles.compact)}
      role="list"
      data-theme-component="trust-strip"
    >
      {items.map((item) => (
        <li key={item.id} className={styles.item}>
          {variant === 'smart' ? (
            <span
              className={cx(styles.dot, item.live && styles.live, TONE_CLASS[item.tone ?? 'primary'])}
              aria-hidden="true"
            >
              <span className={styles.dotCore} />
              <span className={styles.dotRing} />
            </span>
          ) : null}

          {/* الغلاف يحمل aria-hidden بنفسه: الأيقونة زخرفة والادّعاء في `title`،
              فلا يعتمد إخفاؤها على تذكّر المستدعي (عرف HighlightGrid نفسه). */}
          {variant !== 'smart' && item.icon ? (
            <span className={styles.icon} aria-hidden="true">
              {item.icon}
            </span>
          ) : null}

          <span className={styles.text}>
            <span className={styles.title}>{item.title}</span>
            {item.description ? <span className={styles.desc}>{item.description}</span> : null}
          </span>

          {/* minimal draws a hairline between claims; the last one is hidden in CSS */}
          {variant === 'minimal' ? <span className={styles.sep} aria-hidden="true" /> : null}
        </li>
      ))}
    </ul>
  );
}
