/**
 * TrustStrip — أشرطة الثقة الخمسة.
 *
 * الادّعاءات منقولة من صفحة المنتج في المتجر («تحميل فوري بعد الدفع مباشرة»
 * وأخواتها) لا مخترعة هنا: المكوّن لا يشحن نصًّا خاصًّا به، وكل جملة تصله
 * خاصّيةً — وهذا بالضبط ما تُظهره هذه الخلايا.
 *
 * `live` تُمرَّر في الشكل `smart` وحده، ولحالة حيّة فعلًا (الدعم متاح الآن).
 * تمريرها زينةً يجعل الحلقة النابضة تَعِد بما لا يقع.
 */

import { TrustStrip, type TrustItem } from '@tahdheer/ui';

const ITEMS: TrustItem[] = [
  { id: 'instant', title: 'تحميل فوري', description: 'بعد الدفع مباشرة' },
  { id: 'secure', title: 'دفع آمن', description: 'مدى · فيزا · Apple Pay' },
  { id: 'editable', title: 'ملفّات قابلة للتعديل', description: 'Word و PowerPoint' },
  { id: 'support', title: 'دعم المعلّمين', description: 'ردّ خلال ساعات العمل' },
];

/** الافتراضي في الشيفرة — الشكل الذي تحتفظ به مواضع النداء القائمة. */
export function Classic() {
  return <TrustStrip variant="classic" items={ITEMS} />;
}

/** الافتراضي في المحرّر — سطر واحد خفيف بلا حدود. */
export function Minimal() {
  return <TrustStrip variant="minimal" items={ITEMS} />;
}

/** شريط رباعي بسطر ثانٍ لكل عمود — يُستعمل تحت الهيرو. */
export function Band() {
  return <TrustStrip variant="band" items={ITEMS} />;
}

/** زجاجي — للوضع فوق صورة أو خلفية ملوّنة. */
export function Glass() {
  return (
    <div style={{ background: 'var(--ink, #10261d)', padding: 20, borderRadius: 12 }}>
      <TrustStrip variant="glass" items={ITEMS} dark />
    </div>
  );
}

/**
 * `smart` — نقاط ملوّنة وحالة حيّة.
 *
 * «الدعم متاح الآن» وحدها تحمل `live`: هي الحالة الوحيدة هنا التي تصف لحظة
 * راهنة. الباقي حقائق ثابتة لا تنبض.
 */
export function Smart() {
  return (
    <TrustStrip
      variant="smart"
      items={[
        { id: 'support', title: 'الدعم متاح الآن', description: 'ردّ خلال دقائق', tone: 'bright', live: true },
        { id: 'secure', title: 'دفع آمن', description: 'مشفّر بالكامل', tone: 'primary' },
        { id: 'quality', title: 'مراجَع تربويًّا', description: 'قبل النشر', tone: 'gold' },
      ]}
    />
  );
}

/** مضغوط على اللون الداكن — نفس الادّعاءات بمسافات أضيق. */
export function CompactDark() {
  return (
    <div style={{ background: 'var(--ink, #10261d)', padding: 16, borderRadius: 12 }}>
      <TrustStrip variant="minimal" items={ITEMS.slice(0, 3)} dark compact />
    </div>
  );
}
