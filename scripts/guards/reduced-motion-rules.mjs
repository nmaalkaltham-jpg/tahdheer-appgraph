/**
 * قواعدُ حارس تقليل الحركة — دوالّ نقيّة تُختبر بمثبّتات
 * (scripts/__tests__/reduced-motion-rules.test.mjs).
 *
 * كان الحكم على مستوى الملفّ: أيُّ `opacity:0` مع أيّ `transition` في
 * الملفّ نفسه = «محتوى ينتظر حركة». فسقط `data-table.module.css` لأنّ سهم
 * الفرز الخامل مخفيٌّ حتى التحويم أو التركيز — والتحويمُ يعمل تحت reduce
 * كما يعمل بدونه. الحكم الآن لكلّ قاعدةٍ مخفيّة على حدة.
 *
 * ما يُعفى: قاعدةٌ مخفيّة يكشفها **تفاعلُ المستعمل وحده** — قاعدةٌ أخرى
 * بصنفٍ زائفٍ تفاعليّ (:hover :focus :focus-visible :focus-within :active)
 * تنتهي بالهدف نفسه وتكشفه. وما لا يُعفى أبدًا: الكشف بصنفِ حالةٍ يضعه
 * سكربت (`.reveal.isIn`) — هذا هو العيب الذي وُجد الحارس له: سكربتٌ
 * يؤجّل الكشف إلى نهاية حركةٍ قد لا تقع، فيبقى المحتوى مخفيًّا.
 */

export const HIDES = /opacity\s*:\s*0(?![.\d])|max-block-size\s*:\s*0(?![.\d])/;
export const ANIMATES = /transition|animation/;
export const REVEALS =
  /opacity\s*:\s*1|max-block-size\s*:\s*(none|auto|unset|initial|max-content|fit-content)|visibility\s*:\s*visible|transform\s*:\s*none/;
export const KILLS =
  /animation\s*:\s*none|animation-duration\s*:\s*0?\.?0*1?m?s|animation-play-state\s*:\s*paused|animation-iteration-count\s*:\s*1/;

const INTERACTION = /:(hover|focus|focus-visible|focus-within|active)\b/;
/** كشفٌ في قاعدة تفاعل: شفافيّةٌ غير صفريّة أو ارتفاعٌ غير صفريّ. */
const SHOWS = /opacity\s*:\s*(1|0?\.\d*[1-9])|max-block-size\s*:\s*(?!0(?![.\d]))[^;]+/;
const KEYFRAME_STEP = /^(from|to|\d+(\.\d+)?%)(\s*,\s*(from|to|\d+(\.\d+)?%))*$/;

const stripComments = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '');

/**
 * تعطيلٌ يُنزل العنصرَ على حالته المستقرّة. الإيقاف (`paused`) أو المرّة
 * الواحدة ليسا منه: الإيقاف يجمّد الإطار الأوّل — وقد يكون شفّافًا.
 */
const LANDS = /animation\s*:\s*none|animation-duration\s*:\s*0?\.?0*1?m?s/;

/** أسماء keyframes التي تُخفي في إحدى خطواتها. */
function hidingKeyframes(clean) {
  const out = [];
  for (const m of clean.matchAll(/@keyframes\s+([\w-]+)\s*\{/g)) {
    let depth = 1;
    let i = m.index + m[0].length;
    while (i < clean.length && depth > 0) {
      if (clean[i] === '{') depth += 1;
      else if (clean[i] === '}') depth -= 1;
      i += 1;
    }
    if (HIDES.test(clean.slice(m.index, i))) out.push(m[1]);
  }
  return out;
}

/** القواعد الداخليّة مسطّحةً (المحتوى في @media يُعامل كغيره). */
export function rules(src) {
  const out = [];
  for (const m of stripComments(src).matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    const selector = m[1].trim();
    if (selector.startsWith('@') || KEYFRAME_STEP.test(selector)) continue;
    out.push({ selectors: selector.split(',').map((s) => s.trim()).filter(Boolean), body: m[2] });
  }
  return out;
}

/** آخر مركّبٍ في المحدِّد — الهدف الذي تُطبَّق عليه القاعدة — بلا أصنافٍ زائفة. */
const target = (selector) =>
  (selector.split(/\s+|>|\+|~/).filter(Boolean).pop() ?? '').replace(/::?[\w-]+(\([^)]*\))?/g, '');

/** محتوى كل كتل @media (prefers-reduced-motion: reduce) مجمَّعًا (موازنة أقواس). */
export function reduceBlocks(src) {
  let out = '';
  const re = /@media[^{]*prefers-reduced-motion\s*:\s*reduce[^{]*\{/g;
  let m;
  while ((m = re.exec(src))) {
    let depth = 1;
    let i = m.index + m[0].length;
    const start = i;
    while (i < src.length && depth > 0) {
      if (src[i] === '{') depth += 1;
      else if (src[i] === '}') depth -= 1;
      i += 1;
    }
    out += src.slice(start, i - 1) + '\n';
  }
  return out;
}

/**
 * المحدِّدات التي تُخفي محتوًى ولا يكشفها تفاعلُ المستعمل — أي تنتظر حركةً
 * أو سكربتًا. فارغةٌ إن لم يكن في الملفّ حركةٌ أصلًا.
 */
export function motionGatedHidden(src) {
  if (!ANIMATES.test(src)) return [];
  const all = rules(src);
  const revealedByInteraction = new Set(
    all
      .filter((r) => SHOWS.test(r.body))
      .flatMap((r) => r.selectors.filter((s) => INTERACTION.test(s)).map(target)),
  );
  const byRule = all
    .filter((r) => HIDES.test(r.body))
    .flatMap((r) => r.selectors)
    .filter((s) => !INTERACTION.test(s) && !revealedByInteraction.has(target(s)));
  // خطوةٌ مخفيّة في keyframes لا يُعفيها إلّا تعطيلٌ يُنزل على الحالة المستقرّة.
  const byKeyframes = LANDS.test(reduceBlocks(src))
    ? []
    : hidingKeyframes(stripComments(src)).map((name) => `@keyframes ${name}`);
  return [...byRule, ...byKeyframes];
}

/** حكمُ الملفّ: هل يخفي بانتظار حركة، وهل يكشف تحت reduce. */
export function analyze(src) {
  const reduce = reduceBlocks(src);
  const gated = motionGatedHidden(src);
  const clean = stripComments(src);
  const orphanKeyframes = [...clean.matchAll(/@keyframes\s+([\w-]+)/g)]
    .map((m) => m[1])
    .filter((name) => !new RegExp(`animation(-name)?\\s*:[^;{}]*\\b${name}\\b`).test(clean));
  return {
    hidesPendingMotion: gated.length > 0,
    gatedSelectors: gated,
    revealsUnderReduce: REVEALS.test(reduce),
    hasKeyframes: /@keyframes/.test(clean),
    killsUnderReduce: KILLS.test(reduce),
    orphanKeyframes,
  };
}
