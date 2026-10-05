// UNIT: CSS-COMPILE
// FILE: css-compile.ts
// STYLE: none
// PARENT: REGISTRY-INDEX
// USES: DOCUMENT, REGISTRY-TOKENS, STYLES
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * compileDocCss — مبثّ CSS الوحيد للمستند (STUDIO-PHASE2-CONTRACT §2.3،
 * قرار C17): أنماط العقد والأصناف والحالات، **ومعها الآن طبقتا الأجهزة**
 * (التابلت والجوّال) بالنطاقين المثبَّتين في المستودع. كانتا مؤجَّلتين مع
 * مصفوفة §16؛ وحدود التأجيل الباقية: النطاقات المخصّصة (1920…360) والترتيب
 * والتموضع لكلّ نطاق. نقي وخالٍ من DOM: زائر SSR والكانفس
 * يستدعيانه على المستند المطبَّع نفسه فيتطابق الناتج بايتًا ببايت — لا
 * مُصرّف ثانٍ في أي مكان (PHP يتحقق من الأشكال فقط).
 *
 * الحتمية (حرجة للتطابق): التصريحات بترتيب جدول STYLE_KEYS — أبدًا ترتيب
 * مفاتيح الكائن (json_encode في PHP قد يعيد ترتيبها)؛ الطبقات بترتيب
 * STATES؛ داخل الطبقة: الأصناف بترتيب المصفوفة ثم العقد بترتيب walkNodes
 * (تعارض التخصيص يُحسم بترتيب المصدر وحده — نمط العقدة يغلب). مُنسّق أرقام
 * واحد ثابت. المعرّفات خارج STUDIO_ID_PATTERN تُتجاهل قاعدتُها بأكملها
 * (الخادم يقفل النحو أصلًا — دفاع في العمق).
 *
 * DocumentRenderer يصيّر الناتج وسم <style> واحدًا عبر
 * dangerouslySetInnerHTML — sanitized بنيويًّا: لا نص حرّ يبلغ المُصرّف،
 * كل القيم تمرّ عبر قواعد نحو مغلقة، React يهرّب أبناء style النصية لو
 * مُرّرت نصًّا (لذا الخاصية إلزامية)، و`</style` غير قابل للتركيب.
 */

import { STUDIO_ID_PATTERN, isSymbolRef, walkNodes, type StudioDocument } from './document.ts';
import {
  BAND_MEDIA,
  DEVICE_BANDS,
  HEX_COLOR_PATTERN,
  STATE_PAINT_KEYS,
  STATES,
  STYLE_KEYS,
  type Border,
  type Color,
  type Dim,
  type StudioStyleSheet,
  type StyleKeySpec,
  type StyleState,
} from './styles.ts';
import { tokenByName } from './tokens.ts';

const SCOPE = '[data-studio-doc] ';

const PSEUDO: Record<Exclude<StyleState, 'base'>, string> = {
  hover: ':hover',
  active: ':active',
  focusWithin: ':focus-within',
};

/** رموز الظل المدعومة — token-backed حصرًا، لا نص ظل حرّ. */
const SHADOW_CSS: Record<string, string> = {
  none: 'none',
  card: 'var(--shadow-card)',
  float: 'var(--shadow-float)',
  'card-hover': 'var(--shadow-card-hover)',
};

/**
 * قاعدة الانتقال الوحيدة — تُبثّ فقط إذا وُجدت حالة غير أساسية، داخل
 * حارس تقليل الحركة (يكشف صراحةً: لا انتقال لمن طلب تقليل الحركة).
 */
const TRANSITION_RULE =
  '@media (prefers-reduced-motion: no-preference){' +
  `${SCOPE}[data-studio-node]{transition:background-color 160ms ease,color 160ms ease,` +
  'border-color 160ms ease,box-shadow 160ms ease,border-radius 160ms ease,opacity 160ms ease}' +
  '}';

const clamp = (n: number, lo: number, hi: number) => Math.min(Math.max(n, lo), hi);

