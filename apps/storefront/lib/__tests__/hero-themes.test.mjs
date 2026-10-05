/**
 * مواضيع الهيرو الستّة — كلّ نصٍّ ≥4.5:1 على كلّ درجةٍ من سطحه (WCAG AA للنصّ
 * العاديّ، ولو كان العنوان كبيرًا). يُحسب من كتلة «HOME — hero themes» في
 * tokens.css — المصدر نفسه الذي تقرؤه design.module.css — فلا نسخة ثانية تنحرف.
 *
 * والتدرّج يُفحص درجةً درجة: النصّ قد يقع على أيٍّ منها (المحاذاة «center»،
 * والعنوان الطويل). والنصف الشفّاف (`--hm-white-85`) يُمزج فوق الدرجة قبل القياس.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const root = new URL('../../../../', import.meta.url);
const css = readFileSync(new URL('packages/design-system/src/tokens.css', root), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
const raw = new Map();
for (const m of css.matchAll(/(--[\w-]+)\s*:\s*([^;}]+)/g)) if (!raw.has(m[1])) raw.set(m[1], m[2].trim());

const resolve = (v, depth = 0) => {
  assert.ok(depth < 10, `دورة في ${v}`);
  return v.replace(/var\((--[\w-]+)\)/g, (_, name) => {
    assert.ok(raw.has(name), `رمزٌ غير معرَّف: ${name}`);
    return resolve(raw.get(name), depth + 1);
  });
};
const token = (name) => {
  assert.ok(raw.has(name), `رمزٌ غير معرَّف: ${name}`);
  return resolve(raw.get(name));
};

/** `#rrggbb` أو `rgba(r,g,b,a)` ⇒ [r,g,b,a] على ‎0–1‎. */
const parse = (c) => {
  let m = c.match(/^#([0-9a-f]{6})$/i);
  if (m) return [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16) / 255).concat(1);
  m = c.match(/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+))?\s*\)$/);
  assert.ok(m, `لونٌ لا يُقرأ: ${c}`);
  return [m[1], m[2], m[3]].map((x) => Number(x) / 255).concat(m[4] === undefined ? 1 : Number(m[4]));
};
const over = (fg, bg) => fg.slice(0, 3).map((c, i) => c * fg[3] + bg[i] * (1 - fg[3])).concat(1);
const lum = ([r, g, b]) =>
  [r, g, b].map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)).reduce((s, c, i) => s + c * [0.2126, 0.7152, 0.0722][i], 0);
const ratio = (fg, bg) => {
  const [x, y] = [lum(over(fg, bg)), lum(bg)].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
};
/** درجات السطح: لونٌ واحد، أو كلّ ألوان التدرّج. */
const stops = (bg) => [...bg.matchAll(/#[0-9a-f]{6}\b|rgba?\([^)]*\)/gi)].map((m) => parse(m[0]));

const THEMES = ['navy-teal', 'navy', 'teal', 'sand', 'mint', 'ink'];
const ROLES = ['fg', 'muted', 'accent', 'eyebrow'];

test('كلّ موضوعٍ معرَّفٌ كاملًا: سطح + نصّ + وصف + لمسة + سطر علويّ', () => {
  for (const t of THEMES) for (const r of ['bg', ...ROLES]) token(`--hero-${t}-${r}`);
  token('--hero-navy-teal-bg-mobile');
});

for (const t of THEMES) {
  test(`«${t}»: كلّ نصٍّ ≥4.5:1 على كلّ درجةٍ من السطح`, () => {
    const surfaces = [...stops(token(`--hero-${t}-bg`)), ...(t === 'navy-teal' ? stops(token('--hero-navy-teal-bg-mobile')) : [])];
    assert.ok(surfaces.length > 0, 'سطحٌ بلا لون');
    for (const role of ROLES) {
      const fg = parse(token(`--hero-${t}-${role}`));
      for (const bg of surfaces) {
        const r = ratio(fg, bg);
        assert.ok(r >= 4.5, `--hero-${t}-${role}: ${r.toFixed(2)}:1`);
      }
    }
  });
}

test('فوق الصور: حجاب النصّ يُبقي الأبيض واللمسة ≥4.5:1 ولو كانت الصورة بيضاء', () => {
  const scrim = parse(token('--hero-scrim'));
  for (const image of ['#ffffff', '#f6eedc', '#000000'].map(parse)) {
    /* أسوأ حالة: `overlay: none` — الحجاب وحده فوق الصورة. */
    const under = over(scrim, image);
    for (const role of ['#ffffff', token('--hero-accent-light'), token('--hm-white-85')]) {
      const r = ratio(parse(role), under);
      assert.ok(r >= 4.5, `${role} على صورةٍ ${image.slice(0, 3)}: ${r.toFixed(2)}:1`);
    }
  }
});

test('الدعوات: الذهبيّ بحبرٍ، والكحليّ بأبيض، والمحيطيّ الكحليّ على الأسطح الفاتحة — ≥4.5:1', () => {
  const gold = parse(token('--gold-400'));
  const ink = parse(token('--sf-ink'));
  const navy = parse(token('--navy-700'));
  const white = parse(token('--on-accent'));
  assert.ok(ratio(ink, gold) >= 4.5, `ذهبيّ: ${ratio(ink, gold).toFixed(2)}`);
  assert.ok(ratio(white, navy) >= 4.5, `كحليّ: ${ratio(white, navy).toFixed(2)}`);
  for (const t of ['sand', 'mint']) {
    for (const bg of stops(token(`--hero-${t}-bg`))) assert.ok(ratio(navy, bg) >= 4.5, `محيطيّ على ${t}`);
  }
});
