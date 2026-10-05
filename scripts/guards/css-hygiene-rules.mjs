/**
 * قواعد نظافة CSS/TSX — دوالٌّ صِرفة على النصّ، تُختبر في
 * `scripts/__tests__/css-hygiene-rules.test.mjs`؛ والحارس `guard-css-hygiene.mjs`
 * يمشي على الملفّات ويستدعيها.
 *
 * كلّ دالّة تعيد مصفوفة ‎{ rule, line, text }‎.
 */

/** يستبدل التعليقات بفراغاتٍ تحفظ الأسطر، فلا يتغيّر رقم السطر. */
export function stripCssComments(src) {
  return src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
}

const lineOf = (src, index) => src.slice(0, index).split('\n').length;
const lineText = (src, n) => src.split('\n')[n - 1]?.trim() ?? '';

/**
 * لونٌ خامّ: ‎#hex‎ أو ‎rgb()/rgba()/hsl()/hsla()‎ — حتّى داخل احتياطيّ ‎var()‎.
 * الاستثناء الوحيد: ما داخل ‎url()‎ (معرّفات SVG مثل ‎#grad‎).
 */
export function findRawColours(css) {
  const src = stripCssComments(css).replace(/url\([^)]*\)/g, (m) => ' '.repeat(m.length));
  const out = [];
  const re = /(#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?)\()/g;
  let m;
  while ((m = re.exec(src))) {
    const line = lineOf(src, m.index);
    if (out.at(-1)?.line !== line) out.push({ rule: 'raw-colour', line, text: lineText(css, line) });
  }
  return out;
}

/**
 * ‎!important‎ مسموحٌ في موضعين فقط: داخل كتلة
 * ‎@media (prefers-reduced-motion: reduce)‎، أو بعد تعليق
 * ‎/* guard-allow: important — السبب *\/‎ على السطر نفسه أو الذي قبله.
 */
export function findImportant(css) {
  const lines = css.split('\n');
  const out = [];
  let depth = 0;
  let reduceDepth = -1;
  for (let i = 0; i < lines.length; i++) {
    const raw = lines[i];
    const line = stripCssComments(raw);
    if (/@media[^{]*prefers-reduced-motion\s*:\s*reduce/.test(line) && reduceDepth < 0) reduceDepth = depth;
    /* الحكم بحالة بداية السطر: كتلةٌ تُفتح وتُغلق في سطرٍ واحد ما زالت كتلة reduce. */
    const insideReduce = reduceDepth >= 0;
    for (const ch of line) {
      if (ch === '{') depth++;
      else if (ch === '}') {
        depth--;
        if (reduceDepth >= 0 && depth <= reduceDepth) reduceDepth = -1;
      }
    }
    if (!/!\s*important/.test(line)) continue;
    const annotated = /guard-allow:\s*important/.test(raw) || /guard-allow:\s*important/.test(lines[i - 1] ?? '');
    if (!insideReduce && !annotated) out.push({ rule: 'important', line: i + 1, text: raw.trim() });
  }
  return out;
}

/** ‎z-index‎ حرفيّ أكبر من ‎100‎ (الطبقات العالية تأتي من رمزٍ ‎var(--z-…)‎). */
export function findZIndexLiterals(css, max = 100) {
  const src = stripCssComments(css);
  const out = [];
  const re = /z-index\s*:\s*(-?\d+)\s*[;}]/g;
  let m;
  while ((m = re.exec(src))) {
    if (Number(m[1]) > max) {
      const line = lineOf(src, m.index);
      out.push({ rule: 'z-index', line, text: lineText(css, line) });
    }
  }
  return out;
}

const STYLE_KEYS = /^(background|color|border|margin|padding|font|gap)/i;

