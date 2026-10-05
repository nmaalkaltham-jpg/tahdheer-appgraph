// UNIT: LAYOUT-ANALYTICS-OS
// FILE: layout.tsx
// ROUTE: /analytics/os
// STYLE: none
// PARENT: none
// USES: LEVEL, OS-KIT
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * تخطيط Analytics Pro OS: كروم النظام المشترك (تبويبات الشاشات السبع +
 * شريط مستوى التنفيذ) حول كل شاشة تحت ‎/analytics/os‏.
 *
 * رموز التصميم الجديدة لهذه المساحة تُحمَّل من دفتر رموز الوكيل A في
 * ‎.design-sync/tokens-3p‏ — لا تُكتب في tokens.css الأساسي.
 */

import type { ReactNode } from 'react';
import '../../../../../../.design-sync/tokens-3p/agent-A (foundation + control/actions).css';
import { OsLevelProvider } from './_lib/level';
import { OsChrome } from './_components/os-kit';

export default function AnalyticsOsLayout({ children }: { children: ReactNode }) {
  return (
    <OsLevelProvider>
      <OsChrome>{children}</OsChrome>
    </OsLevelProvider>
  );
}
