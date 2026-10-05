// UNIT: CAPABILITY-GATE
// FILE: capability-gate.tsx
// STYLE: unbuilt-notice.module.css
// PARENT: PAGE-ANALYTICS-OS-ASSETS, PAGE-PERFORMANCE, PRODUCT-FACTORY-PANEL
// USES: API-SDK-INDEX
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * بوّابة القدرة: تُغلق **قبل** أيّ نداء.
 *
 * ─── العقد ───
 *
 *   القدرة غير المتاحة ⇦ لا طلب يُرسَل، ولا زرّ يُعرض، وحالةٌ صريحة تُقال.
 *
 * ─── لماذا لا يُستعمل ٤٠٤ كاشفًا ───
 *
 * الرمز يعني «لا مورد بهذا العنوان» لا «الميزة غير مبنيّة». والخلط بينهما
 * يجعل عطلَ خادمٍ حقيقيًّا يبدو ميزةً مؤجَّلة — فيُتجاهَل. وكان هذا حال
 * تسع شاشاتٍ: تُرسل طلبًا، وتتلقّى ٤٠٤، وتعرض «تعذّر جلب البيانات» — وهي
 * رسالةُ عطلٍ مؤقّت لا رسالةُ ميزةٍ لم تُبنَ.
 *
 * ─── ولماذا السجلّ مولَّد لا مقروءٌ عبر الشبكة ───
 *
 * قراءةُ التوفّر بنداءٍ تُناقض العقد نفسه: يبدأ الطلب قبل أن يُعرف أنّه لا
 * يجوز. السجلّ ساكنٌ وقت البناء، فالبوّابة تُغلق قبل أن يُفتح اتّصال.
 */

import { isCapabilityAvailable, capabilityTitle, type CapabilityKey } from '@tahdheer/api-sdk';
import styles from './unbuilt-notice.module.css';

export function CapabilityGate({
  capability,
  needs,
  children,
}: {
  capability: CapabilityKey;
  /** ما ينقص لتصير متاحة — يُكتب صراحةً لا يُترك للتخمين. */
  needs: string;
  children?: React.ReactNode;
}) {
  if (isCapabilityAvailable(capability)) {
    return <>{children}</>;
  }

  return (
    <section className={styles.notice} role="status">
      <h2 className={styles.title}>{capabilityTitle(capability)} — غير متاح</h2>

      <p className={styles.body}>
        هذه القدرة غير مبنيّة في الخادم، فلا تُرسل هذه الشاشة أي طلب. ما ينقص: {needs}.
      </p>

      <p className={styles.body}>
        الحالة مسجّلة في <code>docs/capabilities.json</code> بوصفها مؤجّلة، ويمنع الحارس أي نداء إلى
        نقطة نهاية غير موجودة.
      </p>
    </section>
  );
}
