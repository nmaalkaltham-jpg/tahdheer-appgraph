/**
 * قواعد رموز التصميم — دوالٌّ صِرفة على النصّ، تُختبر في
 * `scripts/__tests__/design-tokens-rules.test.mjs`.
 *
 * البادئات المحروسة: ‎--sf-‎ ‎--hm-‎ ‎--pdp-‎ ‎--ch-‎ ‎--ct-‎ ‎--hv-‎. كلّ رمزٍ منها
 * يُستعمل يجب أن يُعرَّف في `packages/design-system/src/tokens.css` (أو
 * `motion.css`)، وكلّ رمزٍ معرَّفٍ يجب أن يُستعمل مرّةً على الأقلّ.
 */

export const PREFIXES = ['sf', 'hm', 'pdp', 'ch', 'ct', 'hv'];
const TOKEN = `--(?:${PREFIXES.join('|')})-[A-Za-z0-9_-]+`;

const stripComments = (s) => s.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/^\s*\/\/.*$/gm, ' ');

/** الرموز المعرَّفة في نصّ CSS: ‎--x:‎ في أوّل تصريح. */
export function definedTokens(css) {
  const out = new Set();
  const re = new RegExp(`(^|[{;\\s])(${TOKEN})\\s*:`, 'g');
  let m;
  const src = stripComments(css);
  while ((m = re.exec(src))) out.add(m[2]);
  return out;
}

/**
 * الرموز المستعمَلة: ‎var(--x‎ في CSS، ومفاتيحُ ‎'--x'‎ في كائنات style في TSX
 * (تعيينٌ مدفوعٌ بالبيانات يُعدّ استعمالًا)، وأيّ ‎var(--x‎ داخل TSX.
 */
export function referencedTokens(src) {
  const out = new Set();
  const re = new RegExp(`var\\(\\s*(${TOKEN})|['"](${TOKEN})['"](?:\\s+as\\s+string)?\\s*\\]?\\s*:`, 'g');
  let m;
  const s = stripComments(src);
  while ((m = re.exec(s))) out.add(m[1] ?? m[2]);
  return out;
}

/**
 * يقارن التعريفات (من tokens.css/motion.css) بالاستعمالات (من كلّ الملفّات).
 * `localDefs`: رموزٌ بالبادئات المحروسة عُرِّفت خارج ملفّي الرموز — تُبلَّغ
 * لأنّ مكانها الوحيد هو tokens.css.
 */
export function audit({ defined, referenced, localDefs = new Set() }) {
  const undefinedTokens = [...referenced].filter((t) => !defined.has(t)).sort();
  const dead = [...defined].filter((t) => !referenced.has(t)).sort();
  const stray = [...localDefs].filter((t) => !defined.has(t)).sort();
  return { undefinedTokens, dead, stray };
}