/** مُنسّق الأرقام الثابت الوحيد — 3 منازل كحد أقصى بلا أصفار زائدة. */
function fmt(n: number): string {
  const r = Math.round(n * 1000) / 1000;
  return String(Object.is(r, -0) ? 0 : r);
}

/** مرجع رمز صالح النوع → var(--name)، وإلا null. */
function tokenCss(name: unknown, kind: 'color' | 'radius' | undefined): string | null {
  if (kind === undefined || typeof name !== 'string') return null;
  return tokenByName(name)?.kind === kind ? `var(--${name})` : null;
}

function dimCss(value: Dim, spec: StyleKeySpec): string | null {
  if (typeof value !== 'object' || value === null) return null;
  if (value.t === 'none') return spec.units?.includes('none') === true ? 'none' : null;
  if (value.t === 'token') return tokenCss(value.name, spec.tokenKind);
  if (typeof value.v !== 'number' || !Number.isFinite(value.v)) return null;
  if (spec.units?.includes(value.t) !== true) return null;
  const v = clamp(value.v, spec.min ?? value.v, spec.max ?? value.v);
  return `${fmt(v)}${value.t === 'pct' ? '%' : value.t}`;
}

function colorCss(value: Color): string | null {
  if (typeof value !== 'object' || value === null) return null;
  if (value.t === 'hex') return typeof value.v === 'string' && HEX_COLOR_PATTERN.test(value.v) ? value.v.toLowerCase() : null;
  if (value.t === 'token') return tokenCss(value.name, 'color');
  return null;
}

function borderCss(value: Border, spec: StyleKeySpec): string | null {
  if (typeof value !== 'object' || value === null) return null;
  if (value.t === 'none') return 'none';
  if (value.t !== 'solid' || typeof value.w !== 'number' || !Number.isFinite(value.w)) return null;
  const color = colorCss(value.color);
  if (color === null) return null;
  return `${fmt(clamp(Math.trunc(value.w), spec.min ?? 1, spec.max ?? 4))}px solid ${color}`;
}

function declFor(spec: StyleKeySpec, value: unknown): string | null {
  switch (spec.value) {
    case 'dim': {
      const css = dimCss(value as Dim, spec);
      return css === null ? null : `${spec.css}:${css}`;
    }
    case 'color': {
      const css = colorCss(value as Color);
      return css === null ? null : `${spec.css}:${css}`;
    }
    case 'enum':
      return typeof value === 'string' && spec.options?.includes(value) === true ? `${spec.css}:${value}` : null;
    case 'border': {
      const css = borderCss(value as Border, spec);
      return css === null ? null : `${spec.css}:${css}`;
    }
    case 'shadow': {
      const css = typeof value === 'string' ? SHADOW_CSS[value] : undefined;
      return css === undefined ? null : `${spec.css}:${css}`;
    }
    case 'opacity':
      return typeof value === 'number' && Number.isFinite(value)
        ? `${spec.css}:${fmt(clamp(value, spec.min ?? 0, spec.max ?? 100) / 100)}`
        : null;
  }
}

/** تصريحات طبقة واحدة بترتيب جدول STYLE_KEYS — الحالات غير الأساسية طلاءً فقط. */
function declsFor(props: Record<string, unknown> | undefined, state: StyleState): string {
  if (props === undefined || props === null || typeof props !== 'object') return '';
  const out: string[] = [];
  for (const spec of STYLE_KEYS) {
    if (state !== 'base' && !(STATE_PAINT_KEYS as readonly string[]).includes(spec.key)) continue;
    if (!Object.prototype.hasOwnProperty.call(props, spec.key)) continue;
    const decl = declFor(spec, props[spec.key]);
    if (decl !== null) out.push(decl);
  }
  return out.join(';');
}

/** قاعدة عند ثبات (0,2,0) للأساس؛ للحالات: زوج المعاينة القسرية (§2.3). */
function ruleFor(target: string, state: StyleState, decls: string): string {
  if (state === 'base') return `${SCOPE}${target}{${decls}}`;
  return `${SCOPE}${target}${PSEUDO[state]},${SCOPE}${target}[data-studio-state="${state}"]{${decls}}`;
}

