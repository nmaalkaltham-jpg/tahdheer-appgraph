// UNIT: FAQLIST
// FILE: FaqList.tsx
// STYLE: FaqList.module.css
// PARENT: UI-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * FaqList — قائمة أسئلة شائعة قابلة للتركيب (عقد المرحلة ٣٫٦، قرار C54).
 *
 * مُرقّاة من `components/product/ProductFaqs.tsx` بلا تغيير بصري: ملفّ الأنماط
 * منقول حرفيًّا، وصفحة المنتج تستهلكها الآن عبر غلافٍ رفيع فوقها. لولا
 * الترقية لعاشت نسختان من نفس `<details>` تنحرفان عند أوّل تعديل.
 *
 * ‎<details>‎ بلا حالة React — القرار موروث من الأصل وسببه قائم: الطيّ يعمل
 * قبل تحميل أي جافاسكربت، ويجده محرّك البحث نصًّا في المصدر، ويحمل دلالة
 * الكشف للقارئ الصوتي بلا سمة ARIA يدوية. ولهذا يعمل المكوّن في الكانفس
 * وفي صفحة الزائر بالسلوك نفسه: لا شيء فيه يحتاج تشغيلًا.
 *
 * ‎openMode‎ (قرار C56) فرقٌ سلوكي لا تسمية: `single` يبثّ سمة `name` مشتركة
 * على كل `<details>` — وهو التجميع الأصيل في HTML: فتح واحدٍ يغلق أخاه بلا
 * سطر جافاسكربت. و`multi` يتركها فيبقى كلٌّ مستقلًّا.
 */
import styles from './FaqList.module.css';

export type FaqOpenMode = 'single' | 'multi';

export interface FaqItem {
  question: string;
  answer: string;
}

export interface FaqListProps {
  /** فارغًا ⇒ لا عنوان ولا ‎aria-labelledby‎ يشير إلى عنصر غير موجود. */
  title?: string;
  items: readonly FaqItem[];
  openMode?: FaqOpenMode;
  /**
   * معرّف فريد للقسم — من معرّف العقدة (قرار C55). قسمان في صفحة واحدة
   * كانا سيتقاسمان `id` ثابتًا فينكسر ‎aria-labelledby‎ وHTML معًا.
   */
  domId?: string;
}

export function FaqList({ title = '', items, openMode = 'multi', domId }: FaqListProps) {
  // بلا أسئلة لا قسم — عنوان فوق فراغ يوحي بمحتوى مفقود (سلوك الأصل حرفيًّا).
  if (items.length === 0) return null;

  const heading = title.trim();
  const titleId = heading === '' ? undefined : `${domId ?? 'faq'}-title`;
  /*
   * اسم المجموعة يجب أن يكون فريدًا في الوثيقة كلّها: مجموعتان بالاسم نفسه
   * تصيران مجموعة واحدة، فيغلق سؤالُ قسمٍ سؤالَ قسمٍ آخر.
   */
  const groupName = openMode === 'single' ? `${domId ?? 'faq'}-group` : undefined;

  return (
    <section className={styles.section} aria-labelledby={titleId} data-theme-component="faq">
      {heading === '' ? null : (
        <h2 className={styles.title} id={titleId}>
          {heading}
        </h2>
      )}
      <div className={styles.list}>
        {items.map((item, index) => (
          <details
            className={styles.item}
            /* السؤال قد يتكرّر نصًّا؛ الفهرس يضمن مفتاحًا ثابتًا بلا اختلاق معرّف. */
            key={`${index}:${item.question}`}
            name={groupName}
          >
            <summary className={styles.question}>{item.question}</summary>
            <p className={styles.answer}>{item.answer}</p>
          </details>
        ))}
      </div>
    </section>
  );
}
