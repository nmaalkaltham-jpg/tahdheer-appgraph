/**
 * المظهر — التحقّق، والسمات الفعليّة، وتطابق سكربت ما قبل الرسم مع الخطّاف،
 * وتباين ألوان الهويّة الثلاثة كما تُكتب فعلًا في `globals.css`.
 *
 * التباين يُقاس من الورقة نفسها لا من نسخةٍ هنا: لونٌ يُعدَّل في الورقة
 * فيسقط تحت 4.5:1 يُسقط هذا الاختبار.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import {
  DEFAULT_APPEARANCE,
  PREFS_KEY,
  PREPAINT_SCRIPT,
  appearanceAttributes,
  parseAppearance,
} from '../appearance.ts';

test('وثيقةٌ تالفة: كلّ حقلٍ يعود إلى افتراضه وحده', () => {
  assert.deepEqual(parseAppearance(null), DEFAULT_APPEARANCE);
  const parsed = parseAppearance({ accent: 'pink', radius: 'soft', effects: { glass: 'off', shadows: 'huge' } });
  assert.equal(parsed.accent, 'teal');
  assert.equal(parsed.radius, 'soft');
  assert.equal(parsed.effects.glass, 'off');
  assert.equal(parsed.effects.shadows, 'medium');
});

test('تقليل الحركة في النظام يغلب «كاملة» ولا يرفع «بلا»', () => {
  const full = parseAppearance({ effects: { motion: 'full' } });
  assert.equal(appearanceAttributes(full, true).fxMotion, 'reduced');
  assert.equal(appearanceAttributes(full, false).fxMotion, 'full');
  const off = parseAppearance({ effects: { motion: 'off' } });
  assert.equal(appearanceAttributes(off, true).fxMotion, 'off');
});

test('الشدّة «بلا» تُطفئ الزخرفيّة الأربعة وتترك الظلال لمفتاحها', () => {
  const attrs = appearanceAttributes(parseAppearance({ effects: { intensity: 'off', shadows: 'high' } }), false);
  assert.deepEqual(
    [attrs.fxGlow, attrs.fxShine, attrs.fxGlass, attrs.fxAmbient, attrs.fxShadows],
    ['off', 'off', 'off', 'off', 'high'],
  );
});

/* يشغّل السكربت في سياقٍ معزول بمستندٍ وتخزينٍ مزيَّفَين. */
function runPrepaint(store, { light = false, reduce = false } = {}) {
  const dataset = {};
  const context = {
    document: { body: { dataset } },
    localStorage: { getItem: (k) => (k in store ? store[k] : null) },
    matchMedia: (q) => ({ matches: q.includes('light') ? light : q.includes('reduce') ? reduce : false }),
    JSON,
  };
  vm.runInNewContext(PREPAINT_SCRIPT, context);
  return dataset;
}

test('سكربت ما قبل الرسم يكتب ما يكتبه الخطّاف — بلا ومضة', () => {
  const cases = [
    [{}, false],
    [{ accent: 'navy', radius: 'compact', effects: { glass: 'off', motion: 'full', intensity: 'high' } }, true],
    [{ effects: { intensity: 'off', shadows: 'low' } }, false],
    [{ accent: 'bogus', effects: { glow: 'maybe' } }, false],
  ];
  for (const [appearance, reduce] of cases) {
    const dataset = runPrepaint({ [PREFS_KEY]: JSON.stringify({ appearance }) }, { reduce });
    const expected = appearanceAttributes(parseAppearance(appearance), reduce);
    for (const [key, value] of Object.entries(expected)) assert.equal(dataset[key], value, `${key} for ${JSON.stringify(appearance)}`);
  }
});

test('سكربت ما قبل الرسم: السمة والكثافة من مفاتيحهما القديمة، والتخزين التالف لا يرمي', () => {
  assert.equal(runPrepaint({ 'admin.theme': 'system' }, { light: true }).theme, 'light');
  assert.equal(runPrepaint({ 'admin.density': 'compact' }).density, 'compact');
  assert.equal(runPrepaint({ [PREFS_KEY]: '{not json' }).accent, 'teal');
});

