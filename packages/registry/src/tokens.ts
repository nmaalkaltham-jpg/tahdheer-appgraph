// UNIT: REGISTRY-TOKENS
// FILE: tokens.ts
// STYLE: none
// PARENT: CSS-COMPILE, REGISTRY-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * كتالوج رموز الاستوديو — النصف TS من C27 (عقد الرموز — وثيقته محذوفة).
 *
 * كتالوج مُغلق: ٢٧ رمزًا (٢٠ لونًا · ٥ استدارات · ظلّان) تحرّرها لوحة الرموز
 * عبر خط الثيم القائم (ThemeDocument.tokens → ThemeCss). المرآة الملزِمة في
 * PHP هي App\Domain\Theme\TokenCatalog وهي المرآة الوحيدة الباقية؛ ولا حارس
 * يطابقهما أسماءً وأنواعًا بعد حذف مرآة القائمة (٢٠٢٦-٠٩-١٧) — الضمان
 * الوحيد عددٌ مكتوب باليد في ThemeTokensTest. وكل اسم هنا يجب أن يكون مُعلنًا في tokens.css في
 * design-system — لا رمز جديدًا يُخترع من هذه القائمة.
 *
 * الاسم يُخزَّن بلا «--»؛ مراجع الأنماط تُسلسله `var(--name)` مع مطابقة
 * النوع (لون لمفاتيح الألوان، استدارة لمفتاح radius). المجموعات المستثناة
 * (الألوان الدلالية · تدرّجات chrome · المسافات · الحركة · الطباعة) عمدًا
 * خارج الكتالوج — تُعرض للقراءة فقط في اللوحة مع سببها (عقد §6).
 *
 * الملف خالٍ من الاستيرادات عمدًا: يُستورد وقت التشغيل من الحُرّاس ومن
 * css-compile تحت Node بلا تحويل.
 */

export type TokenKind = 'color' | 'radius' | 'shadow';

export interface StudioTokenDef {
  /** اسم الخاصية بلا البادئة «--» — يطابق إعلانًا فعليًّا في tokens.css. */
  name: string;
  kind: TokenKind;
  /** التسمية العربية في لوحة الرموز. */
  label: string;
  /** معرّف المجموعة (TOKEN_GROUPS). */
  group: string;
  /** حدود القيمة الرقمية (px) — للاستدارات فقط. */
  min?: number;
  max?: number;
}

export const TOKEN_GROUPS = [
  { id: 'brand', label: 'ألوان العلامة' },
  { id: 'text', label: 'ألوان النص' },
  { id: 'gold', label: 'الذهبي' },
  { id: 'surface', label: 'الأسطح' },
  { id: 'border', label: 'الحدود' },
  { id: 'radius', label: 'الاستدارات' },
  { id: 'shadow', label: 'الظلال' },
] as const satisfies readonly { id: string; label: string }[];

export type TokenGroupId = (typeof TOKEN_GROUPS)[number]['id'];

export const TOKEN_CATALOG = [
  // ── ألوان العلامة (٤) ──
  { name: 'green-700', kind: 'color', label: 'الأخضر الأساسي', group: 'brand' },
  { name: 'green-deep', kind: 'color', label: 'الأخضر العميق', group: 'brand' },
  { name: 'green-hover', kind: 'color', label: 'أخضر التمرير', group: 'brand' },
  { name: 'green-bright', kind: 'color', label: 'الأخضر الساطع', group: 'brand' },
  // ── ألوان النص (٤) ──
  { name: 'ink', kind: 'color', label: 'حبر العناوين', group: 'text' },
  { name: 'text', kind: 'color', label: 'نص المتن', group: 'text' },
  { name: 'text-secondary', kind: 'color', label: 'النص الثانوي', group: 'text' },
  { name: 'text-muted', kind: 'color', label: 'النص الخافت', group: 'text' },
  // ── الذهبي (٣) ──
  { name: 'gold-500', kind: 'color', label: 'الذهبي', group: 'gold' },
  { name: 'gold-text', kind: 'color', label: 'ذهبي النص', group: 'gold' },
  { name: 'gold-light', kind: 'color', label: 'الذهبي الفاتح', group: 'gold' },
  // ── الأسطح (٦) ──
  { name: 'bg', kind: 'color', label: 'الخلفية', group: 'surface' },
  { name: 'bg-page', kind: 'color', label: 'خلفية الصفحة', group: 'surface' },
  { name: 'bg-2', kind: 'color', label: 'الخلفية الثانية', group: 'surface' },
  { name: 'bg-soft', kind: 'color', label: 'الخلفية الرخوة', group: 'surface' },
  { name: 'bg-mist', kind: 'color', label: 'خلفية الضباب', group: 'surface' },
  { name: 'bg-green-light', kind: 'color', label: 'الخلفية الخضراء الفاتحة', group: 'surface' },
  // ── الحدود (٣) ──
  { name: 'border', kind: 'color', label: 'لون الحدود', group: 'border' },
  { name: 'border-hairline', kind: 'color', label: 'الحدود الشعرية', group: 'border' },
  { name: 'border-strong', kind: 'color', label: 'الحدود الواضحة', group: 'border' },
  // ── الاستدارات (٥) ──
  { name: 'radius-sm', kind: 'radius', label: 'استدارة صغيرة', group: 'radius', min: 0, max: 48 },
  { name: 'radius-md', kind: 'radius', label: 'استدارة متوسطة', group: 'radius', min: 0, max: 48 },
  { name: 'radius-card', kind: 'radius', label: 'استدارة البطاقات', group: 'radius', min: 0, max: 48 },
  { name: 'radius-lg', kind: 'radius', label: 'استدارة كبيرة', group: 'radius', min: 0, max: 48 },
  { name: 'radius-hero', kind: 'radius', label: 'استدارة الهيرو', group: 'radius', min: 0, max: 48 },
  // ── الظلال (٢) ──
  { name: 'shadow-card', kind: 'shadow', label: 'ظل البطاقة', group: 'shadow' },
  { name: 'shadow-float', kind: 'shadow', label: 'الظل المرتفع', group: 'shadow' },
] as const satisfies readonly StudioTokenDef[];

export type StudioTokenName = (typeof TOKEN_CATALOG)[number]['name'];

/** خريطة اسم → تعريف — مصدر مطابقة النوع في css-compile وأدوات الاختيار. */
const BY_NAME = new Map<string, StudioTokenDef>(TOKEN_CATALOG.map((t) => [t.name, t]));

export function tokenByName(name: string): StudioTokenDef | undefined {
  return BY_NAME.get(name);
}

/** صحّة مرجع رمز داخل قيمة نمط: الاسم في الكتالوج ونوعه مطابق للمطلوب. */
export function isTokenOfKind(name: string, kind: TokenKind): boolean {
  return BY_NAME.get(name)?.kind === kind;
}