/**
 * المُصرّف — الترتيب الكلي الحتمي: طبقة (base→hover→active→focusWithin) →
 * أصناف (ترتيب المصفوفة) → عقد (ترتيب walkNodes). مستند بلا أنماط ⇒ ''.
 */
export function compileDocCss(doc: StudioDocument): string {
  const classes = (doc.classes ?? []).filter((c) => typeof c.id === 'string' && STUDIO_ID_PATTERN.test(c.id));
  const styledNodes: { id: string; style: StudioStyleSheet }[] = [];
  for (const { node } of walkNodes(doc)) {
    if (isSymbolRef(node)) continue; // مرجع رمز بلا style بنيويًّا — والمُصرِّف يجري على المستند المتمدّد أصلًا (C37)
    if (node.style !== undefined && STUDIO_ID_PATTERN.test(node.id)) {
      styledNodes.push({ id: node.id, style: node.style });
    }
  }

  const rules: string[] = [];
  let hasStateRule = false;
  for (const state of STATES) {
    for (const def of classes) {
      const decls = declsFor(def.style[state] as Record<string, unknown> | undefined, state);
      if (decls === '') continue;
      rules.push(ruleFor(`[data-studio-class~="${def.id}"]`, state, decls));
      if (state !== 'base') hasStateRule = true;
    }
    for (const styled of styledNodes) {
      const decls = declsFor(styled.style[state] as Record<string, unknown> | undefined, state);
      if (decls === '') continue;
      rules.push(ruleFor(`[data-studio-node="${styled.id}"]`, state, decls));
      if (state !== 'base') hasStateRule = true;
    }
  }
  /*
   * طبقات الأجهزة — بعد الأساس والحالات وقبل حارس الحركة.
   *
   * كانت مؤجَّلة صراحةً مع مصفوفة §16 (قرار C17): «per-breakpoint STYLE
   * overrides (media tiers inside compileDocCss) stay deferred». وهذا هو
   * ذلك القسم، بالنطاقين المثبَّتين في المستودع لا بنطاقات مخترعة.
   *
   * الخصوصيّة لا تتغيّر — القاعدة داخل `@media` بالشكل نفسه (0,2,0) —
   * فالغلبة بترتيب المصدر وحده: الأساس ثمّ التابلت ثمّ الجوّال. لا
   * `!important` ولا محدّد مضخَّم، وهو ما يُبقي نمط العقدة غالبًا لنمط
   * الصنف داخل النطاق كما هو خارجه.
   *
   * والحتميّة محفوظة: النطاقات بترتيب `DEVICE_BANDS`، وداخل كلٍّ منها
   * الأصناف بترتيب المصفوفة ثمّ العقد بترتيب `walkNodes`، والتصريحات
   * بترتيب `STYLE_KEYS` — نفس قواعد الأساس حرفًا بحرف، فيبقى مخرَج SSR
   * والكانفس متطابقًا بايتًا ببايت.
   */
  for (const band of DEVICE_BANDS) {
    const inner: string[] = [];

    for (const def of classes) {
      const decls = declsFor(def.style[band] as Record<string, unknown> | undefined, 'base');
      if (decls === '') continue;
      inner.push(ruleFor(`[data-studio-class~="${def.id}"]`, 'base', decls));
    }
    for (const styled of styledNodes) {
      const decls = declsFor(styled.style[band] as Record<string, unknown> | undefined, 'base');
      if (decls === '') continue;
      inner.push(ruleFor(`[data-studio-node="${styled.id}"]`, 'base', decls));
    }

    // نطاقٌ بلا تجاوزٍ واحد لا يُبثّ: كتلة `@media` فارغة ضجيجٌ في كلّ صفحة.
    if (inner.length > 0) rules.push(`@media ${BAND_MEDIA[band]}{${inner.join('')}}`);
  }

  if (hasStateRule) rules.push(TRANSITION_RULE);
  return rules.join('\n');
}