/* ───── التباين من الورقة ───── */

const css = readFileSync(new URL('../../app/globals.css', import.meta.url), 'utf8');
const tokens = readFileSync(new URL('../../../../packages/design-system/src/tokens.css', import.meta.url), 'utf8');

/* قيم الحزمة: أوّل تعريفٍ لكلّ رمز (كتل `:root`). */
const TOKENS = {};
for (const [, name, value] of tokens.matchAll(/--([\w-]+):\s*([^;]+);/g)) if (!(name in TOKENS)) TOKENS[name] = value.trim();

/*
 * مقيّمٌ صغير لما تكتبه الورقة فعلًا: ‎#hex‎ · ‎hsl()‎ · ‎var()‎ · ‎color-mix(in srgb, A p%, B)‎.
 * ما لا يُفهم يُسقط الاختبار — لا تخمين.
 */
function colour(value, vars) {
  const x = value.trim();
  let m = x.match(/^var\(--([\w-]+)\)$/);
  if (m) {
    const next = vars[m[1]] ?? TOKENS[m[1]];
    assert.ok(next, `رمزٌ غير معرَّف: --${m[1]}`);
    return colour(next, vars);
  }
  m = x.match(/^color-mix\(in srgb,\s*(.+?)\s+([\d.]+)%,\s*(.+)\)$/);
  if (m) {
    const [a, b, p] = [colour(m[1], vars), colour(m[3], vars), Number(m[2]) / 100];
    return a.map((v, i) => v * p + b[i] * (1 - p));
  }
  m = x.match(/^hsl\(\s*([\d.]+)\s+([\d.]+)%\s+([\d.]+)%\s*\)$/);
  if (m) {
    const [h, s, l] = [Number(m[1]), Number(m[2]) / 100, Number(m[3]) / 100];
    const k = (n) => (n + h / 30) % 12;
    const a = s * Math.min(l, 1 - l);
    const f = (n) => l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1));
    return [f(0), f(8), f(4)].map((v) => v * 255);
  }
  m = x.match(/^#([0-9a-f]{6})$/i);
  assert.ok(m, `لونٌ غير مفهوم: ${value}`);
  return [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16));
}

const lum = (c) => {
  const f = (v) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
  return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
};
const ratio = (a, b) => {
  const [x, y] = [lum(a), lum(b)];
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
};

/* كتلة اللون من الورقة؛ الغائب منها (التركواز الداكن) قيمةُ الحزمة نفسها. */
function accentBlock(id) {
  const re = new RegExp(`body\\[data-accent='${id}'\\][^{]*\\{([^}]*)\\}`);
  const block = css.match(re)?.[1];
  assert.ok(block, `كتلة اللون ${id} غائبة من globals.css`);
  const vars = {};
  for (const [, name, value] of block.matchAll(/--([\w-]+):\s*([^;]+);/g)) vars[name] = value.trim();
  return vars;
}

const DARK_SURFACES = ['#111a2e', '#0a0f1e'];
const LIGHT_SURFACES = ['#ffffff', '#eef2f7'];

for (const id of ['teal', 'navy', 'gold']) {
  test(`لون «${id}»: نصٌّ ≥4.5:1 وحلقةٌ ≥3:1 في السمتين`, () => {
    const v = accentBlock(id);
    const c = (name) => colour(`var(--${name})`, v);
    for (const [theme, surfaces] of [['dark', DARK_SURFACES], ['light', LIGHT_SURFACES]]) {
      const ink = c(`adm-on-accent-${theme}`);
      for (const fill of [`cc-primary-${theme}`, `cc-primary-2-${theme}`]) {
        const r = ratio(ink, c(fill));
        assert.ok(r >= 4.5, `${id}/${theme}: حبر الزرّ على ${fill} = ${r.toFixed(2)}`);
      }
      for (const surface of surfaces) {
        const r = ratio(c(`cc-link-${theme}`), colour(surface, v));
        assert.ok(r >= 4.5, `${id}/${theme}: الرابط على ${surface} = ${r.toFixed(2)}`);
      }
    }
  });
}
