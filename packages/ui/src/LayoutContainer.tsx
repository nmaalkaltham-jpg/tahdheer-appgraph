// UNIT: LAYOUTCONTAINER
// FILE: LayoutContainer.tsx
// STYLE: LayoutContainer.module.css
// PARENT: UI-INDEX
// USES: REGISTRY-INDEX
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * LayoutContainer — الحاوية الواحدة خلف محرّكات التخطيط الأربعة
 * (section · flex · stack · grid) — STUDIO-PHASE2-CONTRACT §2.4، قرار C29.
 *
 * قانون الحزمة: «التخطيط خاصية لا ملف» — المحرّك يصل من entry.layout في
 * السجل (tah/container.2101-2104) كخاصية، والمكوّن ملف واحد على نمط
 * ProductCollection حرفيًّا: Settings + DEFAULTS + فحص satisfies ضد المخطط.
 *
 * البثّ (قرار C17):
 *   · القيم المستمرّة والمفاتيح القابلة للتجاوز (responsive:true) تخرج
 *     متغيّرات ‎--lc-*‎ مضمّنة، مع نظيرَيها ‎--lc-*-tab / --lc-*-mob‎
 *     المبثوثَين **دائمًا** (القيمة الفعلية = التجاوز إن وُجد وإلا الأساس):
 *     المتغيّرات المخصّصة تُورَّث، وحاوية متداخلة بلا نظير خاص بها كانت
 *     سترث تجاوز أبيها — بثّها دائمًا يقطع التسرّب بنيويًّا.
 *   · الأشرطة الساكنة الثلاث في module.css (‎≥1025 / 641-1024 / ≤640‎ —
 *     حدود المستودع القانونية نفسها) تستهلكها؛ لا ‎@media‎ مولَّدًا من
 *     المستند، وSSR والكانفس يبثّان بايتات متطابقة.
 *   · قيم التعداد غير القابلة للتجاوز (bg · radius · border · colMode)
 *     أصنافٌ مدعومة بالرموز.
 *
 * الأبناء يصلون React children جاهزين (أغلفة العقد من DocumentRenderer —
 * هي عناصر flex/grid، وعليها تعيش أنماط slot المضمّنة، لا هنا).
 * لا حركة دخول للحاويات (السجل: entrance:'none') — لا keyframes في CSS.
 * خصائص منطقية فقط؛ ورموز فقط. المكوّن خادمي صِرف — لا توجيهات ولا خطافات.
 */
import type { CSSProperties, ReactNode } from 'react';
import type { CONTAINER_SCHEMA } from '@tahdheer/registry';
import styles from './LayoutContainer.module.css';

export type ContainerEngine = 'section' | 'flex' | 'stack' | 'grid';

export type ContainerWidth = 'full' | 'wide' | 'content' | 'narrow';
export type ContainerBg = 'none' | 'surface' | 'page' | 'soft' | 'mist' | 'green-light' | 'deep';
export type ContainerRadius = 'none' | 'sm' | 'md' | 'card' | 'lg' | 'hero';
export type ContainerBorder = 'none' | 'hairline' | 'thin' | 'strong';
export type ContainerDirection = 'row' | 'row-reverse' | 'column' | 'column-reverse';
export type ContainerJustify = 'start' | 'center' | 'end' | 'between';
export type ContainerAlign = 'stretch' | 'start' | 'center' | 'end';
export type ContainerColMode = 'fixed' | 'auto';

/**
 * إعدادات الحاوية — مفاتيح CONTAINER_SCHEMA حرفيًّا (لا طبقة ترجمة).
 * مفاتيح flex/stack وgrid موجودة دائمًا في النوع؛ المفتّش يرشّحها بالمحرّك
 * والمحرّكات الأخرى لا تستهلكها في CSS — فلا ضابط ميّتًا (§76).
 */
export interface LayoutContainerSettings {
  width: ContainerWidth;
  pad: number;
  padInline: number;
  gap: number;
  bg: ContainerBg;
  radius: ContainerRadius;
  border: ContainerBorder;
  minH: number;
  anchor: string;
  cls: string;

  direction: ContainerDirection;
  wrap: boolean;
  justify: ContainerJustify;
  align: ContainerAlign;

