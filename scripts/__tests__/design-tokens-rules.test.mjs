/** مثبّتات قواعد رموز التصميم. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { definedTokens, referencedTokens, audit } from '../guards/design-tokens-rules.mjs';

test('definitions: only guarded prefixes, first declaration form, comments ignored', () => {
  const css = ':root{--sf-ink:#000;--hm-gap:4px;--other:1px}\n/* --pdp-ghost: 1 */\n.a{--ct-x:2}';
  assert.deepEqual([...definedTokens(css)].sort(), ['--ct-x', '--hm-gap', '--sf-ink']);
});

test('references: var() in css and quoted style keys in tsx', () => {
  const css = '.a{color:var(--sf-ink);gap:var( --hm-gap, 2px)}';
  const tsx = "style={{ '--ch-marquee-s': '3s', ['--hv-i' as string]: 1 }} className={x}";
  assert.deepEqual([...referencedTokens(css)].sort(), ['--hm-gap', '--sf-ink']);
  assert.deepEqual([...referencedTokens(tsx)].sort(), ['--ch-marquee-s', '--hv-i']);
});

test('audit: undefined, dead and stray tokens are separated', () => {
  const r = audit({
    defined: new Set(['--sf-a', '--sf-dead']),
    referenced: new Set(['--sf-a', '--sf-missing']),
    localDefs: new Set(['--sf-a', '--hm-local']),
  });
  assert.deepEqual(r, { undefinedTokens: ['--sf-missing'], dead: ['--sf-dead'], stray: ['--hm-local'] });
});
