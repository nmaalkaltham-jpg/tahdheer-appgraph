/**
 * Product-fact vocabulary — the ONE place a typed fact key becomes Arabic text.
 *
 * The API stores facts as `{ key, value }` where `key` is a machine key
 * (`slides_count | pages_count | worksheets_count | activities_count | …`,
 * per the catalog migration). Rendering that key raw shows a shopper
 * «slides_count»; rendering an invented label for an unknown key would be a
 * fabricated fact. So: known keys get their verified Arabic label + plural
 * agreement through the ONE shared `qty()` helper; an unknown key keeps its
 * own key as the label and its own value as the text — honest, never invented.
 *
 * No count, unit or label is ever defaulted: an absent fact stays absent.
 */
import { qty, type PluralForms } from '@tahdheer/design-system/format';
import type { FactIconName } from './icons';

/** The DTO shape the PDP receives (`ProductResource.facts[]`). */
export interface ProductFactDto {
  key: string;
  value: string | number;
}

/** A fact ready to render: label + counted text + its sprite icon. */
export interface FactView {
  key: string;
  /** Short label, e.g. «عدد الشرائح» — used by the pills' `title` and the glance table. */
  label: string;
  /** Full readable text, e.g. «22 شريحة» — what the pills and the original row show. */
  text: string;
  icon: FactIconName;
}

interface FactSpec {
  label: string;
  icon: FactIconName;
  /** Present only for counted facts; absent ⇒ the value is rendered verbatim. */
  forms?: PluralForms;
}

/**
 * Verified key vocabulary. Extend ONLY with keys the catalog actually stores —
 * a speculative entry here would put words on a page that no data supports.
 */
const FACT_VOCABULARY: Record<string, FactSpec> = {
  slides_count: {
    label: 'عدد الشرائح',
    icon: 'grid',
    forms: { zero: 'لا شرائح', one: 'شريحة واحدة', two: 'شريحتان', few: 'شرائح', many: 'شريحة' },
  },
  lessons_count: {
    label: 'عدد الدروس',
    // 'sheet' — the icon set has no book glyph; a missing name would render
    // nothing at all, so the closest real one is used deliberately.
    icon: 'sheet',
    forms: { zero: 'لا دروس', one: 'درس واحد', two: 'درسان', few: 'دروس', many: 'درسًا' },
  },
  pages_count: {
    label: 'عدد الصفحات',
    icon: 'files',
    forms: { zero: 'لا صفحات', one: 'صفحة واحدة', two: 'صفحتان', few: 'صفحات', many: 'صفحة' },
  },
  worksheets_count: {
    label: 'أوراق العمل',
    icon: 'sheet',
    forms: {
      zero: 'لا أوراق عمل',
      one: 'ورقة عمل واحدة',
      two: 'ورقتا عمل',
      few: 'أوراق عمل',
      many: 'ورقة عمل',
    },
  },
  activities_count: {
    label: 'الأنشطة',
    icon: 'sparkle',
    forms: { zero: 'لا أنشطة', one: 'نشاط واحد', two: 'نشاطان', few: 'أنشطة', many: 'نشاطًا' },
  },
  questions_count: {
    label: 'عدد الأسئلة',
    icon: 'sheet',
    forms: { zero: 'لا أسئلة', one: 'سؤال واحد', two: 'سؤالان', few: 'أسئلة', many: 'سؤالًا' },
  },
};

/** Numeric values arrive either as `int_value` (number) or as digits in text. */
function asCount(value: string | number): number | null {
  const n = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(n) ? n : null;
}

/** One fact → its rendered view. Unknown keys fall back to their own key/value. */
function toFactView(fact: ProductFactDto): FactView {
  const spec = FACT_VOCABULARY[fact.key];
  if (!spec) {
    // Honest fallback: we do not know this key, so we invent nothing for it.
    return { key: fact.key, label: fact.key, text: String(fact.value), icon: 'check' };
  }

  const count = spec.forms ? asCount(fact.value) : null;
  const text =
    spec.forms && count !== null ? qty(count, spec.forms) : String(fact.value);

  return { key: fact.key, label: spec.label, text, icon: spec.icon };
}

export function toFactViews(facts: readonly ProductFactDto[] | undefined): FactView[] {
  return (facts ?? []).map(toFactView);
}
