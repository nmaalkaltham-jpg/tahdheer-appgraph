// UNIT: PAGE-CATALOG-INTELLIGENCE
// FILE: page.tsx
// ROUTE: /catalog/intelligence
// STYLE: none
// PARENT: none
// USES: CATALOG-INTELLIGENCE-PANEL, RESOURCE
// API: none
// API-VIA: CATALOG-INTELLIGENCE-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * فاحص ذكاء المنتج (§١١٣ §١١٤ §١١٥ §١٨٢).
 *
 * مسارٌ مستقلّ لا تبويبٌ في «استوديو الكتالوج»: هذه شاشةُ تشخيصٍ تُفتح عند
 * سؤالٍ بعينه — «لماذا لا يجد البحثُ هذا المنتج؟ وهل قُرئ ملفّه أصلًا؟» —
 * ثمّ تُغلق. وضعُها تبويبًا في صفحةٍ تُفتح يوميًّا كان يُحمّل كلّ زيارةٍ
 * طلبًا لا يُنتظر جوابه (القرار نفسه المكتوب عند `/search/explain`).
 */

import { PageHeader } from '../../../../components/resource';
import { CatalogIntelligencePanel } from './intelligence-panel';

export default function Page() {
  return (
    <>
      <PageHeader trail="الكتالوج" title="فاحص ذكاء المنتج" />
      <CatalogIntelligencePanel />
    </>
  );
}
