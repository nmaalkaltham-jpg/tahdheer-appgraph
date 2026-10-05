// UNIT: PAGE-ANALYTICS-OS-ASSETS
// FILE: page.tsx
// ROUTE: /analytics/os/assets
// STYLE: none
// PARENT: none
// USES: CAPABILITY-GATE, RESOURCE
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * شاشةٌ مغلقة ببوّابة القدرة.
 *
 * كانت تُرسل طلبًا إلى نقطة نهاية غير موجودة، وتتلقّى ٤٠٤، وتعرض «تعذّر
 * جلب البيانات» — وهي رسالةُ عطلٍ مؤقّت لا رسالةُ ميزةٍ لم تُبنَ. فيُعيد
 * المشغّل المحاولة، ثمّ يبلّغ عن عطل، ثمّ يفقد الثقة في الرسائل كلّها.
 *
 * الآن لا طلب يُرسَل أصلًا: البوّابة ساكنةٌ وقت البناء، وتقول ما ينقص.
 */

import { PageHeader } from '../../../../../components/resource';
import { CapabilityGate } from '../../../../../components/capability-gate';

export default function Page() {
  return (
    <>
      <PageHeader trail="التحليلات" title="ذكاء أصول المنتجات" />
      <CapabilityGate capability="analytics.intelligence" needs="نطاق ذكاء المنتجات: استخراج، وتطبيع، واستدلال، ومراجعة، ونشر (§٣٤)" />
    </>
  );
}
