// UNIT: NAVIGATION-ICONS
// FILE: icons.tsx
// STYLE: none
// PARENT: DIALOGS, INSPECTOR, PAGE-NAVIGATION, VIEWS, _VIEW-KIT, _VIEW-MAP
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مجموعة الرموز الخطّية للاستوديو — منقولة حرفيًا من الأصل البصري.
 * رسم يدوي بالكامل: لا حزمة أيقونات ولا مكتبة رسوم مضافة.
 */

export const ICON_KEYS = [
  'home',
  'cart',
  'tag',
  'doc',
  'folder',
  'user',
  'link',
  'bolt',
  'globe',
  'badge',
] as const;

export type IconKey = (typeof ICON_KEYS)[number];

const PATHS: Record<string, React.ReactNode> = {
  map: (
    <>
      <path d="M3 6l6-2 6 2 6-2v14l-6 2-6-2-6 2z" />
      <path d="M9 4v14M15 6v14" />
    </>
  ),
  tree: (
    <>
      <rect x="3" y="4" width="7" height="4" rx="1" />
      <rect x="14" y="10" width="7" height="4" rx="1" />
      <rect x="14" y="16" width="7" height="4" rx="1" />
      <path d="M10 6h2v12h2M12 12h2" />
    </>
  ),
  monitor: (
    <>
      <rect x="3" y="4" width="18" height="12" rx="2" />
      <path d="M8 20h8M12 16v4" />
    </>
  ),
  table: (
    <>
      <rect x="3" y="4" width="18" height="16" rx="2" />
      <path d="M3 9h18M9 9v11M15 9v11" />
    </>
  ),
  check: <path d="M5 12.5l4.5 4.5L19 7" />,
  plus: <path d="M12 5v14M5 12h14" />,
  x: <path d="M6 6l12 12M18 6L6 18" />,
  'chev-d': <path d="M6 9l6 6 6-6" />,
  'chev-l': <path d="M14 6l-6 6 6 6" />,
  'chev-r': <path d="M10 6l6 6-6 6" />,
  search: (
    <>
      <circle cx="11" cy="11" r="7" />
      <path d="M21 21l-4.2-4.2" />
    </>
  ),
  undo: (
    <>
      <path d="M4 9h9a5 5 0 1 1 0 10H8" />
      <path d="M4 9l4-4M4 9l4 4" />
    </>
  ),
  redo: (
    <>
      <path d="M20 9h-9a5 5 0 1 0 0 10h5" />
      <path d="M20 9l-4-4M20 9l-4 4" />
    </>
  ),
  save: (
    <>
      <path d="M5 5h11l3 3v11H5z" />
      <path d="M9 5v5h6V5M9 19v-5h6v5" />
    </>
  ),
  publish: (
    <>
      <path d="M12 3l8 3v6c0 5-3.4 8.5-8 9.9C7.4 20.5 4 17 4 12V6z" />
      <path d="M12 16V9M9 12l3-3 3 3" />
    </>
  ),
  pen: (
    <>
      <path d="M15 4l5 5L9 20l-5 1 1-5z" />
      <path d="M13 6l5 5" />
    </>
  ),
  copy: (
    <>
      <rect x="8" y="8" width="12" height="12" rx="2" />
      <path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" />
    </>
  ),
  restore: (
    <>
      <path d="M4 12a8 8 0 1 0 3-6.2" />
      <path d="M4 4v5h5" />
    </>
  ),
  grip: (
    <>
      <circle cx="9" cy="6" r="1.35" />
      <circle cx="15" cy="6" r="1.35" />
      <circle cx="9" cy="12" r="1.35" />
      <circle cx="15" cy="12" r="1.35" />
      <circle cx="9" cy="18" r="1.35" />
      <circle cx="15" cy="18" r="1.35" />
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
  shield: (
    <>
      <path d="M12 3l8 3v6c0 5-3.4 8.5-8 9.9C7.4 20.5 4 17 4 12V6z" />
      <path d="M8.8 12.3l2.2 2.2 4.2-4.4" />
    </>
  ),
  bolt: <path d="M13 3L5 14h5l-1 7 8-11h-5z" />,
  link: (
    <>
      <path d="M9.5 14.5l5-5" />
      <path d="M13 7l1.5-1.5a3.5 3.5 0 0 1 5 5L18 12" />
      <path d="M11 17l-1.5 1.5a3.5 3.5 0 0 1-5-5L6 12" />
    </>
  ),
  ext: (
    <>
      <path d="M14 5h5v5" />
      <path d="M19 5l-8 8" />
      <path d="M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4" />
    </>
  ),
  home: (
    <>
      <path d="M4 11l8-7 8 7" />
      <path d="M6 10v9h12v-9" />
    </>
  ),
  cart: (
    <>
      <path d="M4 5h2l2.2 9.4a1.6 1.6 0 0 0 1.6 1.3h7a1.6 1.6 0 0 0 1.6-1.3L20 8H6.4" />
      <circle cx="10" cy="19" r="1.3" />
      <circle cx="17" cy="19" r="1.3" />
    </>
  ),
  user: (
    <>
      <circle cx="12" cy="8" r="3.4" />
      <path d="M5.9 19.4a6.2 6.2 0 0 1 12.2 0" />
    </>
  ),
  tag: (
    <>
      <path d="M11 4H5v6l9 9 6-6z" />
      <circle cx="8.4" cy="7.4" r="1.15" />
    </>
  ),
  doc: (
    <>
      <path d="M7 3h7l4 4v14H7z" />
      <path d="M14 3v4h4" />
    </>
  ),
  folder: <path d="M4 6h5l2 2h9v11H4z" />,
  clock: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5.5l3.5 2" />
    </>
  ),
  zin: (
    <>
      <circle cx="11" cy="11" r="7" />
      <path d="M21 21l-4.2-4.2M11 8v6M8 11h6" />
    </>
  ),
  zout: (
    <>
      <circle cx="11" cy="11" r="7" />
      <path d="M21 21l-4.2-4.2M8 11h6" />
    </>
  ),
  fit: <path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />,
  phone: (
    <>
      <rect x="7" y="3" width="10" height="18" rx="2.5" />
      <path d="M11 18.4h2" />
    </>
  ),
  tablet: (
    <>
      <rect x="4" y="3" width="16" height="18" rx="2.5" />
      <path d="M11 18.4h2" />
    </>
  ),
  lock: (
    <>
      <rect x="5" y="10" width="14" height="10" rx="2" />
      <path d="M8 10V8a4 4 0 0 1 8 0v2" />
    </>
  ),
  list: <path d="M4 7h16M4 12h16M4 17h10" />,
  layers: (
    <>
      <path d="M12 4l8 4-8 4-8-4z" />
      <path d="M4 12l8 4 8-4M4 16l8 4 8-4" />
    </>
  ),
  globe: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3c3 3.4 3 14.6 0 18M12 3c-3 3.4-3 14.6 0 18" />
    </>
  ),
  badge: (
    <>
      <circle cx="12" cy="10" r="5" />
      <path d="M9.5 14L8 21l4-2 4 2-1.5-7" />
    </>
  ),
  key: (
    <>
      <circle cx="8" cy="12" r="4" />
      <path d="M12 12h9M17 12v4M20 12v3" />
    </>
  ),
  target: (
    <>
      <circle cx="12" cy="12" r="7" />
      <path d="M12 2v3M12 19v3M2 12h3M19 12h3" />
    </>
  ),
  download: <path d="M12 4v10M8 11l4 4 4-4M5 19h14" />,
  upload: <path d="M12 20V9M8 12l4-4 4 4M5 4h14" />,
};

export function Icon({
  name,
  size = 14,
  className,
}: {
  name: string;
  size?: number;
  className?: string;
}) {
  const body = PATHS[name];
  if (!body) return null;
  const filled = name === 'grip';
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      viewBox="0 0 24 24"
      width={size}
      height={size}
      className={className}
      style={{
        fill: filled ? 'currentColor' : 'none',
        stroke: filled ? 'none' : 'currentColor',
        strokeWidth: 1.9,
        strokeLinecap: 'round',
        strokeLinejoin: 'round',
        flex: '0 0 auto',
      }}
    >
      {body}
    </svg>
  );
}
