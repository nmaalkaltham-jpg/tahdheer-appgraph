/**
 * مثبّتات قواعد نظافة CSS/TSX — كلّ قاعدةٍ لها إيجابيّ يجب أن يسقط وسلبيّ
 * يجب أن يمرّ.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { findRawColours, findImportant, findZIndexLiterals, findInlineStyles, findMarkers, justifiedEslint } from '../guards/css-hygiene-rules.mjs';

const rules = (list) => list.map((f) => f.rule);

test('raw colours: hex, rgb(), hsl() and var() fallbacks all fail', () => {
  const css = '.a{color:#fff}\n.b{background:rgba(0,0,0,.5)}\n.c{border-color:hsl(10 20% 30%)}\n.d{color:var(--x,#123456)}';
  assert.equal(findRawColours(css).length, 4);
});

test('raw colours: tokens, transparent, currentColor, url(#id) and comments pass', () => {
  const css = '/* #fff in a comment */\n.a{color:var(--sf-ink);background:transparent;fill:currentColor;mask:url(#grad)}';
  assert.deepEqual(findRawColours(css), []);
});

test('!important fails outside reduce, passes inside reduce or with guard-allow', () => {
  const bad = '.a{color:red!important}';
  assert.deepEqual(rules(findImportant(bad)), ['important']);
  const reduce = '@media (prefers-reduced-motion: reduce){.a{animation:none!important}}\n.b{x:1}';
  assert.deepEqual(findImportant(reduce), []);
  const annotated = '/* guard-allow: important — يغلب نمط مكتبة خارجيّة */\n.a{color:var(--x) !important}';
  assert.deepEqual(findImportant(annotated), []);
  const afterReduce = '@media (prefers-reduced-motion: reduce){.a{x:1}}\n.b{y:2 !important}';
  assert.equal(findImportant(afterReduce).length, 1);
});

test('z-index literals above 100 fail; tokens and small literals pass', () => {
  assert.equal(findZIndexLiterals('.a{z-index:1000;}').length, 1);
  assert.deepEqual(findZIndexLiterals('.a{z-index:2}.b{z-index:var(--z-modal)}.c{z-index:100}'), []);
});

test('inline style: literal colour/spacing values fail; css variables, sizes and data-driven values pass', () => {
  const bad = "<span style={{ background: '#fff', color: 'red' }} />";
  assert.deepEqual(rules(findInlineStyles(bad)), ['inline-style']);
  const multi = '<i style={{\n  marginInlineStart: 4,\n  fontSize: 12,\n}} />';
  assert.equal(findInlineStyles(multi)[0].text, 'style={{ marginInlineStart, fontSize }}');
  const ok = "<a style={{ '--pc-density': String(d), inlineSize: '78%' }} /><b style={{ ['--mo-index' as string]: '1' }} />";
  assert.deepEqual(findInlineStyles(ok), []);
  const dataDriven = '<span style={{ background: badge.bg, color: badge.fg, gap: `${g}px` }} />';
  assert.deepEqual(findInlineStyles(dataDriven), []);
});

test('markers: eslint-disable, test.skip/it.skip and TODO/FIXME are reported with lines', () => {
  const src = '// eslint-disable-next-line x\nit.skip("a", () => {});\n// TODO later\nconst todos = 1;';
  assert.deepEqual(findMarkers(src).map((f) => [f.rule, f.line]), [['eslint-disable', 1], ['test-skip', 2], ['todo', 3]]);
});

test('eslint: next-line/line naming rules with a -- reason passes, in // and {/* */} forms', () => {
  assert.equal(justifiedEslint('// eslint-disable-next-line @next/next/no-img-element -- صورة CDN بأبعاد ديناميكيّة'), true);
  assert.equal(justifiedEslint('{/* eslint-disable-next-line react-hooks/exhaustive-deps, no-console -- يُشغَّل مرّة */}'), true);
  assert.equal(justifiedEslint('x(); // eslint-disable-line no-console -- تشخيص'), true);
  assert.deepEqual(findMarkers('// eslint-disable-next-line no-console -- تشخيص\nconsole.log(1);'), []);
});

test('eslint: bare, reasonless, empty-reason and block/file-level disables fail', () => {
  assert.equal(justifiedEslint('// eslint-disable-next-line'), false);
  assert.equal(justifiedEslint('// eslint-disable-next-line -- سببٌ بلا قاعدة'), false);
  assert.equal(justifiedEslint('// eslint-disable-next-line no-console'), false);
  assert.equal(justifiedEslint('// eslint-disable-next-line no-console -- '), false);
  assert.equal(justifiedEslint('/* eslint-disable */'), false);
  assert.equal(justifiedEslint('/* eslint-disable no-console -- ملفٌّ كامل */'), false);
  assert.equal(findMarkers('/* eslint-disable */\nlet a;').length, 1);
});
