// UNIT: SHARED-ICONS
// FILE: icons.tsx
// STYLE: none
// PARENT: ATTENTION-MAP, DIAGNOSTICS-PANEL, OVERVIEW, PAGE-ANALYTICS, RANGE-BAR, REPORTS +2
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * أيقونات مرسومة يدويًا (SVG خطّي، شبكة 24) — لا مكتبة أيقونات ولا تبعية.
 * تقابل مجموعة TahIcons في لوحات التصميم بنفس الوزن والأطراف المدوّرة.
 */

export type IconName =
  | 'gauge'
  | 'funnel'
  | 'eye'
  | 'chart'
  | 'plug'
  | 'contract'
  | 'info'
  | 'close'
  | 'sliders'
  | 'download'
  | 'lock'
  | 'map'
  | 'bell'
  | 'moon'
  | 'sun'
  | 'coins'
  | 'cart'
  | 'percent'
  | 'user'
  | 'refresh'
  | 'search'
  | 'grid'
  | 'layers'
  | 'alert'
  | 'up'
  | 'down'
  | 'external'
  | 'files'
  | 'sparkle'
  | 'grip';

const PATHS: Record<IconName, string[]> = {
  gauge: ['M4 18a8 8 0 1 1 16 0', 'M12 18l4-5'],
  funnel: ['M3 4h18l-7 8.5V20l-4-2v-5.5z'],
  eye: ['M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6z', 'M12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z'],
  chart: ['M3 20h18', 'M6.5 20v-5', 'M11.5 20V8', 'M16.5 20v-9'],
  plug: ['M9 3v6', 'M15 3v6', 'M6 9h12v2.5a6 6 0 0 1-12 0z', 'M12 17.5V21'],
  contract: ['M6 3h8l4 4v14H6z', 'M14 3v4h4', 'M9 14l2 2 4-4'],
  info: ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z', 'M12 11v5', 'M12 8h.01'],
  close: ['M6 6l12 12', 'M18 6L6 18'],
  sliders: ['M4 7h16', 'M4 12h16', 'M4 17h16', 'M9 4.8v4.4', 'M16 9.8v4.4', 'M7 14.8v4.4'],
  download: ['M12 3v12', 'M7 11l5 5 5-5', 'M5 20h14'],
  lock: ['M5 11h14v9H5z', 'M8 11V8a4 4 0 0 1 8 0v3'],
  map: ['M9 4L3 7v13l6-3 6 3 6-3V4l-6 3z', 'M9 4v13', 'M15 7v13'],
  bell: ['M18 16v-5a6 6 0 1 0-12 0v5l-2 3h16z', 'M10 22h4'],
  moon: ['M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11z'],
  sun: ['M12 7.5a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9z', 'M12 2v2', 'M12 20v2', 'M2 12h2', 'M20 12h2', 'M4.9 4.9l1.5 1.5', 'M17.6 17.6l1.5 1.5', 'M19.1 4.9l-1.5 1.5', 'M6.4 17.6L4.9 19.1'],
  coins: ['M9 4a5 3 0 1 0 0 6 5 3 0 0 0 0-6z', 'M4 7v4c0 1.7 2.2 3 5 3s5-1.3 5-3V7', 'M10 14v3c0 1.7 2.2 3 5 3s5-1.3 5-3v-4', 'M15 10a5 3 0 1 0 0 6 5 3 0 0 0 0-6z'],
  cart: ['M3 4h2.2l2.4 11h10L20 7H6.2', 'M10 19.5a1 1 0 1 0 0 2 1 1 0 0 0 0-2z', 'M17 19.5a1 1 0 1 0 0 2 1 1 0 0 0 0-2z'],
  percent: ['M7 7h.01', 'M17 17h.01', 'M18 6L6 18'],
  user: ['M12 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8z', 'M4 21a8 8 0 0 1 16 0'],
  refresh: ['M20.5 12a8.5 8.5 0 1 1-2.9-6.4', 'M20.5 4v5h-5'],
  search: ['M11 5a6 6 0 1 0 0 12 6 6 0 0 0 0-12z', 'M20 20l-4.7-4.7'],
  grid: ['M4 4h7v7H4z', 'M13 4h7v7h-7z', 'M4 13h7v7H4z', 'M13 13h7v7h-7z'],
  layers: ['M12 3l9 5-9 5-9-5z', 'M3 13l9 5 9-5', 'M3 17.5l9 5 9-5'],
  alert: ['M12 3.5L21 20H3z', 'M12 10v4', 'M12 17h.01'],
  up: ['M12 19V5', 'M6 11l6-6 6 6'],
  down: ['M12 5v14', 'M6 13l6 6 6-6'],
  external: ['M14 4h6v6', 'M20 4l-8.5 8.5', 'M18 14v6H5V6h6'],
  files: ['M8 7h9l3 3v11H8z', 'M17 7v3h3', 'M8 7V4h7', 'M4 7v14h4'],
  sparkle: ['M12 3l2 5.5L19.5 10 14 12l-2 5.5L10 12l-5.5-2L10 8.5z', 'M18.5 16.5l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9z'],
  grip: [
    'M9 7h.01',
    'M15 7h.01',
    'M9 12h.01',
    'M15 12h.01',
    'M9 17h.01',
    'M15 17h.01',
  ],
};

interface IconProps {
  name: IconName;
  /** الحجم بالبكسل — الافتراضي 14 كما في اللوحات. */
  size?: number;
  strokeWidth?: number;
}

/**
 * الأيقونة زخرفية دائمًا: المعنى يحمله النص المجاور، لا الرسم.
 */
export function Icon({ name, size = 14, strokeWidth = 2 }: IconProps) {
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {PATHS[name].map((d) => (
        <path key={d} d={d} />
      ))}
    </svg>
  );
}