  cols: number;
  colMode: ContainerColMode;
  minCol: number;
  rowGap: number;
}

/*
 * فحص الانجراف وقت الترجمة (العقد §2.4 — استيراد نوعي، صفر أثر تشغيلي):
 * ١· كل مفتاح في CONTAINER_SCHEMA خاصية هنا — مفتاح جديد بلا تنفيذ يكسر
 *    البناء (نصف «الزرّ الشكلي» يُقتل قبل الوصول).
 * ٢· مفاتيح responsive:true في المخطط ≡ ContainerResponsiveKey تطابقًا
 *    تامًّا بالاتجاهين — علم يُضاف أو يُرفع هناك يكسر البناء هنا.
 */
type ContainerSchemaKey = (typeof CONTAINER_SCHEMA)[number]['key'];
void (null as unknown as ContainerSchemaKey satisfies keyof LayoutContainerSettings);

type SchemaResponsiveKey = Extract<(typeof CONTAINER_SCHEMA)[number], { responsive: true }>['key'];

/** المفاتيح القابلة للتجاوز لكل جهاز — مرآة أعلام responsive:true حرفيًّا. */
export type ContainerResponsiveKey =
  | 'width'
  | 'pad'
  | 'padInline'
  | 'gap'
  | 'minH'
  | 'direction'
  | 'wrap'
  | 'justify'
  | 'align'
  | 'cols'
  | 'minCol'
  | 'rowGap';

void (null as unknown as SchemaResponsiveKey satisfies ContainerResponsiveKey);
void (null as unknown as ContainerResponsiveKey satisfies SchemaResponsiveKey);

/** تجاوزات الجهازين — شكل ‎node.responsive‎ نفسه بعد التضييق النوعي. */
export type ContainerResponsive = Partial<
  Record<'tab' | 'mob', Partial<Pick<LayoutContainerSettings, ContainerResponsiveKey>>>
>;

/** القيم الافتراضية — قيم default في CONTAINER_SCHEMA نفسها. */
export const CONTAINER_DEFAULTS: LayoutContainerSettings = {
  width: 'content',
  pad: 24,
  padInline: 16,
  gap: 16,
  bg: 'none',
  radius: 'none',
  border: 'none',
  minH: 0,
  anchor: '',
  cls: '',
  direction: 'row',
  wrap: true,
  justify: 'start',
  align: 'stretch',
  cols: 3,
  colMode: 'fixed',
  minCol: 220,
  rowGap: 16,
};

export interface LayoutContainerProps {
  /** المحرّك من ‎entry.layout‎ — لا من الخصائص (التخطيط خاصية السجل). */
  engine: ContainerEngine;
  /**
   * ‎node.props‎ كما وصلت (جزئية) — الدمج فوق CONTAINER_DEFAULTS يحدث هنا.
   * ملاحظة الرصّ: direction غير المذكورة تعني column تحت stack وrow تحت
   * flex — الافتراض الفعلي للمحرّك، والضابط الظاهر يغيّره فعليًّا دائمًا.
   */
  settings: Partial<LayoutContainerSettings>;
  /** ‎node.responsive‎ — تجاوزات tab/mob لمفاتيح responsive:true فقط. */
  responsive?: ContainerResponsive;
  children?: ReactNode;
}

/* ── جداول القيم (التعيين في TS لا في CSS: الشريط يستهلك متغيّرًا واحدًا) ── */

const WIDTHS: readonly ContainerWidth[] = ['full', 'wide', 'content', 'narrow'];
const DIRECTIONS: readonly ContainerDirection[] = ['row', 'row-reverse', 'column', 'column-reverse'];
const JUSTIFIES: readonly ContainerJustify[] = ['start', 'center', 'end', 'between'];
const ALIGNS: readonly ContainerAlign[] = ['stretch', 'start', 'center', 'end'];

/**
 * قيمة max-inline-size لكل عرض — من رموز نظام الحاويات لا من أرقامٍ هنا.
 *
 * كانت ‎1080‎ و‎760‎ مكتوبتين في هذا الملفّ، و`wide` تشير إلى السقف العامّ
 * القديم. أي: **نظاما حاوياتٍ متوازيان** — واحدٌ في CSS للأصداف وواحدٌ في
 * TypeScript لمستندات الاستوديو. وصفحةٌ يبنيها التاجر بحاوية «واسعة» كانت
 * تقف عند رقمٍ لا علاقة له بما يقف عنده متجرها.
 *
 * والرموز نفسها الآن، فتتحرّك السياستان معًا أو لا تتحرّك واحدة.
 */
