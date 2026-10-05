// UNIT: PAGE-AUDIT
// FILE: page.tsx
// ROUTE: /audit
// STYLE: none
// PARENT: none
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/** المسار القديم يبقى يعمل — ويحوّل إلى تبويبه بعد الدمج. */

import { redirect } from 'next/navigation';

export default function LegacyRoute(): never {
  redirect('/system?t=audit');
}
