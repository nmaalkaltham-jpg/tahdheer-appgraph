// UNIT: FAMILIES
// FILE: families.ts
// STYLE: none
// PARENT: REGISTRY-INDEX, REGISTRY-REGISTRY
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * @tahdheer/registry — the component families (MIP §2.5's 20 + Phase 2's
 * layout family; port of tahdheer-component-registry.php). Entry ids use
 * the family number as the `ff` half of `tah/{component}.{ff}{ii}`.
 */

export interface Family {
  /** 1-based family number → `ff` in entry ids. */
  n: number;
  id: string;
  label: string;
}

export const FAMILIES = [
  { n: 1, id: 'products', label: 'المنتجات' },
  { n: 2, id: 'categories', label: 'التصنيفات' },
  { n: 3, id: 'collections', label: 'المجموعات' },
  { n: 4, id: 'search', label: 'البحث' },
  { n: 5, id: 'filtering', label: 'التصفية' },
  { n: 6, id: 'offers', label: 'العروض' },
  { n: 7, id: 'conversion', label: 'التحويل' },
  { n: 8, id: 'trust', label: 'الثقة' },
  { n: 9, id: 'media', label: 'الوسائط' },
  { n: 10, id: 'content', label: 'المحتوى' },
  { n: 11, id: 'search-structure', label: 'بنية البحث' },
  { n: 12, id: 'answer-ai', label: 'الإجابة والذكاء' },
  { n: 13, id: 'navigation', label: 'التنقّل' },
  { n: 14, id: 'social', label: 'الاجتماعي' },
  { n: 15, id: 'brand-story', label: 'قصّة العلامة' },
  { n: 16, id: 'digital-product', label: 'المنتج الرقمي' },
  { n: 17, id: 'forms', label: 'النماذج' },
  { n: 18, id: 'information', label: 'المعلومات' },
  { n: 19, id: 'interactive', label: 'التفاعلي' },
  { n: 20, id: 'smart', label: 'الذكي' },
  // المرحلة الثانية: عائلة الحاويات (STUDIO-PHASE2-CONTRACT §2.4).
  { n: 21, id: 'layout', label: 'التخطيط' },
] as const satisfies readonly Family[];

export type FamilyId = (typeof FAMILIES)[number]['id'];
