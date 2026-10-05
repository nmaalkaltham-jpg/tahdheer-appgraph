// UNIT: ACCORDION
// FILE: Accordion.tsx
// STYLE: Accordion.module.css
// PARENT: UI-INDEX
// USES: DISCLOSUREBODY
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * Accordion — «الأكورديون» tah/accordion.1904 (بطاقات) وtah/accordion.1909
 * (مطويّات ناعمة) — عقد المرحلة ٣٫١٠. النسق من مدخل السجل (C107)، والقيم من
 * لوح «Tabs & Accordion» 1a/1c حرفيًّا.
 *
 * ‎<details>‎ بلا حالة React (قرار C112) — القرار موروث من FaqList وسببه قائم:
 * الطيّ يعمل قبل تحميل أي جافاسكربت، ويجده محرّك البحث نصًّا في المصدر،
 * ويحمل دلالة الكشف للقارئ الصوتي بلا سمة ARIA يدوية.
 *
 * ‎singleOpen‎ خاصّية لا مدخل (C112): محور المدخل مصروف للنسق البصري. الآلية
 * آلية C56 نفسها — سمة ‎name‎ مشتركة مشتقّة من ‎domId‎، وهي التجميع الأصيل في
 * HTML: فتح واحدٍ يغلق أخاه بلا سطر جافاسكربت.
 *
 * الملخّص يحمل الحشو بنفسه لا البطاقة حوله (C108): هدف اللمس ٤٨px لا ٢٤px.
 */
import { DisclosureBody } from './DisclosureBody';
import styles from './Accordion.module.css';

export type AccordionLayout = 'cards' | 'soft';

export interface AccordionItem {
  title: string;
  text?: string;
  points?: readonly string[];
}

export interface AccordionProps {
  /** فارغًا ⇒ لا عنوان ولا ‎aria-labelledby‎ يشير إلى عنصر غير موجود. */
  title?: string;
  items: readonly AccordionItem[];
  /** النسق من المدخل: ‎cards‎ (1a) أو ‎soft‎ (1c). */
  layout?: AccordionLayout;
  /** فتح بند واحد فقط — يبثّ ‎name‎ مشتركًا على كل ‎<details>‎. */
  singleOpen?: boolean;
  /** معرّف فريد للقسم — من معرّف العقدة (قرار C55). */
  domId?: string;
}

export function Accordion({ title = '', items, layout = 'cards', singleOpen = false, domId }: AccordionProps) {
  // بلا بنود لا قسم — عنوان فوق فراغ يوحي بمحتوى مفقود (C110).
  if (items.length === 0) return null;

  const heading = title.trim();
  const base = domId ?? 'accordion';
  const titleId = heading === '' ? undefined : `${base}-title`;
  /* اسم المجموعة فريد في الوثيقة: مجموعتان بالاسم نفسه تصيران واحدة. */
  const groupName = singleOpen ? `${base}-group` : undefined;

  return (
    <section
      className={`${styles.section} ${layout === 'soft' ? styles.soft : styles.cards}`}
      aria-labelledby={titleId}
      data-theme-component="accordion"
    >
      {heading === '' ? null : (
        <h2 className={styles.title} id={titleId}>
          {heading}
        </h2>
      )}
      <div className={styles.list}>
        {items.map((item, index) => (
          <details className={styles.item} key={`${index}:${item.title}`} name={groupName}>
            <summary className={styles.summary}>
              <span>{item.title}</span>
              <span className={styles.icon} aria-hidden="true"></span>
            </summary>
            <div className={styles.body}>
              <DisclosureBody text={item.text} points={item.points} />
            </div>
          </details>
        ))}
      </div>
    </section>
  );
}
