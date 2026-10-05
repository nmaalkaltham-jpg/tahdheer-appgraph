/** مثبّتات حارس سلّم الزوايا (guard-radius-scale.mjs). */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { findRadiusViolations, tokenValues, partOk, ratchet } from '../guards/guard-radius-scale.mjs';

const tokens = tokenValues(':root{--radius-lg:16px;--radius-card:14px;--r-md:var(--radius-button);--radius-button:12px;--alias:var(--radius-card)}');

test('scale: allowed px, 50%, var(--r-*) and zero pass; off-scale px and % fail', () => {
  for (const ok of ['8px', '10px', '12px', '16px', '20px', '24px', '999px', '50%', '0', 'var(--r-xl)', 'inherit']) assert.ok(partOk(ok, tokens), ok);
  for (const bad of ['6px', '14px', '9px', '4px', '30%', '0.5rem']) assert.ok(!partOk(bad, tokens), bad);
});

test('var(): non --r-* tokens are resolved through tokens.css, unknown ones fail', () => {
  assert.ok(partOk('var(--radius-lg)', tokens));
  assert.ok(!partOk('var(--radius-card)', tokens));
  assert.ok(!partOk('var(--alias)', tokens), 'chained alias resolves to 14px');
  assert.ok(!partOk('var(--nowhere)', tokens));
});

test('violations: shorthand, logical longhands, slash syntax, comments and calc()', () => {
  const css = `.a{border-radius:14px}
.b{border-radius:var(--r-md) var(--r-md) 6px 6px}
.c{border-start-start-radius:13px;border-end-end-radius:12px}
/* .d{border-radius:3px} */
.e{border-radius:16px / 8px}
.f{border-radius:calc(var(--r-lg) - 4px)}
.g{border-top-left-radius:7px}`;
  const v = findRadiusViolations(css, tokens);
  assert.deepEqual(v.map((x) => [x.line, x.prop]), [
    [1, 'border-radius'],
    [2, 'border-radius'],
    [3, 'border-start-start-radius'],
    [7, 'border-top-left-radius'],
  ]);
});

test('ratchet: increases and new files fail; decreases are reported as tightenable', () => {
  const { worse, tighter } = ratchet({ 'a.css': 3, 'b.css': 1, 'new.css': 1 }, { 'a.css': 2, 'b.css': 4, 'gone.css': 1 });
  assert.deepEqual(worse.map((w) => w.file), ['a.css', 'new.css']);
  assert.deepEqual(tighter.map((t) => t.file), ['b.css', 'gone.css']);
});
