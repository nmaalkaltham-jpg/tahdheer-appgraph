// UNIT: _SEO-ICONS
// FILE: _seo-icons.tsx
// STYLE: none
// PARENT: _KIT
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

import type { ReactNode } from 'react';

/* ───────────── الأيقونات: مسارات حرفية من اللوح ───────────── */

const PATHS: Record<string, ReactNode> = {
  gauge: (
    <>
      <path d="M4 14a8 8 0 1 1 16 0" />
      <path d="M12 14l4-4" />
      <path d="M4 18h16" />
    </>
  ),
  rocket: (
    <>
      <path d="M12 16c5-3 7-8 7-12-4 0-9 2-12 7l-3 1 4 4 1 3z" />
      <circle cx="13.5" cy="10.5" r="1.4" />
      <path d="M6 18c-1 1-1.5 3-1.5 3s2-.5 3-1.5" />
    </>
  ),
  search: (
    <>
      <circle cx="11" cy="11" r="7" />
      <path d="M21 21l-4.2-4.2" />
    </>
  ),
  globe: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3c3 3.4 3 14.6 0 18M12 3c-3 3.4-3 14.6 0 18" />
    </>
  ),
  doc: (
    <>
      <path d="M7 3h7l4 4v14H7z" />
      <path d="M14 3v4h4" />
    </>
  ),
  cart: (
    <>
      <path d="M4 5h2l2.2 9.4a1.6 1.6 0 0 0 1.6 1.3h7a1.6 1.6 0 0 0 1.6-1.3L20 8H6.4" />
      <circle cx="10" cy="19" r="1.3" />
      <circle cx="17" cy="19" r="1.3" />
    </>
  ),
  shield: (
    <>
      <path d="M12 3l8 3v6c0 5-3.4 8.5-8 9.9C7.4 20.5 4 17 4 12V6z" />
      <path d="M8.8 12.3l2.2 2.2 4.2-4.4" />
    </>
  ),
  task: (
    <>
      <rect x="4" y="4" width="16" height="16" rx="2.5" />
      <path d="M8 12l2.5 2.5L16 9" />
    </>
  ),
  warn: (
    <>
      <path d="M12 4l9 16H3z" />
      <path d="M12 10v5M12 17.6v.1" />
    </>
  ),
  info: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 11v6M12 7.6v.1" />
    </>
  ),
  check: <path d="M5 12.5l4.5 4.5L19 7" />,
  x: <path d="M6 6l12 12M18 6L6 18" />,
  link: (
    <>
      <path d="M9.5 14.5l5-5" />
      <path d="M13 7l1.5-1.5a3.5 3.5 0 0 1 5 5L18 12" />
      <path d="M11 17l-1.5 1.5a3.5 3.5 0 0 1-5-5L6 12" />
    </>
  ),
  map: (
    <>
      <path d="M3 6l6-2 6 2 6-2v14l-6 2-6-2-6 2z" />
      <path d="M9 4v14M15 6v14" />
    </>
  ),
  bolt: <path d="M13 3L5 14h5l-1 7 8-11h-5z" />,
  coin: (
    <>
      <circle cx="12" cy="12" r="8.5" />
      <path d="M9.5 15V9h3.2a2 2 0 0 1 0 4H9.5" />
    </>
  ),
  sparkle: (
    <>
      <path d="M12 4l1.8 4.7L18.5 10l-4.7 1.4L12 16l-1.8-4.6L5.5 10l4.7-1.3z" />
      <path d="M18.5 15.5l.9 2.3 2.3.7-2.3.7-.9 2.3-.9-2.3-2.3-.7 2.3-.7z" />
    </>
  ),
  eye: (
    <>
      <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z" />
      <circle cx="12" cy="12" r="3" />
    </>
  ),
  chart: (
    <>
      <path d="M4 20V6M4 20h16" />
      <path d="M8 20v-6M12 20v-9M16 20v-4M20 20v-11" />
    </>
  ),
  clock: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5.5l3.5 2" />
    </>
  ),
  robot: (
    <>
      <rect x="5" y="8" width="14" height="10" rx="2.5" />
      <path d="M12 8V4M9 4h6" />
      <circle cx="9.5" cy="12.5" r="1" />
      <circle cx="14.5" cy="12.5" r="1" />
      <path d="M9.5 15.5h5" />
    </>
  ),
  target: (
    <>
      <circle cx="12" cy="12" r="7" />
      <path d="M12 2v3M12 19v3M2 12h3M19 12h3" />
    </>
  ),
  graph: (
    <>
      <circle cx="6" cy="6" r="2.2" />
      <circle cx="18" cy="8" r="2.2" />
      <circle cx="8" cy="18" r="2.2" />
      <circle cx="17" cy="17" r="2.2" />
      <path d="M8 7.2l7.8.6M7 8l1 7.8M9.9 17.4l4.9-.3M16.7 15.2L17.7 10" />
    </>
  ),
  tag: (
    <>
      <path d="M11 4H5v6l9 9 6-6z" />
      <circle cx="8.4" cy="7.4" r="1.15" />
    </>
  ),
  bell: (
    <>
      <path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2.5H4.5z" />
      <path d="M10 21a2 2 0 0 0 4 0" />
    </>
  ),
  layers: (
    <>
      <path d="M12 4l8 4-8 4-8-4z" />
      <path d="M4 12l8 4 8-4M4 16l8 4 8-4" />
    </>
  ),
  diff: (
    <>
      <rect x="3" y="4" width="8" height="16" rx="1.5" />
      <rect x="13" y="4" width="8" height="16" rx="1.5" />
      <path d="M5.5 9h3M7 7.5v3M15.5 9h3" />
    </>
  ),
};

export type IconName = keyof typeof PATHS;

export function Icon({ name, className }: { name: IconName; className?: string }) {
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 24 24"
      className={className}
      style={{ fill: 'none', stroke: 'currentColor', strokeWidth: 1.9, strokeLinecap: 'round', strokeLinejoin: 'round' }}
    >
      {PATHS[name]}
    </svg>
  );
}