/** قيمةٌ حرفيّة: نصٌّ أو رقمٌ أو قالبٌ بلا استيفاء. المعرّفات والخصائص بيانات. */
const isLiteral = (v) => /^(['"]|-?\d)/.test(v) || (v.startsWith('`') && !v.includes('${'));

/**
 * ‎style={{ … }}‎ في TSX: مفاتيحُ اللون والمسافات بقيمٍ **حرفيّة** ممنوعة
 * (مكانها CSS modules). يُتجاوَز: متغيّرات CSS ‎'--x'‎، والأبعاد ‎inlineSize‎
 * ونحوها، والقيم المدفوعة بالبيانات (معرّفٌ أو خاصّيّة مثل ‎badge.bg‎).
 * تُقرأ الأقواس متوازنةً فيُلتقط الكائن كاملًا ولو امتدّ أسطرًا.
 */
export function findInlineStyles(tsx) {
  const out = [];
  let idx = 0;
  while ((idx = tsx.indexOf('style={{', idx)) >= 0) {
    const start = idx + 'style={'.length;
    let depth = 0;
    let end = start;
    for (; end < tsx.length; end++) {
      if (tsx[end] === '{') depth++;
      else if (tsx[end] === '}') { depth--; if (depth === 0) break; }
    }
    const body = tsx.slice(start + 1, end);
    const re = /(?:^|[,{\s])(?:'([^']+)'|"([^"]+)"|\[\s*'([^']+)'[^\]]*\]|([A-Za-z_$][\w$]*))\s*:\s*/g;
    const bad = [];
    let m;
    while ((m = re.exec(body))) {
      const key = m[1] ?? m[2] ?? m[3] ?? m[4];
      const value = body.slice(re.lastIndex).split(/,(?![^(]*\))/)[0].trim();
      if (!key.startsWith('--') && STYLE_KEYS.test(key) && isLiteral(value)) bad.push(key);
    }
    if (bad.length) out.push({ rule: 'inline-style', line: lineOf(tsx, idx), text: `style={{ ${bad.join(', ')} }}` });
    idx = end;
  }
  return out;
}

/**
 * توجيهُ eslint مبرَّرٌ بصيغة ESLint الأصليّة (لا علامةً مخترَعة):
 *   // eslint-disable-next-line <قاعدة>[, <قاعدة>] -- <السبب>
 *   {/* eslint-disable-next-line <قاعدة> -- <السبب> *\/}
 * يمرّ فقط ‎-next-line‎ أو ‎-line‎ يسمّي قاعدةً فأكثر ومعه سببٌ غير فارغ بعد ‎--‎.
 * يسقط: التوجيه بلا قاعدة، أو بلا سبب، أو صيغة الكتلة/الملفّ ‎/* eslint-disable *\/‎.
 */
export function justifiedEslint(line) {
  const m = line.match(/eslint-disable(-next-line|-line)?\b([^\n]*?)(?:\*\/|$)/);
  if (!m || !m[1]) return false;
  const [rules, ...why] = m[2].split(/\s--\s/);
  const named = rules.split(',').map((r) => r.trim()).filter(Boolean);
  return named.length > 0 && why.join(' -- ').trim().length > 0;
}

/** علاماتُ التأجيل والإسكات: eslint-disable غير المبرَّر · test.skip/it.skip · TODO/FIXME. */
export function findMarkers(src) {
  const out = [];
  src.split('\n').forEach((l, i) => {
    if (/eslint-disable/.test(l) && !justifiedEslint(l)) out.push({ rule: 'eslint-disable', line: i + 1, text: l.trim() });
    if (/\b(?:test|it|describe)\.skip\s*\(/.test(l)) out.push({ rule: 'test-skip', line: i + 1, text: l.trim() });
    if (/\b(?:TODO|FIXME)\b/.test(l)) out.push({ rule: 'todo', line: i + 1, text: l.trim() });
  });
  return out;
}

export function analyzeCss(css) {
  return [...findRawColours(css), ...findImportant(css), ...findZIndexLiterals(css), ...findMarkers(css)];
}

export function analyzeTsx(tsx) {
  return [...findInlineStyles(tsx), ...findMarkers(tsx)];
}
