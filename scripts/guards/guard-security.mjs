#!/usr/bin/env node
/**
 * حارس الأمن — (MIP §11.1) ثلاثة أبواب دخلت منها الثغرات تاريخيًا:
 *
 *   ١) innerHTML / dangerouslySetInnerHTML بلا علامة sanitized صريحة
 *      على السطر نفسه أو السطر السابق.
 *   ٢) روابط http:// غير مشفّرة في المصادر (يُسمح بـ localhost وأمثاله
 *      وبمساحات أسماء XML/Schema المعيارية لأنها معرّفات لا طلبات).
 *   ٣) أسرار تبدو حقيقية: قيمة base64/hex طويلة مسندة لمتغير باسم
 *      KEY/SECRET/TOKEN/PASSWORD — الأسرار تعيش في البيئة لا في الكود.
 *
 * التشغيل: node scripts/guards/guard-security.mjs · الخروج: 0 سليم · 1 فشل.
 */

import { expandRoots, walk, read, rel, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();

const ROOTS = expandRoots([
  'apps/*/app',
  'apps/*/components',
  'apps/*/lib',
  'packages/*/src',
  'backend/laravel/app',
  'backend/laravel/resources',
]);
const files = ROOTS.flatMap((r) => walk(r, ['.ts', '.tsx', '.js', '.jsx', '.mjs', '.php', '.css', '.json', '.html']));

/* ─── فحص ١: حقن HTML بلا علامة تعقيم ─── */
{
  const RISKY = /dangerouslySetInnerHTML|\.innerHTML\s*=|innerHTML\s*:/;
  const hits = [];
  for (const f of files) {
    const lines = read(f).split('\n');
    lines.forEach((line, n) => {
      if (!RISKY.test(line)) return;
      const context = `${lines[n - 1] ?? ''}\n${line}`;
      if (!context.includes('sanitized')) hits.push(`${rel(f)}:${n + 1}`);
    });
  }
  if (hits.length) fail('حقن HTML', `بلا علامة /* sanitized */: ${hits.slice(0, 5).join(' · ')}`);
  else pass('حقن HTML', 'كل استخدامات innerHTML مُعلَّمة sanitized أو غائبة.');
}

/* ─── فحص ٢: روابط http:// غير مشفّرة ─── */
{
  const HTTP = /http:\/\/(?!localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\]|www\.w3\.org\/|schema\.org\/|ns\.adobe\.com\/)/;
  const hits = [];
  for (const f of files) {
    read(f).split('\n').forEach((line, n) => {
      const t = line.trimStart();
      if (t.startsWith('//') || t.startsWith('*') || t.startsWith('/*') || t.startsWith('#')) return;
      if (HTTP.test(line)) hits.push(`${rel(f)}:${n + 1}`);
    });
  }
  if (hits.length) fail('روابط مشفّرة', `http:// غير مسموح في: ${hits.slice(0, 5).join(' · ')}`);
  else pass('روابط مشفّرة', 'لا http:// خارج قائمة السماح (localhost ومساحات الأسماء).');
}

/* ─── فحص ٣: أسرار مضمّنة ─── */
{
  const ASSIGN = /([A-Za-z0-9_]*(?:key|secret|token|password|passwd)[A-Za-z0-9_]*)\s*(?:=>|[:=])\s*['"]([^'"]{24,})['"]/gi;
  const LOOKS_SECRET = /^[A-Za-z0-9+/=_-]{24,}$/; // base64/hex بلا فراغات
  const PLACEHOLDER = /example|placeholder|your[-_]|changeme|xxxx|<[^>]+>|\$\{|process\.env|env\(/i;
  const hits = [];
  for (const f of files) {
    const src = read(f);
    for (const m of src.matchAll(ASSIGN)) {
      const value = m[2];
      if (LOOKS_SECRET.test(value) && !PLACEHOLDER.test(m[0])) {
        hits.push(`${rel(f)} (${m[1]})`);
      }
    }
  }
  if (hits.length) fail('أسرار مضمّنة', `قيم تشبه أسرارًا حقيقية: ${hits.slice(0, 5).join(' · ')}`);
  else pass('أسرار مضمّنة', 'لا قيم طويلة مسندة لمتغيرات KEY/SECRET/TOKEN.');
}

const suffix = files.length === 0 ? ' — لا مصادر بعد، الحارس ساكن' : ` عبر ${files.length} ملفًا`;
process.exit(report(`حارس الأمن${suffix}`, results));
