#!/usr/bin/env node
/**
 * حارس الأمانة — عقيدة الصدق (MIP §1.3): لا بيانات مُختلَقة في أي واجهة.
 *
 * يرفض في مصادر التطبيقات والحزم:
 *   · تقييمًا رقميًا حرفيًا (مثل 4.9) ملاصقًا لكلمة rating.
 *   · عدّاد مراجعات حرفيًا غير صفري.
 *   · نص «عدد الزوار» الحرفي (اختلاق إحصاءات زوار).
 *   · Math.random لقيم معروضة.
 *   · عدّ تنازلي مزيف مبني على Date.now() + إزاحة.
 *
 * الاستثناءات المشروعة تُسجَّل في honesty-allowlist.json بجانب هذا الملف:
 *   { "مسار/نسبي.tsx": ["math-random"] }  أو  ["*"] لكل القواعد.
 *
 * التشغيل: node scripts/guards/guard-honesty.mjs · الخروج: 0 سليم · 1 فشل.
 */

import { readFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expandRoots, walk, read, rel, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();
const HERE = dirname(fileURLToPath(import.meta.url));

const allowlistPath = join(HERE, 'honesty-allowlist.json');
const allowlist = existsSync(allowlistPath) ? JSON.parse(readFileSync(allowlistPath, 'utf8')) : {};
const allowed = (file, rule) => {
  const rules = allowlist[rel(file)];
  return Array.isArray(rules) && (rules.includes('*') || rules.includes(rule));
};

const ROOTS = expandRoots(['apps/*/app', 'apps/*/components', 'packages/*/src']);
const files = ROOTS.flatMap((r) => walk(r, ['.ts', '.tsx', '.js', '.jsx', '.mjs']));

/** القواعد: [مُعرّف، اسم عربي، دالة فحص للسطر الواحد]. */
const RULES = [
  ['rating-literal', 'تقييم حرفي',
    (line) => /rating/i.test(line) && /(?<![\d.])[0-5]\.\d(?![\d])/.test(line)],
  ['review-count', 'عدّاد مراجعات حرفي',
    (line) => /review[a-z_]*count[a-z_]*\s*[:=]\s*['"]?[1-9]/i.test(line)
      || /[1-9]\d*\s*(مراجعة|مراجعات)/.test(line)],
  ['visitor-count', 'عدد الزوار',
    (line) => line.includes('عدد الزوار')],
  ['math-random', 'Math.random للعرض',
    (line) => line.includes('Math.random')],
  ['fake-countdown', 'عدّ تنازلي مزيف',
    (line) => /Date\.now\(\)\s*\+/.test(line)],
];

for (const [id, name, test] of RULES) {
  const hits = [];
  for (const f of files) {
    if (allowed(f, id)) continue;
    read(f).split('\n').forEach((line, n) => {
      const t = line.trim();
      if (t.startsWith('//') || t.startsWith('*') || t.startsWith('/*')) return;
      if (test(line)) hits.push(`${rel(f)}:${n + 1}`);
    });
  }
  if (hits.length) fail(name, `${hits.length} موضعًا: ${hits.slice(0, 5).join(' · ')}`);
  else pass(name, 'لا مواضع مخالفة.');
}

const suffix = files.length === 0 ? ' — لا مصادر بعد، الحارس ساكن' : ` عبر ${files.length} ملفًا`;
process.exit(report(`حارس الأمانة — لا بيانات مُختلَقة${suffix}`, results));
