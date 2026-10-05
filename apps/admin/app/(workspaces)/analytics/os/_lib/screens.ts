// UNIT: SCREENS
// FILE: screens.ts
// STYLE: none
// PARENT: DERIVE, OS-KIT
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * سجلّ شاشات نظام التشغيل التحليليّ — مصدرٌ واحد لشريطَي التنقّل.
 *
 * ─── لماذا خرج من مكانَيه ───
 *
 * كان معرَّفًا مرّتين: في `_components/os-kit.tsx` لشريط القشرة الذي يلفّ
 * كلّ صفحات `/analytics/os`، وفي `_lib/derive.ts` لشريط `OsNav` الذي ترسمه
 * الصفحات الداخليّة. ونسختان تتباعدان: نسخة القشرة كانت **بسبع شاشات**
 * ونسخة الصفحات بثمانٍ. الثامنة — «ذكاء الأصول» — صفحةٌ مبنيّة على القرص
 * (`os/assets/page.tsx`) لا يقود إليها شريطُ القشرة أبدًا، فلا يبلغها
 * المشغّل إلّا بكتابة عنوانها. قدرةٌ قائمة بلا باب.
 *
 * وشاشةٌ تُضاف من هنا تظهر في الشريطين معًا، فلا يتكرّر السقوط.
 */

export const OS_SCREENS = [
  { key: 'home', label: 'غرفة التحكم', href: '/analytics/os' },
  { key: 'actions', label: 'مركز الإجراءات', href: '/analytics/os/actions' },
  { key: 'cause', label: 'السبب الجذري', href: '/analytics/os/cause' },
  { key: 'opps', label: 'الفرص', href: '/analytics/os/opps' },
  { key: 'sim', label: 'المحاكاة', href: '/analytics/os/sim' },
  { key: 'flows', label: 'الأتمتة', href: '/analytics/os/flows' },
  { key: 'ai', label: 'المساعد والتعلم', href: '/analytics/os/ai' },
  { key: 'assets', label: 'ذكاء الأصول', href: '/analytics/os/assets' },
] as const;

export type OsScreenKey = (typeof OS_SCREENS)[number]['key'];
