'use client';
/**
 * مالكُ «الصيغة المختارة» — موضعٌ واحد تقرأ منه كلّ أدوات الشراء.
 *
 * ─── العطل الذي وُجد هذا من أجله ───
 *
 * الشريط اللاصق أسفل الجوّال هو أداة الشراء الوحيدة دون ‎768px‎. وكان لا
 * يعرف الصيغة: يقرأ سعر `product_prices` وينادي `useAddToCart(productId)`
 * بلا صيغة. فمن اختار PowerPoint رأى ثمن Word في الشريط ودفعه، ثمّ سُلِّم
 * ملفّ Word. والعلاج المؤقّت كان **إسقاط الشريط** لكلّ منتجٍ متعدّد
 * الصيغ — فبقيت أوسع شريحةٍ من الزوّار بلا زرّ شراءٍ ثابت أصلًا.
 *
 * ─── ولماذا سياقٌ لا حالتان ───
 *
 * المُبدِّل في أعلى الصفحة والشريط في آخرها، وبينهما شجرةٌ خادميّة كاملة.
 * ولا سبيل إلى أن يعرف الثاني اختيار الأوّل إلّا بمالكٍ فوقهما. ونسخُ
 * الحالة في الاثنين يعني مصدرين لحقيقةٍ واحدة يفترقان عند أوّل نقرة.
 *
 * `position: sticky` هو أيضًا سبب بقاء الشريط في آخر المقال لا داخل
 * المُبدِّل: عنصرٌ لاصقٌ يلتصق داخل كتلته الحاوية، فلو صُيِّر في سكّة
 * الشراء لاختفى مع اختفائها من الشاشة.
 */
import { createContext, useContext, useMemo, useState, type ReactNode } from 'react';
import { initialVariantId } from '../../lib/pdp-selection';
import type { VariantDto } from './FormatSelector';

export interface VariantSelection {
  variants: VariantDto[];
  /** المختارة الآن، أو `null` حين لا صيغ أصلًا. */
  selected: VariantDto | null;
  select: (id: number) => void;
}

const SelectionContext = createContext<VariantSelection | null>(null);

export function VariantSelectionProvider({
  variants,
  initialFormat,
  children,
}: {
  variants: VariantDto[];
  /** Raw `?fmt=` value; an unknown or unpurchasable slug falls back silently. */
  initialFormat?: string | null;
  children: ReactNode;
}) {
  /*
   * الافتراضيّ أوّل صيغةٍ قابلة للشراء لا أوّل صيغةٍ مطلقًا — فتحُ الصفحة
   * على خيارٍ متوقّفٍ يجعل زرّ الشراء معطَّلًا بلا سبب ظاهر.
   */
  const fallback = variants.find((variant) => variant.purchasable) ?? variants[0] ?? null;
  const [selectedId, setSelectedId] = useState<number | null>(() => initialVariantId(variants, initialFormat));

  const value = useMemo<VariantSelection>(
    () => ({
      variants,
      selected: variants.find((variant) => variant.id === selectedId) ?? fallback,
      select: setSelectedId,
    }),
    [variants, selectedId, fallback],
  );

  return <SelectionContext.Provider value={value}>{children}</SelectionContext.Provider>;
}

/** `null` حين لا مزوّد فوق المكوّن — والمُبدِّل يملك حالته عندئذ. */
export function useVariantSelection(): VariantSelection | null {
  return useContext(SelectionContext);
}
