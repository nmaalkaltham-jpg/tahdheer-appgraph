// UNIT: LIB-EXPORT-CSV
// FILE: export-csv.ts
// STYLE: none
// PARENT: EXPORT-CSV, LIST-PANEL, PAGE-CUSTOMERS, PAGE-ORDERS, PAGE-REVIEWS, _COUPON-RULES
// USES: none
// API: /admin/{orders,customers,products}
// MAP: docs/PROJECT_MAP.md

/**
 * تصدير CSV — الميزة المشتركة بين كل لوحات المتاجر المقارَنة، وكانت غائبة هنا.
 *
 * ─── لماذا لم تُبنَ قبلًا، ولماذا تُبنى الآن ───
 *
 * كان التعليل في `command-palette.tsx` أنّه «لا نظير خادميًّا موحّدًا لها في
 * api-admin.php». والاعتراض كان على نقطة تصديرٍ **واحدة**، لا على التصدير
 * نفسه: نقاط `/admin/{orders,customers,products}` تقبل `page` و`perPage`
 * (≤١٠٠) و`q` والمرشّحات — فالعميل يستطيع تجميع الصفوف كاملةً بأمانة، وهو ما
 * يفعله `pagedRows` أدناه. لا اختراع بيانات ولا نقطة نهاية موهومة.
 *
 * ─── ثلاثة تفاصيل ليست تجميلية ───
 *
 * ١. **BOM**: Excel على ويندوز يقرأ ملفًّا بلا علامة ترتيب بايت بترميز النظام
 *    المحلّي، فيصير النصّ العربي رموزًا مشوّهة. الحرف `U+FEFF` وحده يمنع ذلك.
 *
 * ٢. **CRLF واقتباس RFC 4180**: الفاصلة تكثر في العناوين العربية، والاقتباس
 *    المزدوج يُضاعَف داخل الحقل لا يُهرَّب بشرطة مائلة.
 *
 * ٣. **حقن الصيغ**: حقلٌ نصّي يبدأ بـ`=` أو `+` أو `-` أو `@` يُنفَّذ صيغةً
 *    عند فتح الملفّ في Excel — وعناوين المنتجات وبُرد العملاء **مُدخَلات
 *    مستعمِلين**. يُسبق بفاصلة عليا فيُعرض نصًّا ولا يُنفَّذ. والحماية على
 *    النصوص وحدها: `-500` رقمٌ سالب مشروع لا صيغة، فلا يُمسّ.
 */

export interface CsvColumn<T> {
  header: string;
  value: (row: T) => string | number | null | undefined;
}

const FORMULA_START = /^[=+\-@\t\r]/;

function cell(value: string | number | null | undefined): string {
  if (value === null || value === undefined) return '';
  if (typeof value === 'number') return Number.isFinite(value) ? String(value) : '';

  // رقمٌ كُتب نصًّا يبقى رقمًا — الحماية للنصوص التي تُقرأ صيغةً فقط.
  const escaped =
    FORMULA_START.test(value) && !Number.isFinite(Number(value)) ? `'${value}` : value;

  return /[",\r\n]/.test(escaped) ? `"${escaped.replace(/"/g, '""')}"` : escaped;
}

export function toCsv<T>(columns: readonly CsvColumn<T>[], rows: readonly T[]): string {
  const lines = [columns.map((c) => cell(c.header)).join(',')];
  for (const row of rows) lines.push(columns.map((c) => cell(c.value(row))).join(','));
  return `﻿${lines.join('\r\n')}\r\n`;
}

/** تنزيل نصّ CSV باسم مؤرَّخ — التاريخ يمنع دهس تصديرٍ سابق في مجلّد التنزيلات. */
export function downloadCsv(baseName: string, csv: string): void {
  const stamp = new Date().toISOString().slice(0, 10);
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  const a = document.createElement('a');
  a.href = url;
  a.download = `${baseName}-${stamp}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  // الإفراج مؤجَّل: الإبطال الفوري يقطع التنزيل في بعض المتصفّحات قبل بدئه.
  setTimeout(() => URL.revokeObjectURL(url), 10_000);
}

/** إضافة معاملات إلى مسار قد يحمل بعضها أصلًا (مرشّح الشاشة يجب أن يُصدَّر معها). */
export function withParams(path: string, extra: Record<string, string>): string {
  const [base, existing = ''] = path.split('?');
  const params = new URLSearchParams(existing);
  for (const [key, value] of Object.entries(extra)) params.set(key, value);
  return `${base}?${params.toString()}`;
}
