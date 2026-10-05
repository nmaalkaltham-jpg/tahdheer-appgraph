/**
 * Catalog glyph set — path data copied VERBATIM from the design sprite
 * («TahIcons.dc.html» symbols #i-files, #i-download, #i-sliders, #i-check,
 * #i-close, #i-search, #i-bolt, #i-clock, #i-tag, #i-trophy, #i-plus,
 * #i-chev, #i-book, #i-cap, #i-blocks). Nothing here is redrawn.
 *
 * Every glyph is decorative (aria-hidden): each one sits beside real text, so
 * the accessible name always comes from that text — never from the icon.
 *
 * RTL: #i-chev points to the inline-start edge (‹ shape), which IS the
 * "forward" direction in Arabic, exactly as the design draws it.
 */
import type { ReactNode } from 'react';

export type CatalogIconName =
  | 'files'
  | 'download'
  | 'sliders'
  | 'check'
  | 'close'
  | 'search'
  | 'bolt'
  | 'clock'
  | 'tag'
  | 'trophy'
  | 'plus'
  | 'chev'
  | 'book'
  | 'cap'
  | 'blocks';

const PATHS: Record<CatalogIconName, ReactNode> = {
  files: (
    <>
      <path d="M8 3h6l4 4v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" />
      <path d="M14 3v4h4" />
    </>
  ),
  download: (
    <>
      <path d="M12 4v10" />
      <path d="M8 11l4 4 4-4" />
      <path d="M5 19h14" />
    </>
  ),
  sliders: (
    <>
      <path d="M4 8h10M18 8h2M4 16h4M12 16h8" />
      <circle cx="16" cy="8" r="2" />
      <circle cx="10" cy="16" r="2" />
    </>
  ),
  check: <path d="M4.5 12.5l5 5 10-11" />,
  close: <path d="M6 6l12 12M18 6L6 18" />,
  search: (
    <>
      <circle cx="11" cy="11" r="7" />
      <path d="M21 21l-4-4" />
    </>
  ),
  bolt: <path d="M13 3L6 13h5l-1 8 7-10h-5z" />,
  clock: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7.5V12l3 2" />
    </>
  ),
  tag: (
    <>
      <path d="M4 12l8-8h6a2 2 0 0 1 2 2v6l-8 8z" />
      <circle cx="15.5" cy="8.5" r="1.3" />
    </>
  ),
  trophy: (
    <>
      <path d="M8 4h8v4.5a4 4 0 0 1-8 0z" />
      <path d="M8 6H5.5a2 2 0 0 0 2.2 3M16 6h2.5a2 2 0 0 1-2.2 3" />
      <path d="M12 12.5V16M9 20h6M10 20l.6-4M14 20l-.6-4" />
    </>
  ),
  plus: <path d="M12 5v14M5 12h14" />,
  chev: <path d="M14 6l-6 6 6 6" />,
  book: (
    <>
      <path d="M19 4H8a2 2 0 0 0-2 2v13a2 2 0 0 1 2-2h11z" />
      <path d="M19 4v15" />
    </>
  ),
  cap: (
    <>
      <path d="M3 9l9-4 9 4-9 4z" />
      <path d="M7 11v4c0 1.2 2.2 2.6 5 2.6s5-1.4 5-2.6v-4" />
    </>
  ),
  blocks: (
    <>
      <rect x="4" y="4" width="7" height="7" rx="1.5" />
      <rect x="13" y="4" width="7" height="7" rx="1.5" />
      <rect x="8.5" y="13" width="7" height="7" rx="1.5" />
    </>
  ),
};

export interface CatalogIconProps {
  name: CatalogIconName;
  /** Design stroke weights on this surface: 1.9–3.2. */
  strokeWidth?: number;
  /** Sizing comes from the stylesheet so the glyph tracks its container. */
  className?: string;
}

export function CatalogIcon({ name, strokeWidth = 2.2, className }: CatalogIconProps) {
  return (
    <svg
      className={className}
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {PATHS[name]}
    </svg>
  );
}
