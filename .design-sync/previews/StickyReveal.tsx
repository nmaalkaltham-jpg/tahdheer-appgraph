/**
 * StickyReveal — أقسامٌ تتعاقب ولوحةٌ تثبت بجانبها.
 *
 * ─── لماذا `panel` اختياريّة، وما تُثبته الخليّتان ───
 *
 * العقد يقول ذلك صراحةً: مستند الاستوديو يحمل **نصًّا** لا شجرة React، فلا
 * يستطيع تمرير عقدة. وبدل مكوّنٍ ثانٍ «نسخة الاستوديو» صارت اللوحة
 * اختياريّة — وغيابُها يُصيَّر **رقم الخطوة**. مكوّنٌ واحد يخدم السطحين ولا
 * تنشقّ نسختان تنحرفان.
 *
 * فالخليّتان هما هذان السطحان بالضبط: `WithPanels` ما يمرّره تطبيق React،
 * و`NumbersOnly` ما يصيّره مستند الاستوديو. ليستا تنويعًا جماليًّا.
 *
 * ─── وما لا تُظهره اللقطة ───
 *
 * التثبيت والتعاقب يقعان بالتمرير. البطاقة تعرض الحالة الأولى — أوّل قسمٍ
 * نشط ولوحتُه — وهي ما يراه الزائر قبل أن يمرّر.
 *
 * ─── والمحتوى رحلة الشراء الحقيقيّة ───
 *
 * الخطوات الثلاث هي ما يفعله المعلّم فعلًا في المتجر: يُضيّق بالمنهج، ثمّ
 * يختار الصيغة، ثمّ يستلم فورًا بعد الدفع.
 */

import { StickyReveal, type StickyRevealSection } from '@tahdheer/ui';

/** لوحةٌ بسيطة — يمرّرها المستهلك؛ المكوّن لا يشحن رسومًا. */
function Panel({ label, hint }: { label: string; hint: string }) {
  return (
    <div
      style={{
        display: 'grid',
        gap: 8,
        placeContent: 'center',
        minBlockSize: 180,
        padding: 20,
        borderRadius: 16,
        background: 'var(--bg-soft, #f4f7f5)',
        border: '1px solid var(--border, #e3e7e5)',
        textAlign: 'center',
      }}
    >
      <b style={{ fontSize: 18, color: 'var(--ink, #14261d)' }}>{label}</b>
      <span style={{ fontSize: 13, color: 'var(--text-secondary, #5b6b62)' }}>{hint}</span>
    </div>
  );
}

const BODY = {
  filter: 'اختر المرحلة والصفّ والمادّة والفصل — التصنيف نفسه الذي يُبنى عليه المنهج، فما تختاره هو ما تُدرّسه.',
  format: 'الملفّ الواحد بصيغٍ متعدّدة: Word للتعديل، PowerPoint للعرض، PDF للطباعة. السعر يتبع الصيغة المختارة.',
  deliver: 'يصل رابط التنزيل فور إتمام الدفع ويبقى في حسابك — لا انتظار ولا بريدٌ يُفقَد.',
} as const;

const WITH_PANELS: readonly StickyRevealSection[] = [
  { id: 'filter', title: 'ضيّق بالمنهج', body: BODY.filter, panel: <Panel label="ابتدائي · الرياضيات" hint="الفصل الأول" /> },
  { id: 'format', title: 'اختر الصيغة', body: BODY.format, panel: <Panel label="Word · PowerPoint · PDF" hint="السعر يتبع الصيغة" /> },
  { id: 'deliver', title: 'استلِم فورًا', body: BODY.deliver, panel: <Panel label="رابط التنزيل" hint="محفوظ في حسابك" /> },
];

/** بلا لوحات — يُصيَّر رقم الخطوة مكانها. هذا سطح الاستوديو. */
const NUMBERS_ONLY: readonly StickyRevealSection[] = WITH_PANELS.map(({ id, title, body }) => ({ id, title, body }));

const cell: React.CSSProperties = { direction: 'rtl', maxWidth: 760 };

/** ما يمرّره تطبيق React: لوحةٌ لكلّ قسم. */
export function WithPanels() {
  return (
    <div style={cell}>
      <StickyReveal sections={WITH_PANELS} />
    </div>
  );
}

/** ما يصيّره مستند الاستوديو: لا لوحة، فيحلّ رقم الخطوة محلّها. */
export function NumbersOnly() {
  return (
    <div style={cell}>
      <StickyReveal sections={NUMBERS_ONLY} />
    </div>
  );
}