const WIDTH_CSS: Record<ContainerWidth, string> = {
  full: 'var(--container-full, 100%)',
  wide: 'var(--container-wide, 1200px)',
  content: 'var(--container-content, 1200px)',
  narrow: 'var(--container-narrow, 760px)',
};

const JUSTIFY_CSS: Record<ContainerJustify, string> = {
  start: 'flex-start',
  center: 'center',
  end: 'flex-end',
  between: 'space-between',
};

const ALIGN_CSS: Record<ContainerAlign, string> = {
  stretch: 'stretch',
  start: 'flex-start',
  center: 'center',
  end: 'flex-end',
};

const BG_CLASS: Record<ContainerBg, string | undefined> = {
  none: undefined,
  surface: styles.bgSurface,
  page: styles.bgPage,
  soft: styles.bgSoft,
  mist: styles.bgMist,
  'green-light': styles.bgGreenLight,
  deep: styles.bgDeep,
};

const RADIUS_CLASS: Record<ContainerRadius, string | undefined> = {
  none: undefined,
  sm: styles.rSm,
  md: styles.rMd,
  card: styles.rCard,
  lg: styles.rLg,
  hero: styles.rHero,
};

const BORDER_CLASS: Record<ContainerBorder, string | undefined> = {
  none: undefined,
  hairline: styles.bHairline,
  thin: styles.bThin,
  strong: styles.bStrong,
};

/* ── تعقيم القيم — المستند مُطبَّع المفاتيح على الخادم، لا القيم النوعية:
      قيمة خارج التعداد أو خارج المدى تعود للأساس فلا تصل style attribute
      إلا قيم مبنية هنا (لا حقن CSS عبر متغيّر). ── */

const pickEnum = <T extends string>(v: unknown, allowed: readonly T[], fb: T): T =>
  typeof v === 'string' && (allowed as readonly string[]).includes(v) ? (v as T) : fb;

const pickNum = (v: unknown, min: number, max: number, fb: number): number =>
  typeof v === 'number' && Number.isFinite(v) ? Math.min(max, Math.max(min, Math.round(v))) : fb;

const pickBool = (v: unknown, fb: boolean): boolean => (typeof v === 'boolean' ? v : fb);

/** القيم الفعلية للمفاتيح القابلة للتجاوز على جهاز واحد. */
interface ResolvedResponsive {
  width: ContainerWidth;
  pad: number;
  padInline: number;
  gap: number;
  minH: number;
  direction: ContainerDirection;
  wrap: boolean;
  justify: ContainerJustify;
  align: ContainerAlign;
  cols: number;
  minCol: number;
  rowGap: number;
}

function resolveDevice(
  src: Partial<Pick<LayoutContainerSettings, ContainerResponsiveKey>>,
  base: ResolvedResponsive,
): ResolvedResponsive {
  return {
    width: pickEnum(src.width, WIDTHS, base.width),
    pad: pickNum(src.pad, 0, 160, base.pad),
    padInline: pickNum(src.padInline, 0, 160, base.padInline),
    gap: pickNum(src.gap, 0, 64, base.gap),
    minH: pickNum(src.minH, 0, 1200, base.minH),
    direction: pickEnum(src.direction, DIRECTIONS, base.direction),
    wrap: pickBool(src.wrap, base.wrap),
    justify: pickEnum(src.justify, JUSTIFIES, base.justify),
    align: pickEnum(src.align, ALIGNS, base.align),
    cols: pickNum(src.cols, 1, 6, base.cols),
    minCol: pickNum(src.minCol, 120, 480, base.minCol),
    rowGap: pickNum(src.rowGap, 0, 64, base.rowGap),
  };
}

