// UNIT: PAGE-PERFORMANCE
// FILE: page.tsx
// ROUTE: /performance
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

import { PageHeader } from '../../../components/resource';
import { CapabilityGate } from '../../../components/capability-gate';

export default function Page() {
  return (
    <>
      <PageHeader trail="النظام" title="قياسات الأداء" />
      <CapabilityGate capability="performance" needs="جمع قياسات الأداء وتخزينها (§٦٥)" />
    </>
  );
}
