/**
 * هل يُعرض Apple Pay؟ — القاعدة الواحدة للمتجر كلّه (لوحة الشراء، الشريط
 * اللاصق، الدفع). لا يُعرض إلّا باجتماع شرطين:
 *
 *   ١. الخادم: `GET /api/v1/checkout/methods` يعلن `applePay: true` — أي أنّ
 *      المزوّد الافتراضيّ (Tap) يقبله بإعداده الحاليّ.
 *   ٢. الجهاز: `window.ApplePaySession?.canMakePayments?.() === true` — متصفّحٌ
 *      يملك Apple Pay في سياقٍ آمن. يُقرأ على العميل بعد التركيب وحده.
 *
 * فالتصيير على الخادم لا يرسم الزرّ أبدًا (لا يعرف الجهاز)، ولا وعدَ
 * بطريقةٍ لا يستطيع الجهاز إتمامها. الدفع نفسه في صفحة Tap المستضافة
 * (`src_apple_pay`) — لا ورقة دفعٍ داخل الصفحة: تلك تحتاج معرّف تاجرٍ من
 * Apple وملفّ ربط النطاق وشهادة التحقّق، ولا يملكها المتجر.
 */
import { useSyncExternalStore } from 'react';

/** قيمة `paymentMethod` في عقد الدفع (CheckoutController). */
export const APPLE_PAY_METHOD = 'apple_pay';

interface ApplePayWindow {
  isSecureContext?: boolean;
  ApplePaySession?: { canMakePayments?: () => unknown };
}

/** الشقّ الخالص من شرط الجهاز — يُختبر بلا متصفّح. أيّ استثناءٍ = لا. */
export function deviceCanApplePay(win: ApplePayWindow | null | undefined): boolean {
  if (!win || win.isSecureContext === false) return false;
  try {
    return win.ApplePaySession?.canMakePayments?.() === true;
  } catch {
    return false;
  }
}

/** الشرطان معًا. */
export function applePayAvailable(serverFlag: boolean, device: boolean): boolean {
  return serverFlag === true && device === true;
}

/* قدرة الجهاز لا تتغيّر أثناء الجلسة: تُقرأ مرّةً وتُحفظ. */
let cached: boolean | null = null;
const readDevice = () => {
  if (cached === null) cached = deviceCanApplePay(window as unknown as ApplePayWindow);
  return cached;
};
const noSubscribe = () => () => {};
const onServer = () => false;

/**
 * الخطّاف الوحيد لقرار العرض. على الخادم وأثناء الإماهة `false`، ثمّ قيمة
 * الجهاز الحقيقيّة — فلا يختلف التصيير الأوّل بين الخادم والعميل.
 */
export function useApplePayAvailable(serverFlag: boolean): boolean {
  const device = useSyncExternalStore(noSubscribe, readDevice, onServer);
  return applePayAvailable(serverFlag, device);
}
