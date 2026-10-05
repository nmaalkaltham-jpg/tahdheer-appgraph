#!/usr/bin/env node
/**
 * حارس الألوان الخامّة — سقّاطةٌ بالملفّ (docs/architecture/DESIGN-SYSTEM-2026-09-25.md §9).
 *
 * يعدّ حرفيّات اللون ‎#hex · rgb()/rgba() · hsl()/hsla()‎ في كلّ ملفّ ‎.css/.ts/.tsx‎
 * تحت ‎apps/storefront · apps/admin · packages/ui/src · packages/design-system/src‎
 * — عدا ‎tokens.css‎ (المصدر الوحيد للقيم)، والاختبارات، والتعليقات، وما داخل ‎url()‎.
 *
 * يكمّل ‎guard-css-hygiene‎ (صفرٌ مطلق في وحدات المتجر وui) بأن يمدّ القياس إلى
 * اللوحة وملفّات TS/TSX حيث الدَّين قائم: ‎raw-colour-allowlist.json‎ يحمل عدد اليوم
 * لكلّ ملفّ؛ يفشل الحارس إن زاد ملفٌّ أو ظهر ملفٌّ جديد بلون خامّ، ويطبع ما نقص
 * «قابلًا للتضييق» ليُنزِل المهاجرُ العدد (أو يحذف المدخل عند الصفر). لا يُرفع عددٌ أبدًا.
 *
 *   node scripts/guards/guard-raw-colour.mjs [--list]
 */

import { join } from 'node:path';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { ROOT, walk, read, rel, lineOf, makeResults, report } from './_lib.mjs';
import { ratchet } from './guard-radius-scale.mjs';

const RAW = /#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?)\(/g;
const ROOTS = ['apps/storefront', 'apps/admin', 'packages/ui/src', 'packages/design-system/src'];
const SKIP = /(^|\/)(__tests__|__fixtures__|e2e)\/|\.test\.|\.d\.ts$|packages\/design-system\/src\/tokens\.css$/;

/** يُفرّغ التعليقات و‎url()‎ بمسافاتٍ تحفظ الأسطر. */
const blank = (m) => m.replace(/[^\n]/g, ' ');
export const stripNonCode = (src) =>
  src.replace(/\/\*[\s\S]*?\*\//g, blank).replace(/^\s*\/\/.*$/gm, blank).replace(/url\([^)]*\)/g, blank);

/** مواضع الألوان الخامّة في نصّ: ‎[{ line, text }]‎. */
export function findRawColourLiterals(src) {
  const s = stripNonCode(src);
  return [...s.matchAll(RAW)].map((m) => ({ line: lineOf(s, m.index), text: m[0] }));
}

function main() {
  const { results, pass, fail } = makeResults();
  const files = ROOTS.flatMap((r) => walk(join(ROOT, r), ['.css', '.ts', '.tsx'])).filter((f) => !SKIP.test(rel(f)));
  const counts = {};
  const detail = {};
  for (const f of files) {
    const hits = findRawColourLiterals(read(f));
    if (hits.length) {
      counts[rel(f)] = hits.length;
      detail[rel(f)] = hits;
    }
  }
  const allowFile = join(ROOT, 'scripts', 'guards', 'raw-colour-allowlist.json');
  const allow = existsSync(allowFile) ? JSON.parse(read(allowFile)).files ?? {} : {};
  const { worse, tighter } = ratchet(counts, allow);
  const total = Object.values(counts).reduce((a, b) => a + b, 0);
  const cap = Object.values(allow).reduce((a, b) => a + b, 0);

  if (process.argv.includes('--list')) {
    for (const [f, list] of Object.entries(detail)) for (const h of list) console.log(`  ${f}:${h.line}  ${h.text}`);
  }

  if (worse.length) {
    fail('سقّاطة الألوان', `${worse.length} ملفًّا زاد أو جديد: ${worse.slice(0, 5).map((w) => `${w.file} (${w.count} > ${w.cap})`).join(' · ')} — اقرأ رمزًا من tokens.css`);
  } else {
    pass('سقّاطة الألوان', `لا ملفّ زاد عن حدّه — ${Object.keys(counts).length} ملفًّا ما زال يحمل ألوانًا خامّة.`);
  }
  pass('المتبقّي', `${total} لونًا خامًّا خارج tokens.css (الحدّ ${cap}) — الهدف صفر.`);
  if (tighter.length) {
    pass('قابل للتضييق', `${tighter.length}: ${tighter.slice(0, 5).map((t) => `${t.file} ${t.cap}→${t.count}`).join(' · ')} — أنزِل raw-colour-allowlist.json.`);
  }
  return report(`حارس الألوان الخامّة — ${files.length} ملفًّا · ${total} لونًا`, results);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) process.exit(main());
