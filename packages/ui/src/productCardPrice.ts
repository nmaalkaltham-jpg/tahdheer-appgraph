// UNIT: PRODUCTCARD-PRICE
// FILE: productCardPrice.ts
// STYLE: none
// PARENT: PRODUCTCARD
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * حسابُ عرض السعر في بطاقة المنتج — دالّةٌ محضة بلا React ولا تنسيق (تُختبر
 * بـ`node --test`). البطاقة تعرض ما تقوله هذه الدالّة وحدها، فالاختبار يشهد
 * على الشيفرة التي تعمل فعلًا.
 *
 *  · القديم والوفر من سعرَين حقيقيّين وبيعٍ فعّال فقط؛ ولا شيء حين لا خصم.
 *  · النسبة: نسبة الخادم حين يرسلها موجبة (`PricingService::discountPct`)،
 *    وإلّا تُشتقّ من السعرين — حسابٌ لا تخمين.
 *  · غير متاح: لا سعر ولا خصم.
 */
export interface CardPriceInput {
  priceMinor: number;
  regularMinor?: number;
  discountPct?: number;
  onSale: boolean;
  unavailable?: boolean;
}

export interface CardPriceView {
  /** يُعرض السعر القديم مشطوبًا والوفر. */
  discounted: boolean;
  /** السعر المعتاد بالهللات حين `discounted`، وإلّا null. */
  wasMinor: number | null;
  /** الوفر بالهللات حين `discounted`، وإلّا null. */
  savingsMinor: number | null;
  /** نسبة الخصم الصحيحة (٠ حين لا خصم). */
  pct: number;
}

const NONE: CardPriceView = { discounted: false, wasMinor: null, savingsMinor: null, pct: 0 };

export function cardPrice(input: CardPriceInput): CardPriceView {
  const { priceMinor, regularMinor, discountPct, onSale, unavailable } = input;
  if (unavailable === true || !onSale) return NONE;
  if (typeof regularMinor !== 'number' || !Number.isFinite(regularMinor) || !Number.isFinite(priceMinor)) return NONE;
  if (!(regularMinor > priceMinor) || priceMinor < 0) return NONE;
  const pct =
    typeof discountPct === 'number' && discountPct > 0
      ? Math.round(discountPct)
      : Math.round((1 - priceMinor / regularMinor) * 100);
  return { discounted: true, wasMinor: regularMinor, savingsMinor: regularMinor - priceMinor, pct };
}
