// UNIT: PRODUCT-FACTORY-PANEL
// FILE: product-factory-panel.tsx
// STYLE: none
// PARENT: AI-INDEX, PAGE-ANALYTICS-OS-AI
// USES: CAPABILITY-GATE
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * لوحةٌ مغلقة ببوّابة القدرة.
 *
 * كانت تُرسل طلبًا إلى نقطة نهاية غير موجودة فتعرض عطلًا. ولا طلب يُرسَل
 * الآن: البوّابة ساكنةٌ وقت البناء وتقول ما ينقص.
 */

import { CapabilityGate } from '../capability-gate';

export function ProductFactoryPanel() {
  return <CapabilityGate capability="analytics.intelligence" needs="نطاق ذكاء المنتجات: استخراج، وتطبيع، واستدلال، ومراجعة، ونشر (§٣٤)" />;
}
