/**
 * Homepage icon set — the exact registry stroke glyphs the homepage designs
 * reference through their sprite (TahHomeBody / TahHomeMobile / TahTrust:
 * #i-grid, #i-chev, #i-check, …). Path data is copied verbatim from the
 * design sprite; nothing is redrawn or approximated.
 *
 * Every icon is decorative (aria-hidden): each one sits beside real text, so
 * the accessible name always comes from that text — never from the glyph.
 *
 * RTL: #i-chev points to the inline-start edge (‹ shape) which IS the
 * "forward" direction in Arabic, exactly as the design draws it.
 */
import type { ReactNode } from 'react';

export type IconName =
  | 'grid'
  | 'chev'
  | 'check'
  | 'download'
  | 'pen'
  | 'files'
  | 'tag'
  | 'plus'
  | 'star'
  | 'book'
  | 'cap'
  | 'blocks'
  | 'trophy'
  | 'palette'
  | 'globe'
  | 'shield'
  | 'headset'
  | 'medal';

const PATHS: Record<IconName, ReactNode> = {
  grid: (
    <>
      <rect x="4" y="4" width="7" height="7" rx="1.5" />
      <rect x="13" y="4" width="7" height="7" rx="1.5" />
      <rect x="4" y="13" width="7" height="7" rx="1.5" />
      <rect x="13" y="13" width="7" height="7" rx="1.5" />
    </>
  ),
  chev: <path d="M14 6l-6 6 6 6" />,
  check: <path d="M4.5 12.5l5 5 10-11" />,
  download: (
    <>
      <path d="M12 4v10" />
      <path d="M8 11l4 4 4-4" />
      <path d="M5 19h14" />
    </>
  ),
  pen: (
    <>
      <path d="M15 4l5 5L9 20l-5 1 1-5z" />
      <path d="M13 6l5 5" />
    </>
  ),
  files: (
    <>
      <path d="M8 3h6l4 4v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" />
      <path d="M14 3v4h4" />
    </>
  ),
  tag: (
    <>
      <path d="M4 12l8-8h6a2 2 0 0 1 2 2v6l-8 8z" />
      <circle cx="15.5" cy="8.5" r="1.3" />
    </>
  ),
  plus: <path d="M12 5v14M5 12h14" />,
  star: <path d="M12 3.6l2.6 5.4 5.9.8-4.3 4.1 1 5.9-5.2-2.8-5.2 2.8 1-5.9L3.5 9.8l5.9-.8z" />,
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
  trophy: (
    <>
      <path d="M8 4h8v4.5a4 4 0 0 1-8 0z" />
      <path d="M8 6H5.5a2 2 0 0 0 2.2 3M16 6h2.5a2 2 0 0 1-2.2 3" />
      <path d="M12 12.5V16M9 20h6M10 20l.6-4M14 20l-.6-4" />
    </>
  ),
  palette: (
    <>
      <path d="M12 3a9 9 0 1 0 0 18c1.3 0 2-1 2-2 0-1.3-1-1.6-1-2.6 0-.8.7-1.4 1.6-1.4H17a4 4 0 0 0 4-4c0-4.4-4-8-9-8z" />
      <circle cx="7.5" cy="11" r="1" />
      <circle cx="10" cy="7.5" r="1" />
      <circle cx="14.5" cy="7.5" r="1" />
    </>
  ),
  globe: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3c2.5 2.5 2.5 15 0 18M12 3c-2.5 2.5-2.5 15 0 18" />
    </>
  ),
  shield: (
    <>
      <path d="M12 3l7 3v5c0 4.5-3 7.7-7 9-4-1.3-7-4.5-7-9V6z" />
      <path d="M9.3 12l1.9 1.9 3.6-3.7" />
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
  medal: (
    <>
      <circle cx="12" cy="10" r="5" />
      <path d="M9.5 14L8 21l4-2 4 2-1.5-7" />
    </>
  ),
};

export interface IconProps {
  name: IconName;
  /** Square size in px — the designs use 12–23. */
  size?: number;
  /** Design stroke weights: 1.8–2.6. */
  strokeWidth?: number;
  /** Solid glyph (the star renders filled in the reviews band). */
  filled?: boolean;
  className?: string;
}

export function Icon({ name, size = 16, strokeWidth = 2, filled = false, className }: IconProps) {
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill={filled ? 'currentColor' : 'none'}
      stroke={filled ? 'none' : 'currentColor'}
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
    >
      {PATHS[name]}
    </svg>
  );
}

/**
 * اختيارٌ دوريّ من قائمةٍ قصيرة — تنويعٌ زخرفيّ لا بيانات.
 *
 * يسكن هنا لأنّ مستعمليه كلّهم يدوّرون أيقونةً أو صبغةً من هذه الورقة،
 * وكان معرَّفًا حرفيًّا في ملفّين: `sections.tsx` و`StagesExplorer.tsx`.
 * ونسختان لدالّةٍ واحدة تنحرفان عند أوّل تعديل.
 */
export function pick<T>(list: readonly T[], index: number, fallback: T): T {
  return list[index % list.length] ?? fallback;
}
