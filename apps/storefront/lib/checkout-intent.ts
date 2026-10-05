/**
 * نيّة الشراء التي تحملها صفحة المنتج إلى الدفع في عنوان الصفحة:
 * `/checkout?item=<slug>&fmt=<variantSlug>&pay=apple`.
 *
 * زرّ Apple Pay في صفحة المنتج رابطٌ حقيقيّ (يعمل بلا JS)، فلا بدّ لصفحة
 * الدفع أن تقرأ ما حمله: تُضيف السطر إلى السلّة إن لم يكن فيها، وتختار
 * Apple Pay مسبقًا حين يتيحه الخادم والجهاز فعلًا. القواعد هنا خالصة (بلا شبكة ولا
 * DOM) كي تُختبر — والمكوّن يستدعيها.
 */
/* بامتداد «.ts» عمدًا: اختبارات node --test تحمّل الوحدة مباشرةً بلا مُجمِّع. */
import { FORMAT_PARAM } from './pdp-selection.ts';

export interface CheckoutIntent {
  /** سبيكة المنتج المطلوب، أو null حين لا `item`. */
  item: string | null;
  /** سبيكة الصيغة (أو رمزها) من `?fmt=`، أو null. */
  format: string | null;
  /** طريقة الدفع المطلوبة — `apple` وحدها معروفة اليوم. */
  pay: 'apple' | null;
}

const EMPTY: CheckoutIntent = { item: null, format: null, pay: null };

/** يقرأ النيّة من سلسلة الاستعلام؛ القيم الفارغة كالغائبة. */
export function parseCheckoutIntent(search: string | URLSearchParams | null | undefined): CheckoutIntent {
  if (search == null) return EMPTY;
  const params = typeof search === 'string' ? new URLSearchParams(search) : search;
  const clean = (value: string | null) => {
    const trimmed = value?.trim() ?? '';
    return trimmed === '' ? null : trimmed;
  };
  const item = clean(params.get('item'));
  const format = clean(params.get(FORMAT_PARAM));
  const pay = params.get('pay')?.trim().toLowerCase() === 'apple' ? 'apple' : null;
  return { item, format: item ? format : null, pay };
}

/** الشكل الأدنى لصيغةٍ من حمولة المنتج. */
export interface IntentVariant {
  id: number;
  slug: string;
  format?: string | null;
  purchasable?: boolean;
}

/**
 * معرّف الصيغة التي تسمّيها `fmt` — بالسبيكة أو بالرمز — أو null حين لا صيغة
 * مطلوبة أو لا مطابقة. صيغةٌ غير قابلة للشراء لا تُطابَق: إضافتها تُرفَض.
 */
export function variantIdFor(variants: readonly IntentVariant[], format: string | null): number | null {
  if (!format) return null;
  const wanted = format.toLowerCase();
  const hit = variants.find(
    (variant) =>
      variant.purchasable !== false &&
      (variant.slug.toLowerCase() === wanted || variant.format?.toLowerCase() === wanted),
  );
  return hit?.id ?? null;
}

/** الشكل الأدنى لسطرٍ في السلّة. */
export interface IntentCartLine {
  productId: number | string;
  variantId?: number | null;
}

/**
 * هل السلّة تحمل هذا السطر أصلًا؟ بلا صيغةٍ مطلوبة يكفي المنتج؛ ومع صيغةٍ
 * يجب أن يطابق السطر صيغتها — فلا يُضاف Word فوق PDF ولا يُهمَل.
 */
export function cartHasLine(
  lines: readonly IntentCartLine[],
  productId: number | string,
  variantId: number | null,
): boolean {
  return lines.some(
    (line) =>
      String(line.productId) === String(productId) &&
      (variantId === null || line.variantId === variantId),
  );
}

/** ما يعلنه `GET /api/v1/checkout/methods`. */
export interface CheckoutMethods {
  providers?: readonly string[];
  applePay?: boolean;
  /** علامات الدفع التي تُرسم في الصفحة، بترتيب الخادم. */
  marks?: readonly string[];
}

/**
 * هل تختار نيّةُ `pay=apple` Apple Pay مسبقًا؟ فقط حين يكون متاحًا فعلًا —
 * الخادم والجهاز معًا (`useApplePayAvailable` في lib/apple-pay.ts). وإلّا
 * تُتجاهل النيّة بصمت ويبقى الخيار الافتراضيّ.
 */
export function applePayPreselect(pay: CheckoutIntent['pay'], applePayAvailable: boolean): boolean {
  return pay === 'apple' && applePayAvailable === true;
}

/**
 * عنوان الصفحة بعد استهلاك النيّة: يُحذف `item` و`fmt` كي لا يُعاد الإضافة
 * عند التحديث، ويبقى `pay` لأنّ الطلب لم يُنشأ بعد.
 */
export function consumedSearch(search: string): string {
  const params = new URLSearchParams(search);
  params.delete('item');
  params.delete(FORMAT_PARAM);
  const rest = params.toString();
  return rest === '' ? '' : `?${rest}`;
}
