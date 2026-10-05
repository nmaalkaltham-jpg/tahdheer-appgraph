/**
 * EmptyState — صندوق «لا شيء هنا» المتقطّع.
 *
 * قانون المكوّن: `audience="visitor"` يُعيد **null** — الزائر لا يرى صندوقًا
 * يشرح نقصًا في المتجر، والمشرف يراه بالتفصيل. لهذا كل الخلايا هنا `admin`:
 * خليّة الزائر ستكون فارغة بالضرورة، وذلك هو السلوك الصحيح لا عطل معاينة.
 *
 * موضع النداء الحقيقي: sections.tsx — ‎<EmptyState audience={AUDIENCE} title={title} />‎
 */

import { EmptyState } from '@tahdheer/ui';

const box: React.CSSProperties = { maxWidth: 460 };

/** الشكل الأساسي: عنوان يسمّي الناقص بالضبط. */
export function AdminDefault() {
  return (
    <div style={box}>
      <EmptyState audience="admin" title="لا توجد منتجات منشورة في هذا القسم بعد." />
    </div>
  );
}

/** بتلميح يقول أين يُصلَح الأمر — لا «حاول لاحقًا». */
export function WithHint() {
  return (
    <div style={box}>
      <EmptyState
        audience="admin"
        title="قسم الهيرو غير مضبوط."
        hint="اكتب العنوان في محرّر البطل ثم انشره ليظهر للزوّار."
      />
    </div>
  );
}

/** بإجراء يقود إلى الشاشة التي تملأ الفراغ. */
export function WithAction() {
  return (
    <div style={box}>
      <EmptyState
        audience="admin"
        title="لا ملفّات مرتبطة بهذا المنتج."
        hint="المنتج الرقمي بلا ملف لا يمكن تسليمه بعد الشراء."
        action={<a href="/products/1#files">افتح تبويب الملفّات</a>}
      />
    </div>
  );
}
