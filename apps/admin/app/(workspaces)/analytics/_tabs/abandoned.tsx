'use client';
// UNIT: ABANDONED
// FILE: abandoned.tsx
// STYLE: none
// PARENT: PAGE-ANALYTICS
// USES: INSIGHT-PANEL, RANGE
// API: none
// API-VIA: INSIGHT-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * السلال المتروكة.
 *
 * ─── لماذا زالت البوّابة ───
 *
 * كانت الشاشة مغلقةً ببوّابة «لا تجميع خادميّ» بينما التجميع مبنيٌّ وموصول
 * ومعروضٌ على لوحة التشغيل نفسها: `CartsInsight` يقرأ السلال وأصنافها ويردّ
 * المهجورة وقيمتها ومعدّل الهجر عبر `GET /admin/insights/carts`.
 *
 * فالبوّابة لم تكن تحمي من نقصٍ، كانت تكذب على المشغّل: تقول «غير مبنيّ» عن
 * شيءٍ يراه يعمل في شاشةٍ أخرى. وبوّابةٌ بائتة أسوأ من غيابها — تُفقد الثقة
 * في كلّ بوّابةٍ أخرى في اللوحة.
 *
 * ─── والمدى ───
 *
 * ثلاثون يومًا كافتراض الخادم نفسه (`InsightWindow::fromRequest`)، فلا يختلف
 * ما تعرضه الشاشة عمّا يعرضه استدعاءٌ بلا وسائط.
 */

import { InsightPanel } from '../../dashboard/insight-panel';
import { lastDays } from '../_shared/range';

export default function AbandonedTab() {
  const { from, to } = lastDays(30);

  return (
    <InsightPanel
      domain="carts"
      title="السلال المتروكة"
      description="ما وُضع في السلّة ولم يُشترَ، وكم يساوي — وما لا يمكن الحكم عليه يُقال."
      from={from}
      to={to}
      defaultOpen
    />
  );
}
