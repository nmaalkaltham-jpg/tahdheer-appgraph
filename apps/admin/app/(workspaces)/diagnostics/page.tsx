// UNIT: PAGE-DIAGNOSTICS
// FILE: page.tsx
// ROUTE: /diagnostics
// STYLE: none
// PARENT: none
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/** المسار القديم يبقى يعمل — ويحوّل إلى تبويبه بعد الدمج. */

import { redirect } from 'next/navigation';

export default function LegacyRoute(): never {
  redirect('/system?t=health');
}
