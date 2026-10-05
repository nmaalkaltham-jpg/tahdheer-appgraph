#!/usr/bin/env node
/**
 * مشغّل الحرّاس — يشغّل كل حارس على حدة ويجمع النتائج.
 *
 * التشغيل: node scripts/guards/run-all.mjs   (أو npm run guards)
 * الخروج:  0 = كل الحرّاس سليمة · 1 = فشل حارس واحد على الأقل.
 */

import { spawnSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));

const GUARDS = [
  'guard-brand',
  'guard-honesty',
  'guard-rtl',
  'guard-reduced-motion',
  'guard-registry',
  'guard-security',
  'guard-motion-parity',
  'guard-public-env',
  'guard-layout-parity',
  'guard-infra-refs',
  'guard-domain-boundaries',
  'guard-openapi',
  'guard-api-paths',
  'guard-capability-parity',
  'guard-query-traps',
  'guard-runtime-imports',
  'guard-financial-invariants',
  'guard-secrets',
  'guard-design-fonts',
  'guard-css-hygiene',
  'guard-design-tokens',
  'guard-radius-scale',
  'guard-raw-colour',
];

const outcomes = GUARDS.map((name) => {
  const r = spawnSync(process.execPath, [join(HERE, `${name}.mjs`)], {
    stdio: 'inherit',
    env: process.env,
  });
  return { name, ok: r.status === 0 };
});

const failed = outcomes.filter((o) => !o.ok);
const width = Math.max(...GUARDS.map((g) => g.length)) + 2;

console.log('\n  ملخّص الحرّاس — التحاضير الحديثة');
console.log('  ' + '═'.repeat(48));
for (const o of outcomes) {
  console.log(`  ${o.ok ? '✓' : '×'} ${o.name.padEnd(width)} ${o.ok ? 'سليم' : 'فشل'}`);
}
console.log('  ' + '═'.repeat(48));
console.log(`  ${outcomes.length - failed.length}/${outcomes.length} حارسًا سليمًا\n`);

process.exit(failed.length ? 1 : 0);
