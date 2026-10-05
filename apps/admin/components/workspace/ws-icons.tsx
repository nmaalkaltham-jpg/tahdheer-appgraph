// UNIT: WS-ICONS
// FILE: ws-icons.tsx
// STYLE: none
// PARENT: EDIT-TOOLBAR, WIDGET-FRAME
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * أيقونات مساحة العمل — بنفس هندسة `components/icons.tsx` (٢٤ · خطّ ١٫٦).
 *
 * محلّيّةٌ لا في السجلّ المشترك: لا يستعملها سطحٌ غير المحرّر، وإضافتها
 * هناك تُعيد بناء كلّ ما يستورد السجلّ لأجل ستّة مسارات.
 * و`undo`/`redo` تُقلبان في RTL: السهم يشير إلى «الوراء» في اتّجاه القراءة.
 */

const PATHS = {
  grip: 'M9 5h.01M9 12h.01M9 19h.01M15 5h.01M15 12h.01M15 19h.01',
  lock: 'M5 11h14v10H5zm3 0V7a4 4 0 0 1 8 0v4',
  unlock: 'M5 11h14v10H5zm3 0V7a4 4 0 0 1 7.9-.9',
  more: 'M5 12h.01M12 12h.01M19 12h.01',
  undo: 'M9 14L4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',
  redo: 'M15 14l5-5-5-5M20 9H9a5 5 0 0 0 0 10h3',
  edit: 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
  magnet: 'M6 3v8a6 6 0 0 0 12 0V3m-12 4h4m4 0h4',
  eye: 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12zm11 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
} as const;

export type WsIconName = keyof typeof PATHS;

export function WsIcon({ name, size = 16 }: { name: WsIconName; size?: number }) {
  const heavy = name === 'grip' || name === 'more';
  return (
    <svg
      viewBox="0 0 24 24"
      width={size}
      height={size}
      fill="none"
      stroke="currentColor"
      strokeWidth={heavy ? 3 : 1.6}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
      data-flip={name === 'undo' || name === 'redo' ? 'rtl' : undefined}
    >
      <path d={PATHS[name]} />
    </svg>
  );
}
