/**
 * مثبّتات حارس تقليل الحركة. الحكمُ لكلّ قاعدةٍ مخفيّة: ما يكشفه تفاعلُ
 * المستعمل لا ينتظر حركة، وما يكشفه سكربتٌ أو حركةٌ ينتظرها ويجب أن يُكشف
 * تحت reduce.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { analyze } from '../guards/reduced-motion-rules.mjs';

const fails = (css) => {
  const v = analyze(css);
  return v.hidesPendingMotion && !v.revealsUnderReduce;
};

/* ─── إيجابيّ: يجب أن يسقط ─── */

test('entrance revealed by a script-set state class fails without a reduce reveal', () => {
  assert.equal(fails('.reveal{opacity:0;transition:opacity .3s}.reveal.isIn{opacity:1}'), true);
});

test('collapsed panel opened by a state attribute fails without a reduce reveal', () => {
  assert.equal(fails('.panel{max-block-size:0;transition:max-block-size .2s}.panel[data-open]{max-block-size:40rem}'), true);
});

test('hiding keyframes only paused under reduce still fail: pause freezes the transparent frame', () => {
  const css = '.x{animation:fade .3s both}@keyframes fade{from{opacity:0}to{opacity:1}}'
    + '@media (prefers-reduced-motion: reduce){.x{animation-play-state:paused}}';
  assert.equal(fails(css), true);
});

test('a hover rule that keeps the target transparent is not a reveal', () => {
  assert.equal(fails('.hint{opacity:0;transition:opacity .1s}.btn:hover .hint{opacity:0}'), true);
});

/* ─── سلبيّ: يجب أن ينجح ─── */

test('hint revealed on hover and keyboard focus passes (data-table sort arrow)', () => {
  const css = '.sortIdle{opacity:0;transition:opacity .14s ease}'
    + '.table th:hover .sortIdle,.sortButton:focus-visible .sortIdle{opacity:.45}'
    + '@media (prefers-reduced-motion: reduce){.sortIdle{transition:none}}';
  assert.equal(fails(css), false);
});

test('entrance with an explicit reduce reveal passes', () => {
  const css = '.reveal{opacity:0;transition:opacity .3s}.reveal.isIn{opacity:1}'
    + '@media (prefers-reduced-motion: reduce){.reveal{opacity:1;transition:none}}';
  assert.equal(fails(css), false);
});

test('hiding keyframes cancelled under reduce land on the visible resting state', () => {
  const css = '.x{animation:fade .3s both}@keyframes fade{from{opacity:0}to{opacity:1}}'
    + '@media (prefers-reduced-motion: reduce){.x{animation:none}}';
  assert.equal(fails(css), false);
});

test('a file with no motion never counts as hiding pending motion', () => {
  assert.equal(analyze('.sr{opacity:0}').hidesPendingMotion, false);
});

/* ─── حدّيّ: حتميّ ─── */

test('one hover-gated and one script-gated hider in the same file: the file fails, naming only the script-gated one', () => {
  const css = '.hint{opacity:0;transition:opacity .1s}.btn:hover .hint{opacity:1}'
    + '.reveal{opacity:0;transition:opacity .3s}.reveal.isIn{opacity:1}';
  const v = analyze(css);
  assert.equal(fails(css), true);
  assert.deepEqual(v.gatedSelectors, ['.reveal']);
});

test('opacity values that are not zero are not hiding: 0.5 and .05', () => {
  assert.equal(analyze('.a{opacity:0.5;transition:opacity .1s}.b{opacity:.05}').hidesPendingMotion, false);
});

test('commented-out rules are ignored', () => {
  assert.equal(analyze('/* .a{opacity:0} */.a{transition:color .1s}').hidesPendingMotion, false);
});

test('rules nested in other media queries are judged like top-level ones', () => {
  assert.equal(fails('@media (min-width:40em){.reveal{opacity:0;transition:opacity .3s}.reveal.in{opacity:1}}'), true);
});

test('same input gives the same verdict every time', () => {
  const css = '.hint{opacity:0;transition:opacity .1s}.btn:focus-within .hint{opacity:1}';
  assert.deepEqual(analyze(css), analyze(css));
});

test('keyframes defined but never used are reported as orphans', () => {
  const v = analyze('@keyframes gone{to{opacity:1}}.a{animation:kept 1s}@keyframes kept{to{opacity:1}}');
  assert.deepEqual(v.orphanKeyframes, ['gone']);
});
