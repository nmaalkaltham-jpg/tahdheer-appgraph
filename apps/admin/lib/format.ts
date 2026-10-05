// UNIT: LIB-FORMAT
// FILE: format.ts
// STYLE: none
// PARENT: AI-REQUEST-LOG, AI-USAGE-PANEL, ALERTS, ASSET-LIBRARY, AUDIT-PANEL, BROKEN-LINKS-PANEL +81
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * أدوات العرض الموحّدة (MIP §2.4 و§3): أرقام غربية للمال والإحصاءات،
 * «ر.س» عملة منفصلة، وكل رمز/بريد/معرّف داخل bdi باتجاه ltr.
 *
 * لا شيء هنا يخترع قيمة: المُدخل الغائب يعود «—» لا صفرًا.
 */

const DASH = '—';

const NUMBER = new Intl.NumberFormat('en-US');
const MONEY = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** هللات ← ريالات نصًّا («400.00»). القيمة الغائبة تُعرض شرطة. */
export function money(minor: number | null | undefined): string {
  if (minor === null || minor === undefined || Number.isNaN(minor)) return DASH;
  return MONEY.format(minor / 100);
}

export function count(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return DASH;
  return NUMBER.format(value);
}

/** بايتات إلى وحدة مقروءة — بلا تقريب يخفي فرقًا معنويًّا. */
export function bytes(value: number): string {
  if (value < 1024) return `${count(value)} بايت`;
  if (value < 1024 * 1024) return `${count(Math.round(value / 1024))} ك.ب`;
  return `${count(Math.round((value / (1024 * 1024)) * 10) / 10)} م.ب`;
}

/** ريالات نصًّا ← هللات عددًا صحيحًا. يعيد null إن كان المدخل غير رقمي. */
export function riyalsToMinor(input: string): number | null {
  const trimmed = input.trim();
  if (trimmed === '') return null;
  const value = Number(trimmed);
  if (!Number.isFinite(value) || value < 0) return null;
  return Math.round(value * 100);
}

/** هللات ← نص ريالات صالح لحقل إدخال. */
export function minorToRiyals(minor: number | null | undefined): string {
  if (minor === null || minor === undefined) return '';
  return (minor / 100).toFixed(2);
}

export function dateTime(iso: string | null | undefined): string {
  if (!iso) return DASH;
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return DASH;
  return new Intl.DateTimeFormat('ar-SA-u-ca-gregory-nu-latn', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

/**
 * لحظة مطلقة ← قيمة `datetime-local` بالتوقيت المحلّي للمحرّر.
 *
 * نُقلت من شاشة «تحرير المنتج» حين اندمجت في الاستوديو: هي والدالّتان بعدها
 * كنّ السطح الوحيد الذي يحرّر `sale_from` / `sale_to`، فلولا نقلهنّ لصار
 * الدمج حذفًا لقدرة.
 */
export function isoToLocalInput(iso: string | null | undefined): string {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  const pad = (value: number) => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * قيمة `datetime-local` ← ISO بالتوقيت العالمي.
 *
 * الحقل يعطي نصًّا بلا منطقة زمنية، وإرساله كما هو يجعل الخادم يفسّره
 * بمنطقته هو لا بمنطقة المحرّر — ساعاتٌ من الفرق في بداية تخفيض ونهايته.
 * `null` تعني حدًّا مفتوحًا، و`false` تعني نصًّا غير صالح.
 */
export function localInputToIso(value: string): string | null | false {
  const trimmed = value.trim();
  if (trimmed === '') return null;
  const date = new Date(trimmed);
  if (Number.isNaN(date.getTime())) return false;
  return date.toISOString();
}

/** تسميات عربية لحالات المنتج والطلب — المصدر الوحيد لنصوص الشارات. */
export const PRODUCT_STATUS_LABELS: Record<string, string> = {
  draft: 'مسوّدة',
  published: 'منشور',
  retired: 'مسحوب',
};

export const ORDER_STATUS_LABELS: Record<string, string> = {
  PENDING: 'قيد الإنشاء',
  AWAITING_PAYMENT: 'بانتظار الدفع',
  PAID: 'مدفوع',
  PROCESSING: 'قيد التنفيذ',
  COMPLETED: 'مكتمل',
  FAILED: 'فشل',
  CANCELLED: 'ملغى',
  REFUNDED: 'مسترد',
  PARTIALLY_REFUNDED: 'مسترد جزئيًا',
};

export const ENTITLEMENT_STATUS_LABELS: Record<string, string> = {
  ACTIVE: 'فعّالة',
  EXPIRED: 'منتهية',
  REVOKED: 'مسحوبة',
  SUSPENDED: 'موقوفة',
};

export const SYNONYM_STATE_LABELS: Record<string, string> = {
  active: 'مفعَّل',
  pending: 'معلّق',
  negative: 'موقوف',
};

export const SYNONYM_SOURCE_LABELS: Record<string, string> = {
  manual: 'يدوي',
  learned: 'مُتعلَّم',
  seed: 'أوّلي',
  ai_suggested: 'مقترح بالذكاء',
};

export const REVOCATION_POLICY_LABELS: Record<string, string> = {
  immediate: 'سحب فوري للوصول',
  grace_3d: 'سحب بعد مهلة ٣ أيام',
  keep: 'إبقاء الوصول',
  updates_only: 'سحب التحديثات المستقبلية فقط',
  license_only: 'سحب الترخيص فقط',
};
