/**
 * حارس الاستيراد وقت التشغيل.
 *
 * ─── ١ — كلّ استيرادٍ يُحلّ ───
 *
 * حزمةٌ تُستورد ولا تُعلَن في `package.json` تعمل محلّيًّا — لأنّها موجودة
 * في `node_modules` تبعًا لحزمةٍ أخرى — وتنهار في صورةٍ نظيفة. والانهيار
 * يقع وقت **البناء في الإنتاج**، أي في أسوأ لحظةٍ ممكنة.
 *
 * والمسار النسبيّ الذي لا يقابله ملفّ ينهار كذلك، وTypeScript يلتقطه — إلّا
 * حين يكون داخل `// @ts-ignore` أو في ملفٍّ خارج برنامج الفحص.
 *
 * ─── ٢ — لا نوعٌ محلّيّ يُكرّر عقد الحزمة المولَّدة ───
 *
 * `packages/api-sdk` يولّد أنواع أجسام الطلبات من قواعد الخادم. وواجهةٌ
 * محلّيّة بالاسم نفسه تعني نسختين تتباعدان: تُضاف حقلٌ في الخادم فيُولَّد في
 * الحزمة ولا يصل إلى النسخة، ويبقى المحرّر يؤكّد شكلًا قديمًا.
 */

import { existsSync, readFileSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { ROOT, expandRoots, walk, read, rel, lineOf, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();

const WORKSPACES = ['apps/admin', 'apps/storefront', 'packages/api-sdk', 'packages/design-system', 'packages/registry', 'packages/ui'];

/** أسماءٌ يوفّرها المشغّل نفسه — لا تُعلَن في أيّ `package.json`. */
const BUILTIN = /^(node:|react$|react-dom|react\/|next$|next\/)/;

const declared = new Set();

for (const workspace of [...WORKSPACES, '.']) {
  const manifest = join(ROOT, workspace, 'package.json');

  if (! existsSync(manifest)) continue;

  const pkg = JSON.parse(readFileSync(manifest, 'utf8'));

  for (const field of ['dependencies', 'devDependencies', 'peerDependencies']) {
    for (const name of Object.keys(pkg[field] ?? {})) declared.add(name);
  }
}

const roots = expandRoots(['apps/admin', 'apps/storefront', 'packages']);
const files = roots.flatMap((dir) => walk(dir, ['.ts', '.tsx', '.mjs']));

const IMPORT = /(?:^|\n)\s*(?:import|export)\s[^;]*?from\s+'([^']+)'/g;

const unresolved = [];
let checked = 0;

for (const file of files) {
  const src = read(file);

  for (const match of src.matchAll(IMPORT)) {
    const specifier = match[1];
    checked += 1;

    if (BUILTIN.test(specifier)) continue;

    if (specifier.startsWith('.')) {
      const base = resolve(dirname(file), specifier);
      const found = ['', '.ts', '.tsx', '.mjs', '.js', '.css', '/index.ts', '/index.tsx']
        .some((suffix) => existsSync(base + suffix));

      if (! found) unresolved.push(`${rel(file)}:${lineOf(src, match.index)} → ${specifier}`);

      continue;
    }

    // `@scope/name/sub` ⇦ `@scope/name`، و`name/sub` ⇦ `name`.
    const parts = specifier.split('/');
    const name = specifier.startsWith('@') ? parts.slice(0, 2).join('/') : parts[0];

    if (! declared.has(name)) {
      unresolved.push(`${rel(file)}:${lineOf(src, match.index)} → ${name} (غير معلَنة)`);
    }
  }
}

if (unresolved.length > 0) {
  fail('imports', `استيرادٌ لا يُحلّ: ${unresolved.join(' · ')}`);
} else {
  pass('imports', `${checked} استيرادًا · كلّها تُحلّ إلى ملفٍّ أو تبعيّةٍ معلَنة`);
}

// ── ٢ — لا نوع محلّيّ يُكرّر نوعًا مولَّدًا ──────────────────────────────
const GENERATED = join(ROOT, 'packages/api-sdk/src/generated.ts');

if (! existsSync(GENERATED)) {
  fail('dto-duplication', 'الحزمة المولَّدة غير موجودة — شغّل: node scripts/generate-sdk.mjs');
} else {
  const generatedNames = new Set(
    [...read(GENERATED).matchAll(/export interface (\w+)/g)].map((m) => m[1]),
  );

  const duplicates = [];
  const appFiles = expandRoots(['apps/admin', 'apps/storefront']).flatMap((dir) => walk(dir, ['.ts', '.tsx']));

  for (const file of appFiles) {
    const src = read(file);

    for (const match of src.matchAll(/(?:export )?interface (\w+)/g)) {
      if (generatedNames.has(match[1])) {
        duplicates.push(`${rel(file)}:${lineOf(src, match.index)} → ${match[1]}`);
      }
    }
  }

  if (duplicates.length > 0) {
    fail('dto-duplication', `نوعٌ محلّيّ يكرّر عقد الحزمة المولَّدة: ${duplicates.join(' · ')}`);
  } else {
    pass('dto-duplication', `${generatedNames.size} نوعًا مولَّدًا · لا نسخةَ محلّيّة منها`);
  }
}

process.exit(report('حارس الاستيراد وقت التشغيل', results));
