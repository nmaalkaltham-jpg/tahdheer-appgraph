'use client';
// UNIT: STORE-URL
// FILE: store-url.tsx
// STYLE: none
// PARENT: LAYOUT-HOME, LIST-PANEL, PAGE-HEAD
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * عنوان المتجر العام، متاحًا لكل صفحات اللوحة.
 *
 * القيمة تُقرأ في التخطيط (مكوّن خادم) من STOREFRONT_URL وتُمرَّر هنا، فلا
 * تُخمَّن من اسم المضيف ولا تُكتب في الشيفرة. غيابها يعني `null`، وكل مستهلك
 * ملزم بإخفاء رابطه عندئذٍ بدل توليد عنوان لا يُفتح.
 *
 * ملاحظة تشغيلية: STOREFRONT_URL وسيط بناء أيضًا (apps/admin/Dockerfile)، لأن
 * صفحات اللوحة تُصيَّر مسبقًا فلا تلتقط قيمة تُمرَّر عند التشغيل وحدها.
 */

import { createContext, useContext, type ReactNode } from 'react';

const StoreUrlContext = createContext<string | null>(null);

export function StoreUrlProvider({
  value,
  children,
}: {
  value: string | null;
  children: ReactNode;
}) {
  return <StoreUrlContext.Provider value={value}>{children}</StoreUrlContext.Provider>;
}

/** عنوان أصل المتجر، أو `null` إن لم يُضبط. */
export function useStoreUrl(): string | null {
  return useContext(StoreUrlContext);
}

/**
 * رابط صفحة منتج عامّة. يُعيد `null` ما لم يكن المنتج منشورًا فعلًا: صفحة
 * منتج غير منشور تُعيد 404، ورابط يقود إلى 404 أسوأ من لا رابط.
 */
export function publicProductUrl(
  storeUrl: string | null,
  slug: string | null | undefined,
  status: string,
): string | null {
  if (storeUrl === null || !slug || status !== 'published') return null;
  return `${storeUrl}/products/${encodeURIComponent(slug)}`;
}
