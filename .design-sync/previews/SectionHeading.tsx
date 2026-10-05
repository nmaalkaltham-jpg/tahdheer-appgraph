/**
 * SectionHeading — عنوان القسم.
 *
 * منقول من مواضع النداء الحقيقية في الصفحة الرئيسية:
 * ‎<SectionHeading title="آراء المعلمين والمعلمات" />‎ في sections.tsx،
 * والصيغة الموسومة في SectionHead.tsx.
 *
 * `as` يفصل المستوى الدلالي عن الحجم البصري: قسم داخل قسم يأخذ h3 دون أن
 * يصغر، فلا يُكسر تسلسل العناوين لقارئ الشاشة لأجل المظهر.
 */

import { SectionHeading } from '@tahdheer/ui';

/** موضع النداء الحقيقي في قسم آراء المعلمين. */
export function Default() {
  return <SectionHeading title="آراء المعلمين والمعلمات" />;
}

/** بسطر تمهيدي — نمط أقسام التصفّح. */
export function WithEyebrow() {
  return <SectionHeading eyebrow="تصفّح" title="المراحل التعليمية" />;
}

/** بوصف كامل — يُستعمل في صدر صفحات التصنيف. */
export function WithDescription() {
  return (
    <SectionHeading
      eyebrow="مختارات"
      title="الأكثر مبيعًا هذا الأسبوع"
      description="تحاضير وعروض واختبارات جاهزة للتحميل الفوري، مرتّبة حسب مبيعات المعلمين الفعلية."
    />
  );
}

/** توسيط — لأقسام الصفحة الكاملة. */
export function Centered() {
  return (
    <SectionHeading
      align="center"
      eyebrow="كيف نعمل"
      title="من الاختيار إلى التحميل في ثلاث خطوات"
      description="اختر الملف، ادفع بأمان، حمّله فورًا."
    />
  );
}

/** مستوى h3 لقسم متداخل — الدلالة تتغيّر والحجم لا. */
export function AsSubsection() {
  return <SectionHeading as="h3" title="ملفّات ذات صلة" />;
}
