#!/usr/bin/env node
/**
 * حارس متغيّرات العميل.
 *
 * ‎NEXT_PUBLIC_*‎ تُخبز حرفيًّا داخل حزم جافاسكربت وقت `next build`. تمريرها
 * في `environment:` من docker-compose لا يفعل شيئًا للمتصفّح: الحزمة تكون قد
 * بُنيت والقيمة فيها `undefined` — ولا تحذير من Next ولا خطأ في البناء.
 *
 * الثمن الحقيقي لهذا الصمت في هذا المستودع:
 *
 *   · سلة التسوق على الإنتاج كانت تعرض «تعذّر الاتصال بالخادم» دون أن تُرسل
 *     طلبًا واحدًا: ‎apiBase()‎ تُعيد null فيرتدّ العميل قبل ‎fetch‎. والخادم
 *     سليم تمامًا — ‎GET /api/v1/cart‎ يردّ 200 بسلة فارغة صالحة، وCORS
 *     صحيح بأصل صريح.
 *   · ‎sitemap.xml‎ كان يُقدَّم فارغًا (110 بايت، صفر رابط) لأن
 *     ‎NEXT_PUBLIC_SITE_URL‎ غائب فسقط ‎seo.ts‎ إلى ‎localhost:3000‎.
 *
 * القاعدة: كل ‎NEXT_PUBLIC_*‎ يقرؤه كود التطبيق يجب أن يكون له ‎ARG‎ في
 * Dockerfile الخاص به، و‎build.args‎ في docker-compose. وجوده في
 * ‎environment:‎ وحده ليس كافيًا ويُعدّ فشلًا.
 */

import { readFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expandRoots, walk, read, rel, makeResults, report } from './_lib.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const { results, pass, fail } = makeResults();

const APPS = [
  { name: 'storefront', dir: 'apps/storefront', dockerfile: 'apps/storefront/Dockerfile', service: 'storefront' },
  { name: 'admin', dir: 'apps/admin', dockerfile: 'apps/admin/Dockerfile', service: 'admin' },
];

const COMPOSE = 'infrastructure/docker/docker-compose.yml';
const compose = existsSync(join(ROOT, COMPOSE)) ? readFileSync(join(ROOT, COMPOSE), 'utf8') : '';

/** كتلة build.args لخدمة، أو '' إن لم توجد. */
function buildArgsBlock(service) {
  // من «  <service>:» حتى «    image:» — نطاق تعريف البناء.
  const start = compose.indexOf(`\n  ${service}:`);
  if (start === -1) return '';
  const imageAt = compose.indexOf('\n    image:', start);
  return imageAt === -1 ? '' : compose.slice(start, imageAt);
}

for (const app of APPS) {
  const roots = expandRoots([`${app.dir}/app`, `${app.dir}/components`, `${app.dir}/lib`]);

/*
 * متغيّرات تطوير محلّي بحتة: تُقرأ خلف حاجز `NODE_ENV !== "production"`،
 * فيحذفها bundler الإنتاج مع فرعها كاملًا ولا تصل حزمةً منشورة. تمريرها
 * في build.args سيكون الخطأ لا العكس — خصوصًا وأنّ فيها كلمة مرور.
 * القاعدة (لا القائمة) هي العقد: بادئة NEXT_PUBLIC_DEV_ محجوزة لهذا.
 */
const DEV_ONLY = /^NEXT_PUBLIC_DEV_/;

  const used = new Set();
  for (const root of roots) {
    for (const file of walk(root)) {
      if (!/\.(ts|tsx)$/.test(file)) continue;
      const source = read(file);
      for (const m of source.matchAll(/process\.env\.(NEXT_PUBLIC_[A-Z0-9_]+)/g)) {
        if (!DEV_ONLY.test(m[1])) used.add(m[1]);
      }
    }
  }

  if (used.size === 0) {
    pass(app.name, 'لا متغيّرات عميل مستعملة');
    continue;
  }

  const dockerfilePath = join(ROOT, app.dockerfile);
  if (!existsSync(dockerfilePath)) {
    fail(app.name, `Dockerfile مفقود: ${app.dockerfile}`);
    continue;
  }
  const dockerfile = readFileSync(dockerfilePath, 'utf8');
  const argsBlock = buildArgsBlock(app.service);

  const missingArg = [];
  const missingBuildArg = [];
  for (const name of [...used].sort()) {
    // ARG معلَن قبل خطوة البناء
    if (!new RegExp(`^ARG\\s+${name}\\b`, 'm').test(dockerfile)) missingArg.push(name);
    if (compose !== '' && !argsBlock.includes(`${name}:`)) missingBuildArg.push(name);
  }

  if (missingArg.length > 0) {
    fail(app.name, `بلا ARG في ${app.dockerfile}: ${missingArg.join('، ')} — لن تصل إلى المتصفّح`);
  } else {
    pass(app.name, `${used.size} متغيّر عميل، كلٌّ له ARG في Dockerfile`);
  }

  if (compose !== '') {
    if (missingBuildArg.length > 0) {
      fail(app.name, `بلا build.args في ${COMPOSE}: ${missingBuildArg.join('، ')}`);
    } else {
      pass(app.name, 'كل متغيّر عميل مُمرَّر في build.args');
    }
  }

  /*
   * ARG معلَن بعد `RUN npm run build` لا أثر له: الأمر نُفِّذ قبل أن تُعرَّف
   * القيمة. يبدو الإعداد صحيحًا في المراجعة ويفشل صامتًا في الإنتاج.
   */
  const buildAt = dockerfile.indexOf('RUN npm run build');
  if (buildAt !== -1) {
    const late = [...used].filter((name) => {
      const at = dockerfile.search(new RegExp(`^ARG\\s+${name}\\b`, 'm'));
      return at !== -1 && at > buildAt;
    });
    if (late.length > 0) {
      fail(app.name, `ARG بعد خطوة البناء (لا أثر له): ${late.join('، ')}`);
    } else {
      pass(app.name, 'كل ARG معلَن قبل خطوة البناء');
    }
  }
}