function emitVars(vars: Record<string, string>, suffix: '' | '-tab' | '-mob', v: ResolvedResponsive): void {
  vars[`--lc-width${suffix}`] = WIDTH_CSS[v.width];
  vars[`--lc-pad${suffix}`] = `${v.pad}px`;
  vars[`--lc-pad-inline${suffix}`] = `${v.padInline}px`;
  vars[`--lc-gap${suffix}`] = `${v.gap}px`;
  vars[`--lc-min-h${suffix}`] = `${v.minH}px`;
  vars[`--lc-direction${suffix}`] = v.direction;
  vars[`--lc-wrap${suffix}`] = v.wrap ? 'wrap' : 'nowrap';
  vars[`--lc-justify${suffix}`] = JUSTIFY_CSS[v.justify];
  vars[`--lc-align${suffix}`] = ALIGN_CSS[v.align];
  vars[`--lc-cols${suffix}`] = String(v.cols);
  vars[`--lc-min-col${suffix}`] = `${v.minCol}px`;
  vars[`--lc-row-gap${suffix}`] = `${v.rowGap}px`;
}

export function LayoutContainer({ engine, settings, responsive, children }: LayoutContainerProps) {
  const base: ResolvedResponsive = {
    width: pickEnum(settings.width, WIDTHS, CONTAINER_DEFAULTS.width),
    pad: pickNum(settings.pad, 0, 160, CONTAINER_DEFAULTS.pad),
    padInline: pickNum(settings.padInline, 0, 160, CONTAINER_DEFAULTS.padInline),
    gap: pickNum(settings.gap, 0, 64, CONTAINER_DEFAULTS.gap),
    minH: pickNum(settings.minH, 0, 1200, CONTAINER_DEFAULTS.minH),
    // «رصّ» بلا direction مذكورة = عمود؛ «مرنة» = صفّ. الضابط حين يُضبط يفوز.
    direction: pickEnum(settings.direction, DIRECTIONS, engine === 'stack' ? 'column' : CONTAINER_DEFAULTS.direction),
    wrap: pickBool(settings.wrap, CONTAINER_DEFAULTS.wrap),
    justify: pickEnum(settings.justify, JUSTIFIES, CONTAINER_DEFAULTS.justify),
    align: pickEnum(settings.align, ALIGNS, CONTAINER_DEFAULTS.align),
    cols: pickNum(settings.cols, 1, 6, CONTAINER_DEFAULTS.cols),
    minCol: pickNum(settings.minCol, 120, 480, CONTAINER_DEFAULTS.minCol),
    rowGap: pickNum(settings.rowGap, 0, 64, CONTAINER_DEFAULTS.rowGap),
  };

  const vars: Record<string, string> = {};
  emitVars(vars, '', base);
  emitVars(vars, '-tab', resolveDevice(responsive?.tab ?? {}, base));
  emitVars(vars, '-mob', resolveDevice(responsive?.mob ?? {}, base));

  const bg = pickEnum(settings.bg, ['none', 'surface', 'page', 'soft', 'mist', 'green-light', 'deep'] as const, CONTAINER_DEFAULTS.bg);
  const radius = pickEnum(settings.radius, ['none', 'sm', 'md', 'card', 'lg', 'hero'] as const, CONTAINER_DEFAULTS.radius);
  const border = pickEnum(settings.border, ['none', 'hairline', 'thin', 'strong'] as const, CONTAINER_DEFAULTS.border);
  const colMode = pickEnum(settings.colMode, ['fixed', 'auto'] as const, CONTAINER_DEFAULTS.colMode);
  const anchor = typeof settings.anchor === 'string' ? settings.anchor.trim() : '';
  const cls = typeof settings.cls === 'string' ? settings.cls.trim() : '';

  const classes = [
    styles.container,
    engine === 'grid' ? styles.engineGrid : engine === 'section' ? styles.engineSection : styles.engineFlex,
    engine === 'grid' ? (colMode === 'auto' ? styles.colsAuto : styles.colsFixed) : undefined,
    BG_CLASS[bg],
    RADIUS_CLASS[radius],
    BORDER_CLASS[border],
    // صنف المشرف أخيرًا فيستطيع الفوز — وهو الغرض منه (المهرب الوحيد §62).
    cls,
  ]
    .filter(Boolean)
    .join(' ');

  const Tag = engine === 'section' ? 'section' : 'div';
  return (
    <Tag id={anchor || undefined} className={classes} style={vars as CSSProperties}>
      {children}
    </Tag>
  );
}
