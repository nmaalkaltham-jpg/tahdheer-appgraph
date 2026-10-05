#!/usr/bin/env node
/**
 * حارس الهوية — العلامة «التحاضير الحديثة» فقط.
 *
 * ١) الاسم القديم المحظور يجب ألا يظهر في أي مصدر إنتاجي (بوابة CI — MIP §11.3b).
 * ٢) «tahdheer.example» محظور خارج التعليقات (بقايا نطاق تجريبي).
 *
 * النطاق: apps/*\/app · apps/*\/components · packages/*\/src ·
 *         backend/laravel/app · backend/laravel/resources
 * الاستثناء: ملف مثبّتات اختبار يحمل علامة brand-fixture-exempt صراحةً.
 *
 * التشغيل: node scripts/guards/guard-brand.mjs · الخروج: 0 سليم · 1 فشل.
 */

import { expandRoots, walk, read, rel, lineOf, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();

// الاسم المحظور يُركَّب من نقاط الترميز حتى لا يعلّق هذا الملف نفسه أي فحص نصّي شامل.
const FORBIDDEN_BRAND = ['ت', 'ذ', 'ك', 'ي', 'ر'].join(''); // ت ذ ك ي ر
const FIXTURE_MARKER = 'brand-fixture-exempt';

const ROOTS = expandRoots([
  'apps/*/app',
  'apps/*/components',
  'packages/*/src',
  'backend/laravel/app',
  'backend/laravel/resources',
]);

const EXTS = ['.ts', '.tsx', '.js', '.jsx', '.mjs', '.cjs', '.css', '.scss', '.php', '.json', '.html', '.yml', '.yaml'];
const files = ROOTS.flatMap((r) => walk(r, EXTS));

/* ─── فحص ١: لا أثر للاسم القديم ─── */
{
  const hits = [];
  for (const f of files) {
    const src = read(f);
    if (src.includes(FIXTURE_MARKER)) continue;
    let i = src.indexOf(FORBIDDEN_BRAND);
    while (i !== -1) {
      hits.push(`${rel(f)}:${lineOf(src, i)}`);
      i = src.indexOf(FORBIDDEN_BRAND, i + 1);
    }
  }
  if (hits.length) fail('الاسم القديم', `«${FORBIDDEN_BRAND}» ظهر ${hits.length} مرة: ${hits.slice(0, 5).join(' · ')}`);
  else pass('الاسم القديم', `صفر ظهور في ${files.length} ملفًا مفحوصًا${files.length === 0 ? ' (لا مصادر بعد — الحارس ساكن)' : ''}.`);
}

/* ─── فحص ٢: لا نطاق tahdheer.example خارج التعليقات ─── */
{
  const NEEDLE = 'tahdheer.example';
  const hits = [];
  for (const f of files) {
    const src = read(f);
    if (src.includes(FIXTURE_MARKER) || !src.includes(NEEDLE)) continue;
    src.split('\n').forEach((line, n) => {
      const idx = line.indexOf(NEEDLE);
      if (idx === -1) return;
      const trimmed = line.trimStart();
      const isCommentLine = /^(\/\/|#|\*|\/\*|<!--)/.test(trimmed);
      // «//» تعليق فقط إذا لم يسبقه «:» (وإلا فهو جزء من https:// ونحوه).
      const slashMatch = /(^|[^:])\/\//.exec(line);
      const slash = slashMatch ? slashMatch.index + slashMatch[1].length : -1;
      const hashC = line.indexOf('#');
      const afterInline = (slash !== -1 && idx > slash) || (hashC !== -1 && idx > hashC && f.endsWith('.php'));
      if (!isCommentLine && !afterInline) hits.push(`${rel(f)}:${n + 1}`);
    });
  }
  if (hits.length) fail('نطاق تجريبي', `tahdheer.example خارج تعليق في: ${hits.slice(0, 5).join(' · ')}`);
  else pass('نطاق تجريبي', 'لا نطاق تجريبي في كود منفَّذ.');
}

process.exit(report('حارس الهوية — التحاضير الحديثة', results));
