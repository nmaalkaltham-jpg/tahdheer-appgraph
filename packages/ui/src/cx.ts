// UNIT: CX
// FILE: cx.ts
// STYLE: none
// PARENT: SECTIONHEADING, TRUSTSTRIP
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * ضمُّ أسماء الأصناف — نسخةٌ واحدة بدل واحدةٍ في كلّ مكوّن.
 *
 * كانت معرَّفةً حرفيًّا داخل `SectionHeading` و`TrustStrip` بالنصّ نفسه، ثمّ
 * استُخرجت منهما إلى `./cx` ولم يُكتب الملفّ — فبقي الاستيرادان معلَّقين على
 * وحدةٍ لا وجود لها. ولم يظهر العطل فورًا لأنّ خادم التطوير كان يخدم من
 * ذاكرته المُصرَّفة؛ وظهر حين لمست أداةُ التوثيق ترويسة الملفّين فأعادت
 * تصريفهما، فسقط المتجر كلّه على 500 لأنّ `SectionHeading` يدخل في تخطيط
 * كلّ صفحة.
 *
 * التوقيع هو التوقيع المحذوف نفسه — لا توسيع: `false` و`undefined` تسقطان،
 * فيصحّ النمط `cond && styles.x` بلا فحصٍ عند كلّ نداء.
 */
export const cx = (...parts: Array<string | false | undefined>): string =>
  parts.filter(Boolean).join(' ');
