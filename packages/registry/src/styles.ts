// UNIT: STYLES
// FILE: styles.ts
// STYLE: none
// PARENT: BRIDGE, CSS-COMPILE, DOCUMENT, REGISTRY-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * نموذج الأنماط — مُغلق ومكتوب الأنواع، لا يقبل الحقن بنيويًّا
 * (STUDIO-PHASE2-CONTRACT §2.2).
 *
 * القيم كائنات مكتوبة لا نصوص CSS إطلاقًا: `url()` وposition وz-index
 * وtransform وcalc() و`!important` والمحدّدات الحرة والخصائص الفيزيائية
 * كلها غير قابلة للتعبير — لا مفتاح يقبلها أصلًا. خاصية `cls` النصيّة تبقى
 * المنفذ الوحيد حتى Safe Custom CSS (SPEC §62، مؤجَّل).
 *
 * جدول STYLE_KEYS بياناتٌ تُصدَّر وتُطابَق مرآتُها في PHP
 * (كانت مرآتها PageDocument::normalizeStyle، وقد حُذفت هي والحارس ٢٠٢٦-٠٩-١٧)، وترتيبُه
 * هو ترتيب البثّ الحتمي في compileDocCss — أبدًا ترتيب مفاتيح الكائنات
 * (json_encode في PHP قد يعيد ترتيبها).
 *
 * الحالات غير الأساسية مقصورة على مفاتيح الطلاء (STATE_PAINT_KEYS) — لا
 * قفزات تخطيط عند التمرير. الملف خالٍ من الاستيرادات عمدًا (يُستورد وقت
 * التشغيل تحت Node بلا تحويل).
 */

/** الحالات بترتيب طبقات البثّ في compileDocCss — لا يُعاد ترتيبها. */
export const STATES = ['base', 'hover', 'active', 'focusWithin'] as const;
export type StyleState = (typeof STATES)[number];

/** مفاتيح الطلاء — الوحيدة المسموحة في الحالات غير الأساسية. */
export const STATE_PAINT_KEYS = ['bg', 'textColor', 'border', 'shadow', 'radius', 'opacity'] as const;
export type StatePaintKey = (typeof STATE_PAINT_KEYS)[number];

/** بُعد: قيمة بوحدة، أو مرجع رمز من الكتالوج، أو none (حيث تصحّ). */
export type Dim =
  | { t: 'px' | 'rem' | 'pct' | 'vh'; v: number }
  | { t: 'token'; name: string }
  | { t: 'none' };

/** لون: hex صريح أو مرجع رمز لوني من الكتالوج. */
export type Color = { t: 'hex'; v: string } | { t: 'token'; name: string };

/** حدود: بلا، أو صلبة بعرض 1-4px ولون مكتوب. */
export type Border = { t: 'none' } | { t: 'solid'; w: number; color: Color };

/** ظل: قائمة مغلقة مدعومة برموز الظل — لا نص ظل حر. */
export type ShadowPreset = 'none' | 'card' | 'float' | 'card-hover';

/** خصائص النمط — كلها اختيارية، الغياب = وراثة. */
export interface StyleProps {
  padBlock?: Dim;
  padInline?: Dim;
  marBlock?: Dim;
  maxW?: Dim;
  /** justify-self — يُرسم في المفتّش سياقيًّا فقط (قرار C20). */
  align?: 'start' | 'center' | 'end';
  minH?: Dim;
  bg?: Color;
  textColor?: Color;
  textAlign?: 'start' | 'center' | 'end';
  radius?: Dim;
  border?: Border;
  shadow?: ShadowPreset;
  /** 0-100؛ أقل من 10 في الأساس يثير تحذير «قسم شبه مخفي» في preflight. */
  opacity?: number;
}

/** خصائص الحالات غير الأساسية — طلاء فقط. */
export type StatePaintProps = Pick<StyleProps, StatePaintKey>;

/**
 * نطاقات الأجهزة — النطاقان المثبَّتان في المستودع، لا نطاقات مخصّصة.
 *
 * الأساس هو المكتب (‏≥1025px‏) ويرثه النطاقان. القيم أدناه حرفيّة كما هي
 * مستعملة في `packages/ui` و`apps/storefront` — لا استعلام وسائط جديد يُخترع
 * هنا، فبثّ حدٍّ يخالف الحدود القانونيّة يجعل الاستوديو يعد بمقاسٍ لا يراه
 * الزائر عنده.
 *
 * والترتيب ترتيبُ البثّ: الأعرض أوّلًا فالأضيق، فتغلب قاعدة الجوّال على
 * قاعدة التابلت بترتيب المصدر وحده — لا `!important` ولا رفع خصوصيّة.
 */
export const DEVICE_BANDS = ['tablet', 'mobile'] as const;
export type DeviceBand = (typeof DEVICE_BANDS)[number];

/** استعلام الوسائط لكل نطاق — مطابق حرفيًّا للنطاقات القانونيّة في المستودع. */
export const BAND_MEDIA: Readonly<Record<DeviceBand, string>> = Object.freeze({
  tablet: '(min-width: 641px) and (max-width: 1024px)',
  mobile: '(max-width: 640px)',
});

