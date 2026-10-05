#!/usr/bin/env node
/**
 * حارس رموز التصميم — كلّ ‎--sf-/--hm-/--pdp-/--ch-/--ct-/--hv-‎ مستعمَلٍ
 * معرَّفٌ في `packages/design-system/src/tokens.css` (أو `motion.css`)،
 * وكلّ معرَّفٍ مستعمَل (الميّت يُسرد).
 *
 * الاستعمال يُقرأ من CSS/TS/TSX تحت apps/* و packages/*\/src. تعريفٌ بهذه
 * البادئات في ملفٍّ آخر يُبلَّغ «شاردًا»: مكان الرمز الوحيد ملفّ الرموز.
 *
 *   node scripts/guards/guard-design-tokens.mjs
 */

import { join } from 'node:path';
import { existsSync } from 'node:fs';
import { ROOT, expandRoots, walk, read, rel, makeResults, report } from './_lib.mjs';
import { definedTokens, referencedTokens, audit, PREFIXES } from './design-tokens-rules.mjs';

const { results, pass, fail } = makeResults();

const TOKEN_FILES = ['tokens.css', 'motion.css'].map((f) => join(ROOT, 'packages', 'design-system', 'src', f)).filter(existsSync);
const defined = new Set();
for (const f of TOKEN_FILES) for (const t of definedTokens(read(f))) defined.add(t);

const files = expandRoots(['apps/*', 'packages/*/src']).flatMap((r) => walk(r, ['.css', '.ts', '.tsx']));
const referenced = new Set();
const localDefs = new Map();
for (const f of files) {
  const src = read(f);
  for (const t of referencedTokens(src)) referenced.add(t);
  if (!TOKEN_FILES.includes(f) && f.endsWith('.css')) for (const t of definedTokens(src)) localDefs.set(t, rel(f));
}
/* رمزٌ يُعرَّف بدلالة رمزٍ آخر داخل ملفّ الرموز — ذاك استعمال أيضًا. */
for (const f of TOKEN_FILES) for (const t of referencedTokens(read(f))) referenced.add(t);

const { undefinedTokens, dead, stray } = audit({ defined, referenced, localDefs: new Set(localDefs.keys()) });
const show = (list, n = 8) => list.slice(0, n).join(' · ') + (list.length > n ? ` … (+${list.length - n})` : '');

if (undefinedTokens.length) fail('رموز غير معرَّفة', `${undefinedTokens.length}: ${show(undefinedTokens)}`);
else pass('رموز غير معرَّفة', `كلّ ${referenced.size} رمزًا مستعمَلًا معرَّف في tokens.css.`);

if (dead.length) fail('رموز ميّتة', `${dead.length} معرَّفٌ بلا استعمال: ${show(dead)}`);
else pass('رموز ميّتة', `كلّ ${defined.size} رمزًا معرَّفًا مستعمَل.`);

if (stray.length) fail('تعريف خارج ملفّ الرموز', `${stray.length}: ${show(stray.map((t) => `${t} (${localDefs.get(t)})`), 5)}`);
else pass('تعريف خارج ملفّ الرموز', 'لا تعريفات شاردة.');

process.exit(report(`حارس رموز التصميم — بادئات ${PREFIXES.map((p) => `--${p}-`).join(' ')} · ${defined.size} معرَّف · ${referenced.size} مستعمَل`, results));
