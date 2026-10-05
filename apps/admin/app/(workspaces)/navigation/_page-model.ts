// UNIT: _PAGE-MODEL
// FILE: _page-model.ts
// STYLE: none
// PARENT: PAGE-NAVIGATION
// USES: MODEL
// API: none
// MAP: docs/PROJECT_MAP.md

import type { MenuNode, ServerNode } from './model';

export type ViewKey = 'tree' | 'map' | 'table' | 'live';
export type SectionKey = 'studio' | 'diagnostics' | 'publish';

export interface HistoryEntry {
  label: string;
  nodes: MenuNode[];
}

/** صفّ في لوح «نتائج عبر كل القوائم». */
export interface SearchRow {
  key: string;
  id: string;
  location: string;
  menuName: string;
  label: string;
  typeName: string;
  path: string;
  url: string;
}

/** سقف الصفوف المرسومة في لوح البحث — الباقي يُقال عدده لا يُخفى. */
export const SEARCH_ROWS_SHOWN = 30;

export const VIEW_TABS: [ViewKey, string, string][] = [
  ['map', 'الخريطة البصرية', 'map'],
  ['tree', 'الشجرة المنظَّمة', 'tree'],
  ['live', 'معاينة القائمة', 'monitor'],
  ['table', 'الجدول المختصر', 'table'],
];

export const SECTION_TABS: [SectionKey, string, string][] = [
  ['studio', 'الاستوديو', 'layers'],
  ['diagnostics', 'التشخيص', 'shield'],
  ['publish', 'النشر والدورة', 'publish'],
];

/**
 * ما لا نقطة نهاية له فعلًا — تحقّقتُ من كل سطر مقابل routes/api-admin.php.
 *
 * حُذف من هذه القائمة سطران كانا كذبًا: «مواقع القالب» (المسجَّلة ثلاثة في
 * `MenuTree::LOCATIONS` ولكلٍّ مسوّدة ونشر وتراجع) و«فاحص الروابط» (موجود على
 * `POST /admin/navigation/link-check` بحماية SSRF). إعلان الغياب الكاذب يمنع
 * المشغّل من استعمال قدرة يملكها.
 */
export const MISSING_SURFACES: { title: string; detail: string; endpoint: string }[] = [
  {
    title: 'مكتبة القوائم وتعدّدها',
    detail:
      'قوائم متعددة قابلة للتسمية والتكرار ثم إسنادها للمواقع — اليوم لكل موقع مسوّدة واحدة لا مكتبة.',
    endpoint: 'GET/POST /api/v1/admin/navigation/menus',
  },
  {
    title: 'قراءة المراجعات ومقارنتها',
    detail:
      'المراجعات تُكتب فعلًا عند كل حفظ (navigation_revisions) ويستعملها التراجع، لكن لا نقطة قراءة تعرضها أو تقارنها بالمسوّدة.',
    endpoint: 'GET /api/v1/admin/navigation/{location}/revisions',
  },
  {
    title: 'سجل التدقيق المقروء',
    detail:
      'كل حفظ ونشر وتراجع يُسجَّل على الخادم عبر AdminAuditLogger، ولا توجد نقطة تعيد السجل للعرض.',
    endpoint: 'GET /api/v1/admin/navigation/audit',
  },
  {
    title: 'الأرشيف والحذف النهائي',
    detail: 'أرشفة قائمة واستعادتها، والحذف النهائي بتأكيد بالاسم وتصدير قبل التنفيذ.',
    endpoint: 'POST /api/v1/admin/navigation/menus/{id}/archive',
  },
  {
    title: 'رؤية العنصر (جمهور/جهاز)',
    detail:
      'إظهار عنصر لفئة دون أخرى أو لجهاز دون آخر — الحقل غير موجود في عقد الشجرة ولا في تطبيع الخادم، فلا يُعرض زرّ لا يفعل شيئًا.',
    endpoint: 'حقل visibility في MenuTree + مُصيِّر المتجر',
  },
];

/* ───── أنواع ردود الخادم ───── */

export interface MenuPayload {
  location: string;
  name: string;
  version: number;
  publishedVersion: number | null;
  publishedAt: string | null;
  hasUnpublishedChanges: boolean;
  draft: ServerNode[];
  published: ServerNode[] | null;
}

export interface PublishOperation {
  id: string;
  status: string;
  currentStep: string;
  baseRevision: number | null;
  targetRevision: number | null;
  snapshotRevision: number | null;
  failureCode: string | null;
  failureDetail: string | null;
  verifiedAt: string | null;
  completedAt: string | null;
}

export const OPERATION_COMPLETED = 'COMPLETED';
export const OPERATION_ROLLED_BACK = 'ROLLED_BACK';

/** رسالة الفشل كما يرويها الخادم — لا «تعذّر» مبهمة. */
export function failureText(operation: PublishOperation): string {
  const detail = operation.failureDetail?.trim();
  if (detail) return detail;
  return operation.failureCode === null
    ? `لم تكتمل العملية (${operation.currentStep}).`
    : `لم تكتمل العملية: ${operation.failureCode}.`;
}

export const TIME_FORMAT = new Intl.DateTimeFormat('ar-SA-u-ca-gregory-nu-latn', {
  dateStyle: 'short',
  timeStyle: 'short',
});
