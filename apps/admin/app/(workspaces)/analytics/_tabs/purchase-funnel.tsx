'use client';
// UNIT: PURCHASE-FUNNEL
// FILE: purchase-funnel.tsx
// STYLE: none
// PARENT: PAGE-ANALYTICS
// USES: INSIGHT-PANEL, RANGE
// API: none
// API-VIA: INSIGHT-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * قمع الشراء.
 *
 * ─── لماذا زالت البوّابة ───
 *
 * كانت مغلقةً ببوّابة «يحتاج طبقة مقاييس دلاليّة»، والقمع مبنيٌّ وموصول:
 * `FunnelInsight` يقيس مراحله السفلى من صفوف المعاملات ويُعلن العليا غير
 * مقيسة باسمها وسببها.
 *
 * وهذا بالضبط ما كانت البوّابة تطالب به — أن يُقال ما لا يُقاس بدل اختراعه.
 * فبقاؤها بعد بناء ذلك يخفي نصف قمعٍ حقيقيّ خلف رسالة «غير مبنيّ».
 */

import { InsightPanel } from '../../dashboard/insight-panel';
import { lastDays } from '../_shared/range';

export default function PurchaseFunnelTab() {
  const { from, to } = lastDays(30);

  return (
    <InsightPanel
      domain="funnel"
      title="قمع الشراء"
      description="من سلّةٍ إلى طلبٍ إلى دفع — وما فوقها يُعلَن غير مقيس بسببه."
      from={from}
      to={to}
      defaultOpen
    />
  );
}
