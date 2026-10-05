'use client';

/**
 * FAQ filter + accordion for مركز المساعدة.
 *
 * The artboard («Tahdheer - Content Hub», `is.help`) puts a search field in the
 * hero, implying a help-article index. No such index exists — the only real
 * help content is the store's published FAQ — so the field is rendered HERE,
 * beside the list it actually filters, and its label says so. A control that
 * promised to search articles that do not exist would be a fake control.
 *
 * The filter is purely client-side over an already-delivered list: every answer
 * is in the server-rendered HTML (empty query ⇒ every item), so crawlers,
 * printing and deep links keep the full FAQ.
 */
import { useId, useMemo, useState } from 'react';
import { qty, type PluralForms } from '@tahdheer/design-system/format';
import styles from './FaqSearch.module.css';

export interface FaqItem {
  id: number;
  question: string;
  answer: string;
}

const MATCH_FORMS: PluralForms = {
  zero: 'لا سؤال مطابق',
  one: 'سؤال واحد مطابق',
  two: 'سؤالان مطابقان',
  few: 'أسئلة مطابقة',
  many: 'سؤالًا مطابقًا',
};

export function FaqSearch({ items }: { items: readonly FaqItem[] }) {
  const [query, setQuery] = useState('');
  const fieldId = useId();
  const term = query.trim().toLowerCase();

  const shown = useMemo(
    () =>
      term
        ? items.filter((item) => `${item.question} ${item.answer}`.toLowerCase().includes(term))
        : items,
    [items, term],
  );

  return (
    <div className={styles.wrap}>
      <label className={styles.label} htmlFor={fieldId}>
        ابحث داخل الأسئلة الشائعة
      </label>
      <input
        className={styles.input}
        id={fieldId}
        type="search"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        placeholder="استرجاع، تحميل، فاتورة…"
        autoComplete="off"
      />

      <p className={styles.status} role="status" aria-live="polite">
        {term ? qty(shown.length, MATCH_FORMS) : ''}
      </p>

      {shown.length > 0 ? (
        <div className={styles.list}>
          {/* Shared `name` ⇒ native single-open accordion, no JavaScript. */}
          {shown.map((item) => (
            <details className={styles.item} key={item.id} name="help-faq">
              <summary className={styles.question}>{item.question}</summary>
              <p className={styles.answer}>{item.answer}</p>
            </details>
          ))}
        </div>
      ) : (
        <p className={styles.noMatch}>
          لا سؤال يطابق «{query.trim()}». جرّب كلمة أخرى، أو راسلنا مباشرة.
        </p>
      )}
    </div>
  );
}
