#!/usr/bin/env node
/**
 * مشغّل E2E — يشغّل كلّ `apps/storefront/e2e/*.e2e.mjs` تباعًا على متجرٍ
 * وواجهةٍ يعملان، ويمرّر الوسائط كما هي، ويطبع ملخّصًا، ويخرج بغير صفرٍ
 * عند أيّ فشل.
 *
 * الملفّات تُكتشف بالامتداد لا بقائمة: ملفٌّ جديد (commerce-flow.e2e.mjs مثلًا)
 * يدخل الجولة بلا تسجيل.
 *
 *   npm run e2e
 *   npm run e2e -- --base=http://127.0.0.1:3000 --api=http://127.0.0.1:58080
 *   npm run e2e -- --only=pdp            # تصفية بالاسم (بادئة)
 */

import { spawnSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const DIR = join(ROOT, 'apps', 'storefront', 'e2e');
const args = process.argv.slice(2);
const has = (name) => args.some((a) => a.startsWith(`--${name}=`));
const only = args.find((a) => a.startsWith('--only='))?.slice(7) ?? '';
const passthrough = args.filter((a) => !a.startsWith('--only='));
if (!has('base')) passthrough.push('--base=http://127.0.0.1:3000');
if (!has('api')) passthrough.push('--api=http://127.0.0.1:58080');

const files = readdirSync(DIR).filter((f) => f.endsWith('.e2e.mjs') && (!only || f.startsWith(only))).sort();
if (!files.length) {
  console.error('  لا ملفّات *.e2e.mjs تحت apps/storefront/e2e');
  process.exit(1);
}

const outcomes = files.map((f) => {
  console.log(`\n▶ ${f} ${passthrough.join(' ')}`);
  const started = Date.now();
  const r = spawnSync(process.execPath, [join(DIR, f), ...passthrough], { stdio: 'inherit', env: process.env });
  return { file: f, code: r.status ?? 1, secs: ((Date.now() - started) / 1000).toFixed(1) };
});

const failed = outcomes.filter((o) => o.code !== 0);
const width = Math.max(...files.map((f) => f.length)) + 2;
console.log('\n  ملخّص E2E');
console.log('  ' + '═'.repeat(48));
for (const o of outcomes) console.log(`  ${o.code === 0 ? '✓' : '×'} ${o.file.padEnd(width)} ${o.code === 0 ? 'سليم' : `فشل (${o.code})`} · ${o.secs}s`);
console.log('  ' + '═'.repeat(48));
console.log(`  ${outcomes.length - failed.length}/${outcomes.length} ملفًّا سليمًا\n`);
process.exit(failed.length ? 1 : 0);