/**
 * ورقة أنماط عقدة/صنف: الأساس كامل، والحالات طلاءً فقط، والأجهزة كاملة.
 *
 * نطاقا الجهاز يقبلان مفاتيح الأساس كلّها لا الطلاء وحده: المقصود منهما
 * إعادة القياس (حشو، عرض أقصى، محاذاة، ارتفاع أدنى) لا تغيير اللون فقط —
 * وهو بعينه ما كان متعذّرًا فأُجّل مع مصفوفة §16.
 */
/**
 * طبقة تحرير واحدة — حالة أو نطاق جهاز.
 *
 * الطبقتان تُكتبان بالعمليّة نفسها (`set-style` / `set-class-style`) لأنّ
 * موضعهما في ورقة الأنماط واحد؛ ما يختلف هو مجموعة المفاتيح المسموحة:
 * الحالات طلاءً فقط، والنطاقات مفاتيح الأساس كلّها.
 */
export type StyleLayer = StyleState | DeviceBand;

export interface StudioStyleSheet {
  base?: StyleProps;
  hover?: StatePaintProps;
  active?: StatePaintProps;
  focusWithin?: StatePaintProps;
  tablet?: StyleProps;
  mobile?: StyleProps;
}

/** صنف على مستوى الصفحة — ترتيب المصفوفة في المستند هو أولوية التتابع. */
export interface StudioClassDef {
  id: string;
  name: string;
  style: StudioStyleSheet;
}

/** أنواع قيم المفاتيح في جدول STYLE_KEYS. */
export type StyleValueKind = 'dim' | 'color' | 'enum' | 'border' | 'shadow' | 'opacity';

export interface StyleKeySpec {
  key: keyof StyleProps;
  /** الخاصية المنطقية المبثوثة — لا خصائص فيزيائية (قانون RTL). */
  css: string;
  value: StyleValueKind;
  /** dim: وسوم الوحدات المقبولة (تشمل none حيث تصحّ). */
  units?: readonly ('px' | 'rem' | 'pct' | 'vh' | 'none')[];
  /** نوع الرمز المقبول مرجعًا لهذا المفتاح — غيابه = لا مراجع رموز. */
  tokenKind?: 'color' | 'radius';
  /** حدود القيمة الرقمية (dim.v · عرض border · opacity). */
  min?: number;
  max?: number;
  /** enum/shadow: القيم المقبولة حصرًا. */
  options?: readonly string[];
}

/**
 * الجدول القانوني — ترتيبه هو ترتيب بثّ التصريحات داخل كل قاعدة.
 * لا مرآة في PHP بعد حذف الاستوديو (٢٠٢٦-٠٩-١٧)؛ الترتيب عقدٌ داخليّ يفحصه css-compile.test.ts وحده.
 */
export const STYLE_KEYS = [
  { key: 'padBlock', css: 'padding-block', value: 'dim', units: ['px', 'rem'], min: 0, max: 160 },
  { key: 'padInline', css: 'padding-inline', value: 'dim', units: ['px', 'rem'], min: 0, max: 160 },
  { key: 'marBlock', css: 'margin-block', value: 'dim', units: ['px', 'rem'], min: 0, max: 160 },
  { key: 'maxW', css: 'max-inline-size', value: 'dim', units: ['px', 'rem', 'pct', 'none'], min: 0, max: 1920 },
  { key: 'align', css: 'justify-self', value: 'enum', options: ['start', 'center', 'end'] },
  { key: 'minH', css: 'min-block-size', value: 'dim', units: ['px', 'vh'], min: 0, max: 1200 },
  { key: 'bg', css: 'background-color', value: 'color', tokenKind: 'color' },
  { key: 'textColor', css: 'color', value: 'color', tokenKind: 'color' },
  { key: 'textAlign', css: 'text-align', value: 'enum', options: ['start', 'center', 'end'] },
  { key: 'radius', css: 'border-radius', value: 'dim', units: ['px'], tokenKind: 'radius', min: 0, max: 48 },
  { key: 'border', css: 'border', value: 'border', min: 1, max: 4 },
  { key: 'shadow', css: 'box-shadow', value: 'shadow', options: ['none', 'card', 'float', 'card-hover'] },
  { key: 'opacity', css: 'opacity', value: 'opacity', min: 0, max: 100 },
] as const satisfies readonly StyleKeySpec[];

/* فحص تغطية وقت الترجمة: الجدول يغطي مفاتيح StyleProps كلها بلا زيادة. */
type StyleKeysCover = (typeof STYLE_KEYS)[number]['key'] extends keyof StyleProps
  ? keyof StyleProps extends (typeof STYLE_KEYS)[number]['key']
    ? true
    : never
  : never;
const _styleKeysCover: StyleKeysCover = true;
void _styleKeysCover;

/** نمط hex المقبول: ‎#rrggbb أو ‎#rrggbbaa. */
export const HEX_COLOR_PATTERN = /^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/;
