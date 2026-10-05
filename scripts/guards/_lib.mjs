/**
 * أدوات مشتركة لحرّاس المستودع — صفر تبعيات خارجية.
 * كل حارس مستقلّ التشغيل: node scripts/guards/guard-*.mjs
 * الأعداد تُشتقّ من المصفوفات دائمًا ولا تُكتب يدويًا.
 */

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, relative, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

/** جذر المستودع (scripts/guards/_lib.mjs ← مستويان للأعلى). */
export const ROOT = dirname(dirname(dirname(fileURLToPath(import.meta.url))));

const SKIP_DIRS = new Set([
  'node_modules', 'vendor', '.next', '.git', 'dist', 'build',
  'storage', 'coverage', '.turbo', 'docs',
]);

/** توسيع أنماط مثل «apps/&#x2a;/app» إلى مسارات فعلية موجودة. */
export function expandRoots(patterns) {
  const out = [];
  for (const pattern of patterns) {
    let acc = [ROOT];
    for (const part of pattern.split('/')) {
      if (part === '*') {
        acc = acc.flatMap((d) =>
          existsSync(d)
            ? readdirSync(d)
                .filter((e) => !e.startsWith('.') && !SKIP_DIRS.has(e))
                .map((e) => join(d, e))
                .filter((f) => statSync(f).isDirectory())
            : []
        );
      } else {
        acc = acc.map((d) => join(d, part));
      }
    }
    out.push(...acc.filter((d) => existsSync(d) && statSync(d).isDirectory()));
  }
  return [...new Set(out)];
}

/** كل الملفات تحت مجلد بامتدادات محددة (اختياري)، مع تخطي مجلدات الاعتماديات. */
export function walk(dir, exts = null, out = []) {
  if (!existsSync(dir)) return out;
  for (const entry of readdirSync(dir)) {
    if (entry.startsWith('.') || SKIP_DIRS.has(entry)) continue;
    const full = join(dir, entry);
    const st = statSync(full);
    if (st.isDirectory()) walk(full, exts, out);
    else if (!exts || exts.some((x) => entry.endsWith(x))) out.push(full);
  }
  return out;
}

export const read = (f) => readFileSync(f, 'utf8');
export const rel = (f) => relative(ROOT, f);

/** رقم السطر لموضع داخل النص (١-مبني). */
export const lineOf = (src, index) => src.slice(0, index).split('\n').length;

/** مجمّع نتائج: pass/fail يدفعان إلى مصفوفة واحدة يُشتقّ منها كل عدّ. */
export function makeResults() {
  const results = [];
  return {
    results,
    pass: (name, note) => results.push({ ok: true, name, note }),
    fail: (name, note) => results.push({ ok: false, name, note }),
  };
}

/** تقرير موحّد ✓/× ويعيد رمز الخروج (0 سليم · 1 فشل). */
export function report(title, results) {
  const failed = results.filter((r) => !r.ok);
  const width = Math.max(0, ...results.map((r) => [...r.name].length)) + 2;
  console.log(`\n  ${title}\n  ` + '─'.repeat(56));
  for (const r of results) {
    console.log(`  ${r.ok ? '✓' : '×'} ${r.name.padEnd(width)} ${r.note}`);
  }
  console.log('  ' + '─'.repeat(56));
  console.log(`  ${results.length - failed.length}/${results.length} فحصًا سليمًا\n`);
  return failed.length ? 1 : 0;
}
