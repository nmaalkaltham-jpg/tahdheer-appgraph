// UNIT: TYPES
// FILE: types.ts
// STYLE: none
// PARENT: API-SDK-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * Interim DTO types for @tahdheer/api-sdk — typecheck-only until the OpenAPI
 * generator replaces this package. Field names follow MIP §4.2 verbatim
 * (OpenAPI owns this contract; the generated output supersedes this file).
 */

export interface ProductCardImageDto {
  url: string;
  alt: string;
  w: number;
  h: number;
}

export interface ProductCardBadgeDto {
  label: string;
  bg: string;
  fg: string;
}

/**
 * ProductCard DTO (MIP §4.2 — settles the verified legacy drift).
 * - discount is computed SERVER-side; the card derives it from the two prices
 *   only when the server omits it (packages/ui/src/productCardPrice.ts).
 * - Empty rating ⇒ ratingAvg/ratingCount are ABSENT (never 0).
 */
export interface ProductCardDto {
  id: number;
  sku: string;
  title: string;
  slug: string;
  url: string;
  categoryPath: string[];
  format: string;
  priceMinor: number;
  regularMinor?: number;
  discountPct?: number;
  onSale: boolean;
  /** ISO 8601 server timestamp; absent when not on a timed sale. */
  saleTo?: string;
  ratingAvg?: number;
  ratingCount?: number;
  image?: ProductCardImageDto;
  badge?: ProductCardBadgeDto;
  editable: boolean;
  digital: true;
  downloadable: boolean;
  /*
   * يبثّها `ProductCardResource` أيضًا (اختياريّة هنا حتّى يستبدل المولِّد هذا
   * الملفّ): نوع الملفّ، وتاريخ النشر، و«الأكثر مبيعًا» من طلباتٍ مدفوعة،
   * وعدّاد الشراء، وطبيعة المنتج. لا تُملأ بافتراضٍ حين تغيب.
   */
  resourceType?: string | null;
  publishedAt?: string | null;
  bestSeller?: boolean;
  purchaseCount?: number;
  productNature?: 'DIGITAL' | 'PHYSICAL' | 'SERVICE' | 'HYBRID' | 'ACCESS_BASED' | 'COMPOSITE' | null;
  /** الصفّ والفصل (`name_ar`) — حين حمّلت القائمة علاقتيهما. */
  gradeName?: string;
  semesterName?: string;
  /** صيغ المجموعة المنشورة (‎PDF/DOCX/PPTX…‎) — حين حُمِّلت الصيغ. */
  formats?: string[];
  /** للمنتج ملفّ عيّنةٍ عامّ — حين سألت القائمة (`ProductPreviews::withSampleFlag`). */
  hasSample?: boolean;
}
