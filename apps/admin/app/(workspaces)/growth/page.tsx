// UNIT: PAGE-GROWTH
// FILE: page.tsx
// ROUTE: /growth
// STYLE: none
// PARENT: none
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/** المسار القديم يبقى يعمل — والحلقات صارت داخل «الأتمتة». */

import { redirect } from 'next/navigation';

export default function LegacyRoute(): never {
  redirect('/analytics/os/flows');
}
