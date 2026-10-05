import type { ReactNode } from 'react';
import styles from './pdp.module.css';

/**
 * Product tabs — Storefront Product.dc.html («الوصف» … «الترخيص والاسترجاع»)
 * and the mobile file's shorter five («الوصف» … «أسئلة شائعة»).
 *
 * Radios, not scripted tabs: each tab is a real radio inside its label and
 * the panels switch with `:has(:checked)`, so the page works with scripting
 * off. Arrow keys move between radios exactly as they move between tabs.
 * The rating row's «N تقييمًا» is a `<label for>` of the reviews radio, so it
 * opens the reviews tab the way the design's `goReviews` does.
 */
export interface PdpTab {
  id: string;
  label: string;
  /** Mobile label (Storefront Product Mobile.dc.html) when it differs. */
  short?: string;
  /** Absent from the mobile tab row (reviews and licence have no mobile tab). */
  desktopOnly?: boolean;
  content: ReactNode;
}

export const TAB_GROUP = 'pdp-tab';

export function tabInputId(id: string): string {
  return `${TAB_GROUP}-${id}`;
}

export function PdpTabs({ tabs }: { tabs: PdpTab[] }) {
  if (tabs.length === 0) return null;
  /* Open on the first tab that also exists on mobile, so a phone never lands
     on a panel whose tab is hidden there. */
  const initial = Math.max(0, tabs.findIndex((tab) => !tab.desktopOnly));

  return (
    <div className={styles.tabs}>
      <div className={styles.tablist} role="group" aria-label="أقسام صفحة المنتج">
        {tabs.map((tab, index) => (
          <label className={styles.tab} key={tab.id} data-desktop-only={tab.desktopOnly ? '' : undefined}>
            <input
              className={styles.tabInput}
              type="radio"
              name={TAB_GROUP}
              id={tabInputId(tab.id)}
              aria-labelledby={`${tabInputId(tab.id)}-label`}
              defaultChecked={index === initial}
            />
            <span id={`${tabInputId(tab.id)}-label`} className={styles.tabText}>
              {tab.short ? (
                <>
                  <span className={styles.tabLong}>{tab.label}</span>
                  <span className={styles.tabShort}>{tab.short}</span>
                </>
              ) : (
                tab.label
              )}
            </span>
          </label>
        ))}
      </div>

      {tabs.map((tab) => (
        <section className={styles.panel} key={tab.id} aria-label={tab.label}>
          {tab.content}
        </section>
      ))}
    </div>
  );
}
