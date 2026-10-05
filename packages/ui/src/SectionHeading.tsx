// UNIT: SECTIONHEADING
// FILE: SectionHeading.tsx
// STYLE: SectionHeading.module.css
// PARENT: UI-INDEX
// USES: CX
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * SectionHeading — the eyebrow + h2 pair every TahHomeBody.dc.html section
 * opens with. Server-component-friendly. The single-h1 rule lives at the page
 * level; this component never renders an h1.
 */
import { cx } from './cx';
import styles from './SectionHeading.module.css';

export interface SectionHeadingProps {
  title: string;
  /** Small over-line label, e.g. «تصفّح» / «مختارات». */
  eyebrow?: string;
  description?: string;
  /*
   * `h1` أُضيف لصفحاتٍ عنوانُها الرئيس هو هذا العنوان نفسه — `/samples`
   * كانت تبدأ بـ`h2` فتخلو من `h1` بالكامل. والافتراض يبقى `h2` فلا
   * يتغيّر موضعٌ قائم.
   */
  as?: 'h1' | 'h2' | 'h3';
  align?: 'start' | 'center';
}

export function SectionHeading({
  title,
  eyebrow,
  description,
  as: Tag = 'h2',
  align = 'start',
}: SectionHeadingProps) {
  return (
    <header
      className={cx(styles.wrap, align === 'center' && styles.center)}
      data-theme-component="section-heading"
    >
      {eyebrow ? <span className={styles.eyebrow}>{eyebrow}</span> : null}
      <Tag className={styles.title}>{title}</Tag>
      {description ? <p className={styles.desc}>{description}</p> : null}
    </header>
  );
}
