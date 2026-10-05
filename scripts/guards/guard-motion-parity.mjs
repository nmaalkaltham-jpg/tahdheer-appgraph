#!/usr/bin/env node
/**
 * حارس تطابق الحركة والتيبوغرافيا بين مصادرها.
 *
 * ثلاث مقارنات، كلّها ولدت من عطل حقيقي في هذا المستودع:
 *
 *  ١· MOTION_SCALE في motion.ts مقابل ‎[data-motion='N']‎ في motion.css.
 *     الأرقام الستّة والستّون كانت محبوسة في TypeScript ولا تصل CSS إطلاقًا،
 *     فستّة ضوابط حركة كانت تتحرّك بلا أثر. نسختان تعنيان انحرافًا لاحقًا ما
 *     لم يقارنهما أحد رقمًا برقم.
 *
 *  ٢· أدوار التيبوغرافيا في ThemeDocument مقابل رموز ‎--tp-*‎ في tokens.css.
 *     دور يُضاف على الخادم بلا رمز افتراضي يعني صفحةً تفقد حجمها كلّه إذا
 *     تعذّر تحميل ‎/theme.css‎.
 *
 *  ٣· أوزان الخطوط المطلوبة مقابل ما يوفّره الخطّ فعلًا. الوزن 800 كان
 *     مستعملًا 116 مرّة في المتجر وغير محمَّل، و«Tajawal» لا يملك 600 أصلًا.
 *     المتصفّح لا يشتكي — يصنع بديلًا اصطناعيًّا ويبدو كل شيء سليمًا.
 */

import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { makeResults, report } from './_lib.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const { results, pass, fail } = makeResults();

const read = (p) => readFileSync(join(ROOT, p), 'utf8');

// ───── ١ · مستويات الحركة ─────

const ts = read('packages/design-system/src/motion.ts');
const css = read('packages/design-system/src/motion.css');

/** @type {Map<string, {distance:number, duration:number, stagger:number, blur:number}>} */
const fromTs = new Map();
for (const m of ts.matchAll(
  /^\s*(\d+):\s*\{\s*distance:\s*(-?[\d.]+),\s*duration:\s*(-?[\d.]+),\s*easing:\s*'([^']+)',\s*stagger:\s*(-?[\d.]+),\s*blur:\s*(-?[\d.]+)/gm,
)) {
  fromTs.set(m[1], {
    distance: Number(m[2]),
    duration: Number(m[3]),
    stagger: Number(m[5]),
    blur: Number(m[6]),
  });
}

const fromCss = new Map();
for (const m of css.matchAll(/\[data-motion='(\d+)'\]\s*\{([^}]*)\}/g)) {
  const body = m[2];
  const num = (name) => {
    const hit = body.match(new RegExp(`--mo-${name}:\\s*(-?[\\d.]+)`));
    return hit ? Number(hit[1]) : null;
  };
  fromCss.set(m[1], {
    distance: num('distance'),
    duration: num('duration'),
    stagger: num('stagger'),
    blur: num('blur'),
  });
}

if (fromTs.size === 0 || fromCss.size === 0) {
  fail('motion', `تعذّر استخراج المستويات (ts=${fromTs.size}, css=${fromCss.size})`);
} else if (fromTs.size !== fromCss.size) {
  fail('motion', `عدد المستويات مختلف: motion.ts=${fromTs.size} · motion.css=${fromCss.size}`);
} else {
  let drift = 0;
  for (const [level, spec] of fromTs) {
    const other = fromCss.get(level);
    if (other === undefined) {
      fail('motion', `المستوى ${level} غائب من motion.css`);
      drift += 1;
      continue;
    }
    for (const key of ['distance', 'duration', 'stagger', 'blur']) {
      if (spec[key] !== other[key]) {
        fail('motion', `المستوى ${level} · ${key}: motion.ts=${spec[key]} ≠ motion.css=${other[key]}`);
        drift += 1;
      }
    }
  }
  if (drift === 0) pass('motion', `${fromTs.size} مستوى حركة متطابق بين motion.ts و motion.css`);
}

// ───── ٢ · أدوار التيبوغرافيا ─────

const doc = read('backend/laravel/app/Domain/Theme/ThemeDocument.php');
const tokens = read('packages/design-system/src/tokens.css');

const rolesBlock = doc.match(/public const ROLES = \[([\s\S]*?)\];/);
const roles = rolesBlock
  ? [...rolesBlock[1].matchAll(/'([a-zA-Z0-9]+)'/g)].map((m) => m[1])
  : [];

/** productTitle ⇒ product-title (نفس تحويل ThemeCss::kebab) */
const kebab = (s) => s.replace(/([a-z])([A-Z])/g, '$1-$2').toLowerCase();

if (roles.length === 0) {
  fail('typography', 'تعذّر استخراج الأدوار من ThemeDocument');
} else {
  const missing = roles.filter((role) => !tokens.includes(`--tp-${kebab(role)}-size:`));
  if (missing.length > 0) {
    fail('typography', `أدوار بلا رمز افتراضي في tokens.css: ${missing.join('، ')}`);
  } else {
    pass('typography', `${roles.length} دورًا لكلٍّ منها رمز ‎--tp-*-size‎ افتراضي`);
  }
}

// ───── ٣ · أوزان الخطوط ─────

const catalog = read('backend/laravel/app/Domain/Theme/FontCatalog.php');
const tajawal = catalog.match(/'tajawal' => \[[\s\S]*?'weights' => \[([^\]]+)\]/);
const available = tajawal ? [...tajawal[1].matchAll(/\d+/g)].map((m) => Number(m[0])) : [];

if (available.length === 0) {
  fail('weights', 'تعذّر استخراج أوزان Tajawal من FontCatalog');
} else {
  const layout = read('apps/storefront/app/layout.tsx');
  const loaded = [...(layout.match(/weight:\s*\[([^\]]+)\]/)?.[1] ?? '').matchAll(/\d+/g)].map((m) =>
    Number(m[0]),
  );

  const unavailable = loaded.filter((w) => !available.includes(w));
  // لا أوزان مستخرجة = الشكل تغيّر، لا «كلّها متاحة»: قائمةٌ فارغة كانت تنجح.
  if (loaded.length === 0) {
    fail('weights', 'تعذّر استخراج `weight: [...]` من apps/storefront/app/layout.tsx — تغيّر شكل تحميل الخطّ؟');
  } else if (unavailable.length > 0) {
    fail('weights', `المتجر يحمّل أوزانًا لا يوفّرها Tajawal: ${unavailable.join('، ')}`);
  } else {
    pass('weights', `الأوزان المحمَّلة (${loaded.join('، ')}) كلها متاحة في الخطّ`);
  }

  // لا وزن مكتوب في CSS خارج ما يوفّره الخطّ.
  const declared = new Set();
  for (const file of [
    'apps/admin/app/globals.css',
    'apps/storefront/app/globals.css',
    'packages/design-system/src/tokens.css',
  ]) {
    for (const m of read(file).matchAll(/font-weight:\s*(\d{3})/g)) declared.add(Number(m[1]));
  }
  const impossible = [...declared].filter((w) => !available.includes(w));
  if (impossible.length > 0) {
    fail('weights', `أوزان مُعلَنة لا وجود لها في الخطّ: ${impossible.join('، ')}`);
  } else {
    pass('weights', 'كل وزن مُعلَن في الأنماط العامّة موجود في الخطّ');
  }
}

report('guard-motion-parity', results);
