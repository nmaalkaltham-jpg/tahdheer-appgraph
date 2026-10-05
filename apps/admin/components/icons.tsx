// UNIT: COMPONENTS-ICONS
// FILE: icons.tsx
// STYLE: none
// PARENT: COMMAND-CENTER, DATA-TABLE, DATE-RANGE, FILTER-BAR, INSIGHT-PANEL, INTEGRATIONS-PANEL +9
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مجموعة أيقونات اللوحة — مسارات مضمّنة، لا مكتبة.
 *
 * ─── لماذا لا مكتبة أيقونات ───
 *
 * الشريط الجانبيّ وحده يحتاج نحو أربعين أيقونة. وأصغر مكتبةٍ شائعة تُدخل
 * غلافَ مكوّنٍ لكلّ أيقونة وشجرةَ تصدير كاملة؛ وما يُشحن فعلًا أربعون مسارًا
 * لا أكثر. المسارات هنا تُكتب مرّة وتُشحن كما هي — صفر اعتماد، وصفر خطر
 * أن تختفي أيقونةٌ لأنّ نسخة المكتبة أعادت تسميتها.
 *
 * ─── الاتّجاه ───
 *
 * الأيقونات التي تعبّر عن **حركةٍ مكانيّة** (سهم، رجوع) تُعكس في RTL عبر
 * `data-flip`؛ وما عداها لا يُعكس: عربةٌ مقلوبة أو مغلّفٌ مقلوب تشويشٌ لا
 * توطين.
 */

import type { SVGProps } from 'react';

/* مسار واحد لكلّ رمز. `stroke` لا `fill`: يرث لون النصّ فيتبع الحالة. */
const PATHS = {
  box: 'M21 8l-9-5-9 5m18 0v8l-9 5-9-5V8m18 0l-9 5-9-5',
  check: 'M22 11.1V12a10 10 0 1 1-5.9-9.1M22 4L12 14.01l-3-3',
  cart: 'M9 22a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm11 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6',
  file: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm0 0v6h6M9 13h6M9 17h6',
  alert: 'M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4m0 4h.01',
  truck: 'M1 3h13v13H1zm13 5h4l3 3v5h-7zM5.5 18.5a2 2 0 1 0 0 .01zm12 0a2 2 0 1 0 0 .01z',
  card: 'M2 5h20v14H2zm0 5h20',
  plug: 'M9 2v6m6-6v6M5 8h14v3a7 7 0 0 1-14 0zm7 10v4',
  users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8zm13 18v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
  star: 'M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z',
  tag: 'M20.6 13.4L12 22l-9-9V3h10zM7.5 7.5h.01',
  trend: 'M23 6l-9.5 9.5-5-5L1 18M17 6h6v6',
  layers: 'M12 2L2 7l10 5 10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
  package: 'M16.5 9.4L7.5 4.2M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16zM3.3 7L12 12l8.7-5M12 22V12',
  boxes: 'M3 3h7v7H3zm11 0h7v7h-7zM3 14h7v7H3zm11 0h7v7h-7z',
  rotate: 'M1 4v6h6M23 20v-6h-6M20.5 9A9 9 0 0 0 5.6 5.6L1 10m22 4l-4.6 4.4A9 9 0 0 1 3.5 15',
  repeat: 'M17 1l4 4-4 4M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4m14-2v2a4 4 0 0 1-4 4H3',
  link: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7L12.2 19',
  shield: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
  search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zm10 2l-4.3-4.3',
  compass: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zm4.2-14.2l-2.8 6.4-6.4 2.8 2.8-6.4z',
  swap: 'M7 16H3m0 0l4-4m-4 4l4 4M17 8h4m0 0l-4-4m4 4l-4 4',
  unlink: 'M17.7 9.7l1.4-1.4a5 5 0 0 0-7-7L10.6 2.7M6.3 14.3l-1.4 1.4a5 5 0 0 0 7 7l1.4-1.4M2 2l20 20',
  map: 'M1 6l7-3 8 3 7-3v15l-7 3-8-3-7 3zM8 3v15m8-12v15',
  globe: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z',
  chart: 'M3 3v18h18M7 15v3m5-9v9m5-13v13',
  cpu: 'M5 5h14v14H5zm4 4h6v6H9zM9 1v4m6-4v4M9 19v4m6-4v4M1 9h4m-4 6h4m14-6h4m-4 6h4',
  wand: 'M15 4V2m0 20v-2M4 15H2m20 0h-2M6.4 6.4L5 5m14 14l-1.4-1.4M6.4 17.6L5 19M19 5l-1.4 1.4M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
  palette: 'M12 22a10 10 0 1 1 0-20c5.5 0 10 3.6 10 8 0 2.8-2.2 4-4.5 4H15a2 2 0 0 0-1.4 3.4A2 2 0 0 1 12 22zM7.5 10.5h.01M12 7.5h.01M16.5 10.5h.01',
  list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
  image: 'M3 3h18v18H3zm3 12l4-4 4 4 2-2 5 5M8.5 8.5h.01',
  sparkle: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM19 3v3m1.5-1.5h-3M5 18v3m1.5-1.5h-3',
  cog: 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zm7.4-2.5a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1v.3a2 2 0 1 1-4 0v-.2a1.6 1.6 0 0 0-2.8-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 4.5 13h-.3a2 2 0 1 1 0-4h.2a1.6 1.6 0 0 0 1.1-2.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H10a1.6 1.6 0 0 0 1-1.5v-.3a2 2 0 1 1 4 0v.2a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V10a1.6 1.6 0 0 0 1.5 1h.3a2 2 0 1 1 0 4h-.2a1.6 1.6 0 0 0-1.4 1z',
  pulse: 'M22 12h-4l-3 9L9 3l-3 9H2',
  server: 'M2 3h20v7H2zm0 11h20v7H2zM6 6.5h.01M6 17.5h.01',
  gauge: 'M12 21a9 9 0 1 0-9-9M12 12l5-5M3 12h2m14 0h2M12 3v2',
  scroll: 'M4 3h12l4 4v14H4zM16 3v4h4M8 12h8M8 16h8',
  bell: 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
  grid: 'M3 3h8v8H3zm10 0h8v8h-8zM3 13h8v8H3zm10 0h8v8h-8z',
  home: 'M3 9.5L12 2l9 7.5V21H3zM9 21v-7h6v7',
  logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14l5-5-5-5m5 5H9',
  chevron: 'M9 18l6-6-6-6',
  close: 'M18 6L6 18M6 6l12 12',
  refresh: 'M23 4v6h-6M1 20v-6h6M3.5 9a9 9 0 0 1 14.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15',
} as const;

export type IconName = keyof typeof PATHS;

export function Icon({
  name,
  flip = false,
  size = 18,
  ...rest
}: { name: IconName; flip?: boolean; size?: number } & Omit<SVGProps<SVGSVGElement>, 'name'>) {
  return (
    <svg
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
      data-flip={flip ? 'rtl' : undefined}
      {...rest}
    >
      <path d={PATHS[name]} />
    </svg>
  );
}
