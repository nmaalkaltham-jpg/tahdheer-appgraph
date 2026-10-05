#!/usr/bin/env node
/**
 * إثباتُ حتميّة المسح البصريّ: يقارن مجلّدَي لقطاتٍ من تشغيلين
 * (`--save-actual=<dir>`) لقطةً لقطة بالعتبة الصفريّة — أيّ بكسلٍ مختلف يُبلَّغ.
 *
 *   npm run test:visual -- --save-actual=test-results/visual/runA
 *   npm run test:visual -- --save-actual=test-results/visual/runB
 *   node scripts/visual-determinism.mjs test-results/visual/runA test-results/visual/runB [--color-threshold=0]
 *
 * العتبة اللونيّة الافتراضيّة ‎0‎ (أيّ فرقٍ في أيّ قناة). ‎--color-threshold=0.1‎
 * يطابق عتبة البوّابة نفسها (`DEFAULTS.colorThreshold`) — للتمييز بين ضجيج
 * التكميم تحت العتبة وبين فرقٍ تراه البوّابة.
 * الخروج: ‎0‎ = كلّ اللقطات المشتركة متطابقة (diffPct ‎0‎) ولا لقطة ناقصة في أحدهما.
 */
import { readdirSync, readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { diffPng } from './lib/visual-diff.mjs';

const [a, b] = process.argv.slice(2).filter((x) => !x.startsWith('--'));
const CT = Number(process.argv.find((x) => x.startsWith('--color-threshold='))?.split('=')[1] ?? 0);
if (!a || !b || !existsSync(a) || !existsSync(b)) {
  console.error('usage: visual-determinism.mjs <runA> <runB>');
  process.exit(2);
}
const list = (d) => new Set(readdirSync(d).filter((f) => f.endsWith('.png')));
const A = list(a);
const B = list(b);
let bad = 0;
for (const f of [...new Set([...A, ...B])].sort()) {
  if (!A.has(f) || !B.has(f)) { bad++; console.log(`  × ${f.padEnd(46)} ناقصة في ${A.has(f) ? 'B' : 'A'}`); continue; }
  const r = await diffPng(readFileSync(join(a, f)), readFileSync(join(b, f)), { colorThreshold: CT, maxDiffPct: 0, bandPct: 0 });
  if (!r.ok) bad++;
  console.log(`  ${r.ok ? '✓' : '×'} ${f.padEnd(46)} diff ${r.diffPct}% · شريط ${r.worstBand.pct}%`);
}
console.log(`\n  ${A.size + B.size ? [...A].filter((f) => B.has(f)).length : 0} لقطة مشتركة · ${bad} غير متطابقة\n`);
process.exit(bad ? 1 : 0);
