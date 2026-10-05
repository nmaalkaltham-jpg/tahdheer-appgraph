/**
 * ONE money contract for every customer-facing surface
 * (packages/design-system/src/format.ts): formatMoney(minor) →
 * { amount: "1,250.00", currency: "SAR" }. This suite pins the output and
 * fails when any storefront / UI source formats money on its own.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

import { CURRENCY, formatMoney } from '../../../../packages/design-system/src/format.ts';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../../../..');

test('formatMoney: two decimals, en-US grouping, Western digits, ISO code', () => {
  assert.deepEqual(formatMoney(4500), { amount: '45.00', currency: 'SAR' });
  assert.deepEqual(formatMoney(0), { amount: '0.00', currency: 'SAR' });
  assert.deepEqual(formatMoney(5), { amount: '0.05', currency: 'SAR' });
  assert.deepEqual(formatMoney(125050), { amount: '1,250.50', currency: 'SAR' });
  assert.deepEqual(formatMoney(100000000), { amount: '1,000,000.00', currency: 'SAR' });
  assert.equal(CURRENCY, 'SAR');
  // RLM/ALM-free: safe inside <bdi dir="ltr">.
  assert.doesNotMatch(formatMoney(987654321).amount + CURRENCY, /[‎‏؜]/);
});

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    if (name === 'node_modules' || name === '.next' || name === '__tests__') continue;
    const full = join(dir, name);
    if (statSync(full).isDirectory()) walk(full, out);
    else if (/\.(tsx?|mjs)$/.test(name)) out.push(full);
  }
  return out;
}

/** Source with comments removed (block, line and JSX comments). */
function code(text) {
  return text
    .replace(/\{\/\*[\s\S]*?\*\/\}/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|[^:'"`])\/\/.*$/gm, '$1');
}

const SOURCES = [
  'apps/storefront/app',
  'apps/storefront/components',
  'apps/storefront/lib',
  'packages/ui/src',
].flatMap((dir) => walk(join(ROOT, dir)));

const FORBIDDEN = [
  [/ر\.س|﷼/, 'a literal Arabic currency label'],
  [/['"`>]\s*SAR\s*['"`<]/, 'a literal «SAR» label'],
  [/\.toFixed\(\s*2\s*\)/, 'toFixed(2) money formatting'],
  [/Intl\.NumberFormat/, 'a private Intl.NumberFormat'],
  [/\/\s*100\b/, 'minor→major arithmetic outside formatMoney'],
];

test('no storefront or UI source formats money itself', () => {
  assert.ok(SOURCES.length > 100, `scanned ${SOURCES.length} files — the walk found the tree`);
  const offenders = [];
  for (const file of SOURCES) {
    const text = code(readFileSync(file, 'utf8'));
    for (const [pattern, what] of FORBIDDEN) {
      if (pattern.test(text)) offenders.push(`${relative(ROOT, file)}: ${what}`);
    }
  }
  assert.deepEqual(offenders, [], offenders.join('\n'));
});
