/**
 * Account-area stroke glyphs — the exact sprite symbols «Tahdheer - My Account»
 * and «Tahdheer - Customer Completion Pack» reference (#i-grid, #i-book,
 * #i-cart-bag, #i-download, …). Path data is copied verbatim from
 * TahIcons.dc.html; nothing is redrawn or approximated.
 *
 * Every glyph is decorative (aria-hidden): each sits beside real text, so the
 * accessible name always comes from that text, never from the glyph.
 */
import type { ReactNode } from 'react';

export type AccountIconName =
  | 'grid'
  | 'book'
  | 'cartBag'
  | 'download'
  | 'heart'
  | 'wallet'
  | 'medal'
  | 'tag'
  | 'sliders'
  | 'headset'
  | 'userCircle'
  | 'lock'
  | 'bell'
  | 'files'
  | 'search'
  | 'check'
  | 'refresh'
  | 'clock'
  | 'info'
  | 'link';

const PATHS: Record<AccountIconName, ReactNode> = {
  grid: (
    <>
      <rect x="4" y="4" width="7" height="7" rx="1.5" />
      <rect x="13" y="4" width="7" height="7" rx="1.5" />
      <rect x="4" y="13" width="7" height="7" rx="1.5" />
      <rect x="13" y="13" width="7" height="7" rx="1.5" />
    </>
  ),
  book: (
    <>
      <path d="M19 4H8a2 2 0 0 0-2 2v13a2 2 0 0 1 2-2h11z" />
      <path d="M19 4v15" />
    </>
  ),
  cartBag: (
    <>
      <path d="M5 8h14l-1 12.2a1.6 1.6 0 0 1-1.6 1.5H7.6a1.6 1.6 0 0 1-1.6-1.5L5 8Z" />
      <path d="M8.6 8V6.2a3.4 3.4 0 0 1 6.8 0V8" />
    </>
  ),
  download: (
    <>
      <path d="M12 4v10" />
      <path d="M8 11l4 4 4-4" />
      <path d="M5 19h14" />
    </>
  ),
  heart: (
    <path d="M19 13.6c1.45-1.44 2.8-3.06 2.8-5.24A5.16 5.16 0 0 0 16.6 3.2c-1.7 0-2.98.55-4.6 2.06C10.38 3.75 9.1 3.2 7.4 3.2A5.16 5.16 0 0 0 2.2 8.36c0 2.18 1.35 3.8 2.8 5.24L12 20.5Z" />
  ),
  wallet: (
    <>
      <rect x="3" y="6.5" width="18" height="12.5" rx="2.4" />
      <path d="M3 10.5h18" />
      <circle cx="16.6" cy="14.8" r="1.15" />
      <path d="M6.5 6.5V5.4A1.6 1.6 0 0 1 8.6 3.9l8.2 2.6" />
    </>
  ),
  medal: (
    <>
      <circle cx="12" cy="10" r="5" />
      <path d="M9.5 14L8 21l4-2 4 2-1.5-7" />
    </>
  ),
  tag: (
    <>
      <path d="M4 12l8-8h6a2 2 0 0 1 2 2v6l-8 8z" />
      <circle cx="15.5" cy="8.5" r="1.3" />
    </>
  ),
  sliders: (
    <>
      <path d="M4 8h10M18 8h2M4 16h4M12 16h8" />
      <circle cx="16" cy="8" r="2" />
      <circle cx="10" cy="16" r="2" />
    </>
  ),
  headset: (
    <>
      <path d="M4 13v-1a8 8 0 0 1 16 0v1" />
      <rect x="3" y="13" width="4" height="6" rx="1.4" />
      <rect x="17" y="13" width="4" height="6" rx="1.4" />
      <path d="M20 19a4 4 0 0 1-4 3h-2" />
    </>
  ),
  userCircle: (
    <>
      <circle cx="12" cy="12" r="9" />
      <circle cx="12" cy="9.6" r="2.9" />
      <path d="M6.2 18.5a6 6 0 0 1 11.6 0" />
    </>
  ),
  lock: (
    <>
      <rect x="5" y="11" width="14" height="9" rx="2" />
      <path d="M8 11V8a4 4 0 0 1 8 0v3" />
    </>
  ),
  bell: (
    <>
      <path d="M6 9.5a6 6 0 0 1 12 0c0 5.1 2.2 6.7 2.2 6.7H3.8S6 14.6 6 9.5Z" />
      <path d="M10.1 19.6a2 2 0 0 0 3.8 0" />
    </>
  ),
  files: (
    <>
      <path d="M8 3h6l4 4v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" />
      <path d="M14 3v4h4" />
    </>
  ),
  search: (
    <>
      <circle cx="11" cy="11" r="7" />
      <path d="M21 21l-4-4" />
    </>
  ),
  check: <path d="M4.5 12.5l5 5 10-11" />,
  refresh: (
    <>
      <path d="M20 11a8 8 0 0 0-14-4.5L4 9" />
      <path d="M4 4v5h5" />
      <path d="M4 13a8 8 0 0 0 14 4.5L20 15" />
      <path d="M20 20v-5h-5" />
    </>
  ),
  clock: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7.5V12l3 2" />
    </>
  ),
  info: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 11v5M12 7.6h.01" />
    </>
  ),
  link: (
    <>
      <path d="M10.2 13.8a3.6 3.6 0 0 0 5.1 0l3.1-3.1a3.6 3.6 0 0 0-5.1-5.1l-1 1" />
      <path d="M13.8 10.2a3.6 3.6 0 0 0-5.1 0l-3.1 3.1a3.6 3.6 0 0 0 5.1 5.1l1-1" />
    </>
  ),
};

export interface AccountIconProps {
  name: AccountIconName;
  className?: string;
  /** Design stroke weights in this area: 1.8 · 1.9 · 2 · 2.6. */
  strokeWidth?: number;
}

export function AccountIcon({ name, className, strokeWidth = 1.9 }: AccountIconProps) {
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
    >
      {PATHS[name]}
    </svg>
  );
}
