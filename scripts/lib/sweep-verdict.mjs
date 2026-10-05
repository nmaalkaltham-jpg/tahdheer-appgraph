/**
 * حكم الماسح — قاعدةٌ واحدة لكلّ الماسحات: لا نجاح بلا قياس.
 *
 * ─── العيب الذي أوجبها ───
 *
 * `sweep:submit` كان يبحث عن زرٍّ نصّه «أضف للسلة» وزرّ صفحة المنتج «أضف إلى
 * السلة»، فلا يُبذر سلّة ولا تُقاس حالة — ثمّ يخرج بـ‎0‎ لأنّ «لا خرق». وكلّ
 * ماسحٍ يحسب الخروقات وحدها يرث العيب نفسه: صفرُ قياسٍ يُقرأ «سليم».
 *
 * ─── رموز الخروج ───
 *
 *  · ‎0‎ — قيس شيءٌ فعلًا (‎measured > 0‎) ولا خرق ولا عطلَ قياس ولا عطلَ بنية.
 *  · ‎1‎ — خرقٌ في الواجهة، أو عطلُ قياس (الأداة لم تُصب ما قصدت)، أو صفرُ قياس.
 *  · ‎2‎ — عطلُ بنية: ردّت الواجهة البرمجيّة ‎429/500/502/503‎ على ما يلزم
 *    للقياس. ليس خرقًا في الواجهة ولا نجاحًا — يُعلَن باسمه.
 */

/** رموز الحالة التي تعني «البنية لم تُجب» لا «الواجهة أخطأت». */
export const INFRA_STATUSES = new Set([429, 500, 502, 503]);

/**
 * @param {{ measured: number, breaches?: number, instrumentErrors?: number, infraFailures?: number }} tally
 * @returns {{ code: 0 | 1 | 2, reason: string }}
 */
export function sweepVerdict({ measured, breaches = 0, instrumentErrors = 0, infraFailures = 0 }) {
  if (breaches > 0) return { code: 1, reason: `${breaches} خرقًا في الواجهة` };
  if (instrumentErrors > 0) return { code: 1, reason: `${instrumentErrors} عطلَ قياس — الأداة لم تُصب ما قصدت` };
  if (infraFailures > 0) return { code: 2, reason: `${infraFailures} عطلَ بنية (‎429/5xx‎) — لم يُقس ما يلزمه الخادم` };
  if (!Number.isFinite(measured) || measured <= 0) return { code: 1, reason: 'صفرُ قياس — الفحص لا يُثبت شيئًا' };
  return { code: 0, reason: `${measured} قياسًا · بلا خرق` };
}

/**
 * زرّ «أضف إلى السلة» بأسمائه المشروعة — بالاسم المتاح (accessible name)
 * لا بالنصّ المرئيّ وحده:
 *  · صفحة المنتج: «أضف إلى السلة»
 *  · بطاقة الكتالوج: «أضف للسلة» واسمها المتاح «أضف للسلة «العنوان»»
 * وما عداهما (أضف تقييمًا، أضف إلى المفضلة …) لا يطابق.
 */
export const ADD_TO_CART_NAME = /^أضف (?:إلى السلة|للسلة)(?=$|\s|«)/u;

export const isAddToCartName = (name) => typeof name === 'string' && ADD_TO_CART_NAME.test(name.trim());

/**
 * وصفُ فشل التحميل بصنفه: خنقٌ ‎429‎ (أو ‎503‎ بـRetry-After) عطلُ بنية، وما
 * عداه عطلُ قياس. كانت الماسحات تطبع «تعذّر التحميل» للحالتين فيُقرأ إنهاكُ
 * الواجهة البرمجيّة عطلًا في الصفحة.
 */
export function describeLoadFailure(error) {
  const message = String(error?.message ?? error).split('\n')[0];
  return error?.temporary === true ? `عطلُ بنية (خنق) · ${message}` : `تعذّر التحميل · ${message}`;
}
