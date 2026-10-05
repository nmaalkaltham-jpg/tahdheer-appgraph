/**
 * من «لا نتائج» إلى طلبٍ مملوء.
 *
 * حين يفهم البحث القصدَ (مادّة، صفّ، نوع…) ولا يجد منتجًا، فالسؤال طلبٌ
 * لم يُلبَّ — لا سؤالٌ خاطئ. صفحة الصفر تحمل ما فُهم إلى /request فيصل
 * الزائر إلى نموذجٍ مملوء بدل نموذجٍ فارغ يُعيد فيه ما كتبه للتوّ.
 *
 * دوالّ نقيّة بلا React — تُختبر بـ`node --test` (lib/__tests__/request-intent.test.mjs).
 */
import { routes } from './routes.ts';

/** حقولُ نموذج الطلب — والصيغةُ (format) ليست منها. */
export const REQUEST_DIMS = ['stage', 'grade', 'subject', 'semester', 'kind'] as const;
export type RequestDim = (typeof REQUEST_DIMS)[number];

const OPTION_KEY: Record<RequestDim, string> = {
  stage: 'stages',
  grade: 'grades',
  subject: 'subjects',
  semester: 'semesters',
  kind: 'kinds',
};

const MAX_NOTE = 200;

export function requestHref(filters: Partial<Record<RequestDim, string | string[] | null>>, note: string): string {
  const qs = new URLSearchParams();
  for (const dim of REQUEST_DIMS) {
    const value = filters[dim];
    if (typeof value === 'string' && value !== '') qs.set(dim, value);
  }
  const q = note.trim().slice(0, MAX_NOTE);
  if (q !== '') qs.set('q', q);
  const s = qs.toString();

  return s === '' ? routes.request : `${routes.request}?${s}`;
}

/**
 * القيمُ المبدئيّة للقوائم من معاملات الرابط — ما يوجد في القوائم وحده:
 * رابطٌ مزوَّر لا يختار قيمةً لا يعرضها النموذج.
 */
export function initialSelection(
  params: Record<string, string | string[] | undefined>,
  options: Record<string, { slug: string }[]>,
): Partial<Record<RequestDim, string>> {
  const out: Partial<Record<RequestDim, string>> = {};
  for (const dim of REQUEST_DIMS) {
    const value = params[dim];
    if (typeof value === 'string' && (options[OPTION_KEY[dim]] ?? []).some((o) => o.slug === value)) {
      out[dim] = value;
    }
  }

  return out;
}
