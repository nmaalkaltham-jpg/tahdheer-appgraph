// UNIT: _PANEL-VOCABULARY
// FILE: _panel-vocabulary.ts
// STYLE: none
// PARENT: STUDIO-CLIENT, WIRED-PANELS
// USES: STUDIO-DATA
// API: none
// MAP: docs/PROJECT_MAP.md

import type { TranslationRow } from '../studio-data';

/** اسم الملف من مسار الصورة — لا عنوان مخترع لصورة لا تحمل اسمًا. */
export function mediaName(url: string): string {
  const tail = url.split('/').pop();
  return tail === undefined || tail === '' ? url : tail;
}

export const INHERIT_FIELDS: { key: keyof TranslationRow['content']; label: string; long?: boolean }[] = [
  { key: 'title', label: 'العنوان' },
  { key: 'subtitle', label: 'العنوان الفرعي' },
  { key: 'slug', label: 'الرابط' },
  { key: 'shortDesc', label: 'الوصف المختصر', long: true },
  { key: 'description', label: 'الوصف الكامل', long: true },
];

/** الحقول التي يستطيع هذا الاستوديو تطبيق اقتراحها فعلًا. */
export const WRITING_FIELDS = [
  { field: 'title', label: 'عنوان المنتج' },
  { field: 'shortDesc', label: 'الوصف المختصر' },
  { field: 'description', label: 'الوصف الكامل' },
  { field: 'seoTitle', label: 'عنوان البحث' },
  { field: 'seoDesc', label: 'وصف البحث' },
] as const;

export type WritingField = (typeof WRITING_FIELDS)[number]['field'];

/** صفّ صيغة كما يصدره `Admin\ProductVariantController::rows()`. */
export interface VariantRow {
  id: number;
  sku: string;
  slug: string;
  title: string | null;
  format: string | null;
  status: string;
  position: number;
  isBridge: boolean;
  /**
   * §٢٧ — وزن الصيغة بالغرامات. `null` تعني «غير مقيس» لا «صفر».
   *
   * بوّابة النشر تطلبه من المنتج الماديّ، و`ShipmentExecutor` يرفض الشحن
   * بدونه. ولم يكن للمشغّل مدخلٌ واحد يكتبه، فيُطالَب بما لا يستطيع.
   */
  weightGrams: number | null;
  offer: {
    id: number;
    sellingModel: string;
    status: string;
    currency: string;
    regularMinor: number;
    saleMinor: number | null;
    effectiveMinor: number;
  } | null;
  deliverableFiles: number;
  genericDeliverableFiles: number;
}

export const VARIANT_STATUS_LABELS: Record<string, string> = {
  draft: 'مسوّدة',
  published: 'منشورة',
  retired: 'متقاعدة',
};

export const OFFER_STATUS_LABELS: Record<string, string> = {
  draft: 'مسوّدة',
  active: 'فعّال',
  paused: 'موقوف',
  retired: 'متقاعد',
};
