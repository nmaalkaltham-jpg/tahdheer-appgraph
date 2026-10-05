// UNIT: FORMAT
// FILE: format.ts
// STYLE: none
// PARENT: DESIGN-SYSTEM-INDEX, PRODUCTCARD
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * @tahdheer/design-system — format.ts
 * The ONE formatNumber/formatMoney utility pair + Arabic plural agreement.
 *
 * MONEY CONTRACT (revised 2026-09-23 with the storefront redesign, Storefront
 * Home/Product .dc.html): every customer-facing amount is `formatMoney(minor)`
 * → { amount: "1,250.00", currency: "SAR" } — Western digits, en-US grouping,
 * ALWAYS two decimals, the ISO code as its own (smaller) part after the
 * amount, the pair wrapped by the consumer in <bdi dir="ltr">. Stored value =
 * integer minor units (halalas) from the server; this file only formats.
 * No component may format money itself (toFixed, Intl, a literal «ر.س» or
 * «SAR»): apps/storefront/lib/__tests__/money-contract.test.mjs fails if one
 * does. The admin console (operator UI) keeps its own apps/admin/lib/format.ts.
 *
 * BIDI — MEASURED 2026-09-10, not assumed. Every string this file returns is
 * RLM-free: formatMoney(125000).amount is «1,250.00» (U+0031 U+002C … U+0030)
 * and CURRENCY is U+0053 U+0041 U+0052 («SAR»). That is the ONLY reason <bdi dir="ltr">
 * is safe here, and the reason does not generalise. This file exports NO date
 * formatter, and the dir="ltr" instruction above must never be extended to one:
 * Intl.DateTimeFormat('ar-SA-u-ca-gregory-nu-latn', ...) emits U+200F (RLM)
 * INSIDE the date — «10‏/09‏/2026، 04:45 م» measures as
 * 0031 0030 200F 002F 0030 0039 200F 002F 0032 0030 0032 0036 060C ... — and
 * forcing dir="ltr" around that tears the leading day off the front (24 admin
 * sites were corrected for exactly this). If a date/time formatter is ever
 * added to this file, measure its code points FIRST and give it its own
 * wrapping rule; do not inherit this one.
 */

/** Canonical currency label — the ISO 4217 code, as the storefront design prints it. */
export const CURRENCY = 'SAR';

export interface MoneyParts {
  /** Western-digit amount string, e.g. "1,250" or "12.5" — render inside <bdi dir="ltr">. */
  amount: string;
  /** «SAR» — render as its own span after the amount. */
  currency: typeof CURRENCY;
}

const westernNumber = new Intl.NumberFormat('en-US', {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
});

const westernMoney = new Intl.NumberFormat('en-US', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

/**
 * Format a minor-unit amount (halalas; 100 minor = 1 SAR) as <bdi>-ready parts.
 * Always two decimals («35.00», «1,250.50») — the storefront money contract.
 *
 * INVARIANT — `minor` MUST be an INTEGER. Rounding money is the server's
 * authority alone; this function must never be the place a value is rounded.
 * Integer input is exact (measured: 200k random values up to 1e9 minor
 * round-trip with zero drift). A float does NOT round-trip — it is rounded
 * SILENTLY by maximumFractionDigits:2 above, so formatMoney(999.5) renders
 * "10" where the true value is 9.995. TypeScript's `number` cannot express the
 * integer constraint without breaking every call site, so the invariant has to
 * be enforced upstream: the boundary that decodes an API DTO into *Minor
 * fields is what must reject a non-integer, not this formatter.
 */
export function formatMoney(minor: number): MoneyParts {
  return { amount: westernMoney.format(minor / 100), currency: CURRENCY };
}

/** Western-digit number with en-US grouping (tabular-nums surfaces). */
export function formatNumber(n: number): string {
  return westernNumber.format(n);
}

/** Arabic plural agreement forms for the 0 / 1 / 2 / 3–10 / 11+ buckets. */
export interface PluralForms {
  /** n = 0 — full phrase, e.g. «لا تقييمات». */
  zero: string;
  /** n = 1 — full singular phrase (numeral omitted), e.g. «تقييم واحد». */
  one: string;
  /** n = 2 — full dual phrase (numeral omitted), e.g. «تقييمان». */
  two: string;
  /** 3 ≤ n ≤ 10 — plural noun; rendered as «{n} {few}», e.g. «تقييمات». */
  few: string;
  /** n ≥ 11 — singular accusative; rendered as «{n} {many}», e.g. «تقييمًا». */
  many: string;
}

/**
 * qty(n, forms) — single Arabic plural-agreement helper (MIP §2.4).
 * 0 and 1 and 2 return the full phrase; 3–10 and 11+ prepend the
 * Western-digit numeral.
 */
export function qty(n: number, forms: PluralForms): string {
  if (n === 0) return forms.zero;
  if (n === 1) return forms.one;
  if (n === 2) return forms.two;
  if (n >= 3 && n <= 10) return `${formatNumber(n)} ${forms.few}`;
  return `${formatNumber(n)} ${forms.many}`;
}
