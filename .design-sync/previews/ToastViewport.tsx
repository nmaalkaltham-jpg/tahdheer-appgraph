/**
 * ToastViewport — منطقة البثّ الحيّة.
 *
 * تُصيَّر من التركيب لا وحدها: هي `role="status" aria-live="polite"` موجودة في
 * DOM منذ التركيب ليُعلن قارئ الشاشة الإضافات بأدب.
 *
 * الحدّ TOAST_LIMIT = 2 مفروض بنيويًّا: الخليّة الثالثة تمرّر أربعة إشعارات
 * وتُظهر أن الأحدثَين وحدهما يبقيان — وهذا اختبار للعقد لا زينة.
 */

import { ToastViewport, TOAST_LIMIT, type ToastData } from '@tahdheer/ui';

const shell: React.CSSProperties = { position: 'relative', minHeight: 140, minWidth: 380 };

/** إشعار واحد. */
export function Single() {
  const toasts: ToastData[] = [{ id: 'v-1', tone: 'status', message: 'حُفظت التعديلات.' }];
  return (
    <div style={shell}>
      <ToastViewport toasts={toasts} onDismiss={() => {}} durationMs={0} />
    </div>
  );
}

/** الحدّ الأقصى المسموح: اثنان معًا. */
export function AtLimit() {
  const toasts: ToastData[] = [
    { id: 'v-1', tone: 'status', message: 'أُضيف المنتج إلى السلة.' },
    { id: 'v-2', tone: 'error', message: 'تعذّر تطبيق الكوبون.' },
  ];
  return (
    <div style={shell}>
      <ToastViewport toasts={toasts} onDismiss={() => {}} durationMs={0} />
    </div>
  );
}

/** أربعة مُرسَلة، اثنان مصيَّران: الأحدث يفوز، والحدّ لا يُتجاوز. */
export function OverLimitKeepsNewest() {
  const toasts: ToastData[] = [
    { id: 'v-1', tone: 'status', message: `الأقدم — يُسقَط (الحدّ ${TOAST_LIMIT})` },
    { id: 'v-2', tone: 'status', message: 'يُسقَط أيضًا' },
    { id: 'v-3', tone: 'status', message: 'حُفظت المسوّدة على الخادم.' },
    { id: 'v-4', tone: 'error', message: 'تعذّر الاتصال بالخادم.' },
  ];
  return (
    <div style={shell}>
      <ToastViewport toasts={toasts} onDismiss={() => {}} durationMs={0} />
    </div>
  );
}
