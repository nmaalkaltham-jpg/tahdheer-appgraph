// UNIT: ANIM
// FILE: anim.ts
// STYLE: none
// PARENT: DOCUMENT, REGISTRY-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * حركة الدخول — المجموعة الجزئية على طبقة الحركة الساكنة (STUDIO-PHASE3-CONTRACT
 * §2.5، قرار C33): كل قيمة هنا عنصر قائمة مغلقة تطابق مفردات
 * `packages/design-system/src/motion.css` حرفيًّا (أسماء data-enter
 * وdata-easing) — قيمة حرّة غير قابلة للتعبير أصلًا (قانون styles.ts مطبَّقًا
 * على الحركة). البثّ سماتُ غلافٍ ومتغيّرات ‎--mo-*‎ فقط؛ **تصريحات الحركة
 * ممنوعة في مخرجات compileDocCss** ما دام الفحص الساكن لا يرى
 * المُصرَّف (سطر العقد للبنّائين القادمين).
 *
 * الملف خالٍ من أي استيراد — يستورده document.ts وقت التشغيل والحارس مباشرة.
 */

/** ⊆ أسماء data-enter في motion.css — الحارس يفحص الاحتواء ضد CSS نفسه. */
export const ANIM_ENTRANCES = ['fade', 'up', 'down', 'inline', 'zoom'] as const;
export const ANIM_DURATIONS = [210, 320, 480, 640] as const;
export const ANIM_DELAYS = [0, 100, 200, 300, 400, 500, 600] as const;
/** = أسماء data-easing في motion.css (بلا 'custom' — ليست قيمة محرّر). */
export const ANIM_EASINGS = ['smooth', 'spring', 'soft-spring', 'linear'] as const;
export const ANIM_TRIGGERS = ['load', 'view'] as const;

export type AnimEntrance = (typeof ANIM_ENTRANCES)[number];
export type AnimDuration = (typeof ANIM_DURATIONS)[number];
export type AnimDelay = (typeof ANIM_DELAYS)[number];
export type AnimEasing = (typeof ANIM_EASINGS)[number];
export type AnimTrigger = (typeof ANIM_TRIGGERS)[number];

/**
 * حركة عقدة — على العقد العنصرية فقط (لا مراجع الرموز — C38، ولا عقد
 * المنبثقة: لـPopupShell دخوله المحروس الخاص). غياب المفاتيح الاختيارية =
 * «موروث من مستوى الحركة». stagger للحاويات الحقيقية فقط: كل غلاف ابن
 * مباشر يأخذ سمات الدخول + ‎--mo-index: i‎ (نمط ProductCollection).
 */
export interface NodeAnim {
  entrance: AnimEntrance;
  trigger: AnimTrigger;
  duration?: AnimDuration;
  delay?: AnimDelay;
  easing?: AnimEasing;
  stagger?: true;
}
