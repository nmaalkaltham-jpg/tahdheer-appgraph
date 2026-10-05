import { ChevronDownIcon } from './icons';
import styles from './pdp.module.css';

/**
 * FAQ accordion — Storefront Product.dc.html: one question open at a time,
 * the first open on load, chevron turning 180°. Built on exclusive
 * `<details name>` so it opens, closes and stays single-open without script.
 *
 * `variant="rows"` is the list inside «المعاينة والأسئلة الشائعة» (hairline
 * rows); `variant="cards"` is the «قبل الشراء» band (white cards) and the
 * mobile «أسئلة شائعة» tab. The questions are the SEO service's visible FAQs
 * — the same array the FAQPage graph is built from.
 */
export interface PdpFaqItem {
  question: string;
  answer: string;
}

export function PdpFaq({
  items,
  name,
  variant,
}: {
  items: readonly PdpFaqItem[];
  /** Exclusive-group name — distinct per list so two lists don't close each other. */
  name: string;
  variant: 'rows' | 'cards';
}) {
  if (items.length === 0) return null;

  return (
    <div className={variant === 'rows' ? styles.faqRows : styles.faqCards}>
      {items.map((item, index) => (
        <details className={styles.faqItem} name={name} open={index === 0} key={`${index}-${item.question}`}>
          <summary className={styles.faqQ}>
            <span>{item.question}</span>
            <ChevronDownIcon size={variant === 'rows' ? 17 : 18} className={styles.faqChevron} />
          </summary>
          <p className={styles.faqA}>{item.answer}</p>
        </details>
      ))}
    </div>
  );
}
