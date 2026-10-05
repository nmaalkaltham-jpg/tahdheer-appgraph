/**
 * Toast — بطاقة الإشعار الداكنة.
 *
 * `durationMs={0}` في كل خليّة: الافتراضي 3000مل يُخفي البطاقة قبل أن تُلتقط
 * الصورة، فتصير المعاينة فارغة بلا سبب ظاهر. الصفر يعني «حتى يُغلق يدويًّا»
 * وهو سلوك حقيقي من العقد لا حيلة معاينة.
 *
 * و`onDismiss` مُمرَّرة: زرّ الإغلاق لا يُصيَّر بدونها — وهو الصواب (لا زرّ
 * بلا فعل)، لكن معاينةً تُسقطها تُخفي نصف المكوّن. المرور بالصفر مع الإغلاق
 * اليدوي هو التركيب الذي يفرضه WCAG 2.2.1: مهلة موقوفة ومخرج دائم.
 */

import { Toast } from '@tahdheer/ui';

const box: React.CSSProperties = { maxWidth: 380 };

/** نجاح: نبرة الحالة العادية. */
export function Status() {
  return (
    <div style={box}>
      <Toast id="t-1" tone="status" message="أُضيف المنتج إلى السلة." onDismiss={() => {}} durationMs={0} />
    </div>
  );
}

/** خطأ: نبرة الفشل — نصّ يقول ما فشل لا «حدث خطأ». */
export function Error() {
  return (
    <div style={box}>
      <Toast id="t-2" tone="error" message="تعذّر تطبيق الكوبون: انتهت صلاحيته." onDismiss={() => {}} durationMs={0} />
    </div>
  );
}

/** رسالة طويلة: البطاقة تلتفّ ولا تقصّ النصّ. */
export function LongMessage() {
  return (
    <div style={box}>
      <Toast
        id="t-3"
        tone="status"
        message="حُفظت التعديلات على الخادم. ستظهر للزوّار بعد النشر من زرّ «نشر» في أعلى الصفحة."
        onDismiss={() => {}}
        durationMs={0}
      />
    </div>
  );
}
