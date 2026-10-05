/**
 * عقد نظام التصميم الموحّد (docs/architecture/DESIGN-SYSTEM-2026-09-25.md) —
 * الرموز بأسمائها وقيمها، وتباين الثلاثيّات الدلاليّة محسوبًا، ونظافة الوصفات.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const root = new URL('../../../../', import.meta.url);
const tokensCss = readFileSync(new URL('packages/design-system/src/tokens.css', root), 'utf8');
const controlsCss = readFileSync(new URL('packages/ui/src/controls.module.css', root), 'utf8');
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '');

/* قيم الرموز محلولةً عبر سلاسل var() — أوّل تعريفٍ في :root هو المرجع. */
const raw = new Map();
for (const m of strip(tokensCss).matchAll(/(--[\w-]+)\s*:\s*([^;}]+)/g)) if (!raw.has(m[1])) raw.set(m[1], m[2].trim());
const resolve = (v, d = 0) => (d > 8 ? v : v.replace(/var\(\s*(--[\w-]+)\s*\)/g, (_, n) => resolve(raw.get(n) ?? `var(${n})`, d + 1)));
const val = (n) => {
  assert.ok(raw.has(n), `${n} معرَّف`);
  return resolve(raw.get(n)).replace(/\s+/g, ' ');
};

test('الزوايا والحركة والأحجام بقيم العقد', () => {
  const contract = {
    '--r-xs': '8px', '--r-sm': '10px', '--r-md': '12px', '--r-lg': '16px', '--r-xl': '20px', '--r-2xl': '24px', '--r-round': '999px',
    '--e-0': 'none',
    '--dur-press': '120ms', '--dur-hover': '180ms', '--dur-reveal': '260ms', '--ease-out': 'cubic-bezier(0.2, 0.8, 0.2, 1)',
    '--ctl-sm': '36px', '--ctl-md': '46px', '--ctl-lg': '54px', '--field-h': '48px', '--chip-h': '44px',
    '--focus-halo': '0 0 0 4px rgba(23, 138, 120, 0.18)',
  };
  for (const [n, v] of Object.entries(contract)) assert.equal(val(n), v, n);
  for (const n of ['--e-1', '--e-2', '--e-3', '--e-4', '--btn-shadow-primary', '--btn-shadow-secondary']) {
    assert.match(val(n), /rgba\((20, 42, 78|22, 133, 116)/, `${n} ظلٌّ مصبوغ لا رماديّ`);
  }
  for (const n of ['--btn-highlight', '--btn-shadow-primary-hover', '--focus-halo-danger']) val(n);
});

const hex = (v) => {
  const m = v.match(/^#([0-9a-f]{6})$/i);
  assert.ok(m, `لون سداسيّ: ${v}`);
  return [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16) / 255);
};
const lum = (rgb) => {
  const [r, g, b] = rgb.map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const contrast = (a, b) => {
  const [x, y] = [lum(hex(a)), lum(hex(b))].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
};

test('الثلاثيّات الدلاليّة: نصّها ≥4.5:1 على خلفيّتها (WCAG)', () => {
  for (const s of ['info', 'success', 'warning', 'danger']) {
    val(`--st-${s}-line`);
    const r = contrast(val(`--st-${s}-fg`), val(`--st-${s}-bg`));
    assert.ok(r >= 4.5, `--st-${s}: ${r.toFixed(2)}:1`);
  }
});

/* كتل @media مع محتواها (الأقواس متوازنة). */
const mediaBlocks = (src, re) => {
  const out = [];
  for (const m of src.matchAll(/@media[^{]*\{/g)) {
    if (!re.test(m[0])) continue;
    let d = 1;
    let i = m.index + m[0].length;
    for (; i < src.length && d; i++) d += src[i] === '{' ? 1 : src[i] === '}' ? -1 : 0;
    out.push(src.slice(m.index, i));
  }
  return out;
};

test('كلّ صنفٍ متحرّك في الوصفات له نظيرٌ في prefers-reduced-motion', () => {
  const css = strip(controlsCss);
  const reduce = mediaBlocks(css, /prefers-reduced-motion\s*:\s*reduce/).join('\n');
  const gated = mediaBlocks(css, /prefers-reduced-motion\s*:\s*no-preference/);
  assert.ok(reduce, 'كتلة reduce موجودة');
  let rest = css;
  for (const b of [reduce, ...gated]) rest = rest.replace(b, '');
  const reduced = new Set([...reduce.matchAll(/\.([A-Za-z]\w*)/g)].map((m) => m[1]));
  const moving = new Set();
  for (const m of rest.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    if (!/(^|;|\s)(transition|animation)\s*:/.test(m[2]) || /^\s*(from|to|\d)/.test(m[1])) continue;
    for (const c of m[1].matchAll(/\.([A-Za-z]\w*)/g)) moving.add(c[1]);
  }
  assert.ok(moving.size >= 6, `أصنافٌ متحرّكة: ${[...moving]}`);
  for (const c of moving) assert.ok(reduced.has(c), `.${c} بلا نظير reduce`);
});

test('الوصفات: لا ألوان خامّة، خصائص منطقيّة، والأصناف المعلنة موجودة', () => {
  const css = strip(controlsCss).replace(/url\([^)]*\)/g, '');
  assert.doesNotMatch(css, /#[0-9a-f]{3,8}\b|\b(rgba?|hsla?)\(/i);
  assert.doesNotMatch(css, /(^|[{;\s])(margin|padding)-(left|right)\s*:|(^|[{;\s])(left|right)\s*:/m);
  const classes = new Set([...css.matchAll(/\.([A-Za-z]\w*)/g)].map((m) => m[1]));
  for (const c of ['btn', 'btnSm', 'btnLg', 'btnPrimary', 'btnSecondary', 'btnOutline', 'btnGhost', 'btnDanger', 'btnIcon',
    'field', 'label', 'hint', 'fieldError', 'chip', 'tab', 'card', 'cardInteractive', 'panel', 'menu', 'menuItem',
    'panelHero', 'dialog', 'scrim', 'drawer', 'notice', 'noticeInfo', 'noticeSuccess', 'noticeWarning', 'noticeDanger', 'skeleton', 'divider']) {
    assert.ok(classes.has(c), `.${c}`);
  }
  /* كلّ رمزٍ من كتلة DS مستعمَلٌ في الوصفات أو في رمزٍ آخر */
  const ds = tokensCss.slice(tokensCss.indexOf('/* DS — نظام موحّد'));
  for (const m of strip(ds).matchAll(/(--[\w-]+)\s*:/g)) {
    assert.ok(controlsCss.includes(`var(${m[1]})`), `${m[1]} مستعمَل في الوصفات`);
  }
});
