// UNIT: PAGE-SEARCH-EXPLAIN
// FILE: page.tsx
// ROUTE: /search/explain
// STYLE: none
// PARENT: none
// USES: RESOURCE, SEARCH-EXPLAIN-PANEL
// API: none
// API-VIA: SEARCH-EXPLAIN-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * مصحّح الصلة — «لماذا ظهر هذا المنتج؟ ولماذا هو فوق ذاك؟» (§٣٧ و§١٥٠).
 *
 * المسار الأب ‎/search‎ محوَّلٌ إلى تبويبه في «الظهور والبحث»، وهذه الشاشة
 * مسارٌ مستقلّ تحته لا تبويب فيه: المصحّح أداةُ تشخيصٍ تُفتح عند سؤالٍ بعينه
 * وتُغلق، لا سطحٌ يُتصفَّح. وضعُها تبويبًا خامسًا في صفحةٍ تُفتح يوميًّا كان
 * سيُحمّل كلّ زيارةٍ طلبًا لا يُنتظر جوابه.
 */

import { PageHeader } from '../../../../components/resource';
import { SearchExplainPanel } from './explain-panel';

export default function Page() {
  return (
    <>
      <PageHeader trail="الظهور والبحث" title="مصحّح الصلة" />
      <SearchExplainPanel />
    </>
  );
}
