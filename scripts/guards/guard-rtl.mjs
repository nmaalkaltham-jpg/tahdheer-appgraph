#!/usr/bin/env node
/**
 * حارس RTL — خصائص منطقية فقط (MIP §2.4 قواعد هندسة RTL المُلزِمة).
 *
 * يرفض في CSS تحت apps/* و packages/*\/src:
 *   · margin-left/right و padding-left/right (استعمل *-inline-*).
 *   · left: / right: الفيزيائية (استعمل inset-inline-*).
 *   · ‹ أو ← داخل content: — الفاصل المعتمد › فقط (Bidi_Mirrored).
 *
 * الاستثناء: ملف يحمل العلامة  rtl-exempt  (لجزر LTR مقصودة).
 * التشغيل: node scripts/guards/guard-rtl.mjs · الخروج: 0 سليم · 1 فشل.
 */

import { expandRoots, walk, read, rel, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();

const ROOTS = expandRoots(['apps/*', 'packages/*/src']);
const files = ROOTS.flatMap((r) => walk(r, ['.css', '.scss']));

const PHYSICAL_SPACING = /(^|[{;\s])(margin|padding)-(left|right)\s*:/;
const PHYSICAL_INSET = /(^|[{;\s])(left|right)\s*:/;
const CONTENT_ARROW = /content\s*:[^;}]*[‹←]/;

const scan = (test) => {
  const hits = [];
  for (const f of files) {
    const src = read(f);
    if (src.includes('rtl-exempt')) continue;
    src.split('\n').forEach((line, n) => {
      if (line.trimStart().startsWith('/*') || line.trimStart().startsWith('*')) return;
      if (test.test(line)) hits.push(`${rel(f)}:${n + 1}`);
    });
  }
  return hits;
};

{
  const hits = scan(PHYSICAL_SPACING);
  if (hits.length) fail('هوامش منطقية', `margin/padding فيزيائي في: ${hits.slice(0, 5).join(' · ')}`);
  else pass('هوامش منطقية', 'لا margin-left/right ولا padding-left/right.');
}
{
  const hits = scan(PHYSICAL_INSET);
  if (hits.length) fail('إزاحة منطقية', `left:/right: فيزيائي في: ${hits.slice(0, 5).join(' · ')}`);
  else pass('إزاحة منطقية', 'لا left:/right: — inset-inline-* فقط.');
}
{
  const hits = scan(CONTENT_ARROW);
  if (hits.length) fail('فاصل الاتجاه', `‹ أو ← داخل content في: ${hits.slice(0, 5).join(' · ')}`);
  else pass('فاصل الاتجاه', 'الفاصل › فقط؛ لا أسهم فيزيائية في content.');
}

const suffix = files.length === 0 ? ' — لا ملفات CSS بعد، الحارس ساكن' : ` عبر ${files.length} ملف CSS`;
process.exit(report(`حارس RTL — خصائص منطقية${suffix}`, results));
