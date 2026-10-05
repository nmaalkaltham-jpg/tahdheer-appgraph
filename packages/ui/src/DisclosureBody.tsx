// UNIT: DISCLOSUREBODY
// FILE: DisclosureBody.tsx
// STYLE: DisclosureBody.module.css
// PARENT: ACCORDION, TABS
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * DisclosureBody — جسم البند المشترك بين Tabs وAccordion (عقد المرحلة ٣٫١٠،
 * قرار C109): فقرة اختيارية ثمّ قائمة اختيارية. داخلي — لا يُصدَّر من الحزمة.
 *
 * عقيدة الصدق (C110): نصٌّ فارغ لا يُصيَّر فقرةً فارغة، وقائمة بلا بنود لا
 * تُصيَّر ‎<ul>‎ فارغة؛ وكلاهما فارغًا ⇒ لا شيء.
 */
import styles from './DisclosureBody.module.css';

interface DisclosureBodyProps {
  text?: string;
  points?: readonly string[];
}

export function DisclosureBody({ text = '', points = [] }: DisclosureBodyProps) {
  const paragraph = text.trim();
  const list = points.map((p) => p.trim()).filter((p) => p !== '');
  if (paragraph === '' && list.length === 0) return null;

  return (
    <>
      {paragraph === '' ? null : <p className={styles.text}>{paragraph}</p>}
      {list.length === 0 ? null : (
        <ul className={styles.points}>
          {list.map((point, index) => (
            /* البند قد يتكرّر نصًّا؛ الفهرس يضمن مفتاحًا ثابتًا بلا اختلاق معرّف. */
            <li key={`${index}:${point}`}>{point}</li>
          ))}
        </ul>
      )}
    </>
  );
}