/*
 * أي قيمة تُمرَّر في build.args يجب أن تُمرَّر في environment أيضًا: مكوّنات
 * الخادم تقرأ process.env وقت الطلب. اختلافهما يعني متجرًا يطلب عنوانًا من
 * المتصفّح وعنوانًا آخر من الخادم — وهو عطل يظهر في نصف الصفحات فقط.
 */
if (compose !== '') {
  for (const app of APPS) {
    const args = buildArgsBlock(app.service);
    const names = [...args.matchAll(/^\s{8}(NEXT_PUBLIC_[A-Z0-9_]+):/gm)].map((m) => m[1]);
    if (names.length === 0) continue;

    const start = compose.indexOf(`\n  ${app.service}:`);
    const envAt = compose.indexOf('environment:', start);
    const nextService = compose.indexOf('\n  networks:', envAt);
    const envBlock = envAt === -1 ? '' : compose.slice(envAt, nextService === -1 ? undefined : nextService);

    const drift = names.filter((n) => !envBlock.includes(`${n}:`));
    if (drift.length > 0) {
      fail(app.name, `في build.args دون environment: ${drift.join('، ')} — الخادم والمتصفّح سيختلفان`);
    } else {
      pass(app.name, 'build.args و environment متطابقان');
    }
  }
}

/*
 * متغيّرات الخادم (غير NEXT_PUBLIC_*): ليست NEXT_PUBLIC_* فلا
 * تُخبز وقت البناء — تُقرأ وقت الطلب، فيكفيها `environment:` بلا ARG. لكن
 * غيابها من compose يعني جسر معاينة معطَّلًا على الإنتاج: المعاينة تعرض سببها
 * المكتوب والكانفس في اللوحة يبقى بلا READY. القاعدة: كل متغيّر منها يقرؤه
 * كود التطبيق يجب أن يوجد في كتلة environment لخدمته.
 */
// فارغةٌ منذ تقاعد استوديو الصفحات (40357d7): STUDIO_EDITOR_ORIGIN لم يعد يُقرأ.
/** @type {{ app: (typeof APPS)[number], name: string }[]} */
const SERVER_ENV = [];

/** كتلة الخدمة كاملة (من «  <service>:» حتى الخدمة التالية أو نهاية الملف). */
function serviceBlock(service) {
  const start = compose.indexOf(`\n  ${service}:`);
  if (start === -1) return '';
  const next = compose.slice(start + 1).search(/\n  [a-z][a-z0-9_-]*:\s*(\n|#|$)/);
  return next === -1 ? compose.slice(start) : compose.slice(start, start + 1 + next);
}

for (const { app, name } of SERVER_ENV) {
  const roots = expandRoots([`${app.dir}/app`, `${app.dir}/components`, `${app.dir}/lib`]);
  let isRead = false;
  for (const root of roots) {
    for (const file of walk(root)) {
      if (!/\.(ts|tsx)$/.test(file)) continue;
      if (read(file).includes(`process.env.${name}`)) {
        isRead = true;
        break;
      }
    }
    if (isRead) break;
  }
  // middleware.ts يعيش خارج app/ — يُفحص مباشرة.
  const middleware = join(ROOT, app.dir, 'middleware.ts');
  if (!isRead && existsSync(middleware) && readFileSync(middleware, 'utf8').includes(`process.env.${name}`)) {
    isRead = true;
  }

  if (!isRead) {
    fail(app.name, `${name} غير مقروء في الكود — أزل القاعدة أو تحقّق من المسارات`);
    continue;
  }
  if (compose === '') continue;
  if (serviceBlock(app.service).includes(`${name}:`)) {
    pass(app.name, `${name} مقروء وقت الطلب وموجود في environment للخدمة`);
  } else {
    fail(app.name, `${name} مقروء في الكود وغائب من environment في ${COMPOSE} — الجسر سيتعطّل على الإنتاج`);
  }
}

process.exit(report('guard-public-env', results));
