#!/usr/bin/env node
/**
 * حارس تطابق تخطيط صفحة المنتج بين الخادم والواجهة.
 *
 * ودجة في قائمة السماح بلا مُصيِّر في الواجهة زرٌّ لا يفعل شيئًا: يبدّله
 * المشرف ويحفظ ويرى «حُفظ» ولا يتغيّر في الصفحة شيء. وقعت هذه بالضبط —
 * `description` و`related` كانتا في الكتالوج ولا تُصيَّران في أي مكان.
 *
 * والعكس أخطر: مُصيِّر يقرأ ودجة لا يعرفها الخادم يختفي عند أوّل نشر، لأن
 * التطبيع يُسقط المجهول قبل الحفظ.
 *
 * ثلاث مقارنات:
 *  ١· أسماء الودجات: PHP ⇄ TypeScript.
 *  ٢· المناطق: كل ودجة في منطقة تعرفها الواجهة، ومجموع المناطق يغطّي الكل.
 *  ٣· الإلزامي: REQUIRED في PHP ⇄ REQUIRED_WIDGETS في الواجهة.
 */

import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { makeResults, report } from './_lib.mjs';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const { results, pass, fail } = makeResults();

const read = (p) => readFileSync(join(ROOT, p), 'utf8');

const php = read('backend/laravel/app/Domain/Catalog/ProductLayout.php');
const page = read('apps/storefront/app/products/[slug]/page.tsx');
const homePhp = read('backend/laravel/app/Domain/Content/HomeContent.php');
const homeTs = read('apps/storefront/lib/homepage.ts');

/** ودجة ⇒ منطقتها، من قائمة السماح في الخادم. */
const serverWidgets = new Map();
for (const m of php.matchAll(/'([a-z]+)'\s*=>\s*\[\s*'label'\s*=>\s*'[^']*',\s*'zone'\s*=>\s*'([a-z]+)'/g)) {
  serverWidgets.set(m[1], m[2]);
}

const serverRequired = (php.match(/public const REQUIRED = \[([^\]]*)\]/)?.[1] ?? '')
  .split(',')
  .map((s) => s.trim().replace(/^'|'$/g, ''))
  .filter(Boolean);

const list = (name) =>
  (page.match(new RegExp(`const ${name} = \\[([\\s\\S]*?)\\] as const`))?.[1] ?? '')
    .split(',')
    .map((s) => s.trim().replace(/^'|'$/g, ''))
    .filter(Boolean);

const clientWidgets = list('DEFAULT_LAYOUT');
const heroZone = list('HERO_ZONE');
const bodyZone = list('BODY_ZONE');

const clientRequired = (page.match(/const REQUIRED_WIDGETS = new Set\(\[([^\]]*)\]\)/)?.[1] ?? '')
  .split(',')
  .map((s) => s.trim().replace(/^'|'$/g, ''))
  .filter(Boolean);

// ───── ١ · أسماء الودجات ─────

if (serverWidgets.size === 0) {
  fail('widgets', 'تعذّر استخراج قائمة الودجات من ProductLayout.php — تغيّر شكل الثابت؟');
} else {
  const server = [...serverWidgets.keys()].sort();
  const client = [...clientWidgets].sort();
  const onlyServer = server.filter((w) => !client.includes(w));
  const onlyClient = client.filter((w) => !server.includes(w));

  if (onlyServer.length > 0 || onlyClient.length > 0) {
    const parts = [];
    if (onlyServer.length > 0) parts.push(`في الخادم بلا مُصيِّر: ${onlyServer.join('، ')}`);
    if (onlyClient.length > 0) parts.push(`في الواجهة بلا سماح من الخادم: ${onlyClient.join('، ')}`);
    fail('widgets', parts.join(' · '));
  } else {
    pass('widgets', `${server.length} ودجة متطابقة بين الخادم والواجهة`);
  }
}

// ───── ٢ · المناطق ─────

{
  const zoned = new Set([...heroZone, ...bodyZone, 'gallery']);
  const missing = [...serverWidgets.keys()].filter((w) => !zoned.has(w));
  const overlap = heroZone.filter((w) => bodyZone.includes(w));

  if (missing.length > 0) {
    fail('zones', `ودجات بلا منطقة في الواجهة فلن تُصيَّر أبدًا: ${missing.join('، ')}`);
  } else if (overlap.length > 0) {
    // ودجة في منطقتين تُصيَّر مرّتين — عنوانان أو صندوقا شراء في صفحة واحدة.
    fail('zones', `ودجات في منطقتين معًا: ${overlap.join('، ')}`);
  } else {
    const mismatched = [...serverWidgets.entries()].filter(([widget, zone]) => {
      if (zone === 'media') return widget !== 'gallery';
      if (zone === 'hero') return !heroZone.includes(widget);
      return !bodyZone.includes(widget);
    });

    if (mismatched.length > 0) {
      fail('zones', `منطقة مختلفة بين الخادم والواجهة: ${mismatched.map(([w]) => w).join('، ')}`);
    } else {
      pass('zones', 'كل ودجة في المنطقة نفسها على الجانبين');
    }
  }
}

// ───── ٢ب · الترتيب الافتراضي ─────

/*
 * ترتيب `WIDGETS` في الخادم هو ما يبدأ منه محرّر اللوحة، و`DEFAULT_LAYOUT` هو ما
 * تُصيِّره الواجهة حين لا تخطيط منشورًا. افتراقهما يجعل «نشرُ التخطيط دون لمسه»
 * يعيد ترتيب التبويبات وصندوق الشراء — تغييرٌ مرئيّ لم يطلبه أحد.
 */
{
  const server = [...serverWidgets.keys()].join(',');
  const client = clientWidgets.join(',');
  if (server !== client) {
    fail('order', `الترتيب الافتراضي مختلف — الخادم: ${server} · الواجهة: ${client}`);
  } else {
    pass('order', 'الترتيب الافتراضي واحد على الجانبين');
  }
}

// ───── ٣ · الأقسام الإلزامية ─────

{
  const server = [...serverRequired].sort().join(',');
  const client = [...clientRequired].sort().join(',');

  if (server === '' ) {
    fail('required', 'تعذّر استخراج REQUIRED من ProductLayout.php');
  } else if (server !== client) {
    // الواجهة الأضعف تُخفي قسمًا يمنع الخادمُ إخفاءه: الصفحة تفقد الشراء.
    fail('required', `الإلزامي مختلف — الخادم: ${server} · الواجهة: ${client}`);
  } else {
    pass('required', `الأقسام الإلزامية متطابقة (${server})`);
  }
}

// ───── ٤ · أقسام الرئيسة ─────

/*
 * المفردات في مكانين: `HomeContent::SECTIONS` يقرّر ما يعرضه مُبدِّل اللوحة،
 * و`HOMEPAGE_SECTION_IDS` يقرّر ما له مُصيِّر. وانحرافُهما عطلٌ صامت في
 * الاتّجاهين: مُعرِّفٌ في الخادم بلا مُصيِّر قسمٌ يفعّله المالك ولا يظهر له
 * شيء؛ ومُعرِّفٌ في الواجهة بلا مفردةٍ في الخادم قسمٌ لا يستطيع تفعيله أبدًا.
 */
{
  const serverSections = [
    ...(homePhp.match(/public const SECTIONS = \[([\s\S]*?)\n {4}\];/)?.[1] ?? '').matchAll(
      /'([a-z-]+)'\s*=>\s*\['label'/g,
    ),
  ].map((m) => m[1]);

  /*
   * مُطابِقٌ مثبَّت على السطر لا تقسيمٌ بالفواصل.
   *
   * القائمة تتخلّلها تعليقاتٌ عربيّة تحوي فواصل وأسماء أقسام بين علامتين
   * مائلتين، فالتقسيم بالفاصلة يلصق نصّ التعليق بالمُعرِّف الذي يليه فيسقط.
   * وقع فعلًا: أبلغ الحارس أنّ `smart-finder` «في الخادم بلا مُصيِّر» وله
   * مُصيِّر — وحارسٌ يكذب أسوأ من غيابه لأنّه يُطارَد.
   */
  const clientSections = [
    ...(homeTs.match(/HOMEPAGE_SECTION_IDS = \[([\s\S]*?)\] as const/)?.[1] ?? '').matchAll(
      /^\s*'([a-z-]+)',/gm,
    ),
  ].map((m) => m[1]);

  if (serverSections.length === 0 || clientSections.length === 0) {
    fail('home-sections', 'تعذّر استخراج مفردات أقسام الرئيسة — تغيّر شكل الثابت؟');
  } else {
    const onlyServer = serverSections.filter((s) => !clientSections.includes(s));
    const onlyClient = clientSections.filter((s) => !serverSections.includes(s));

    if (onlyServer.length > 0 || onlyClient.length > 0) {
      const parts = [];
      if (onlyServer.length > 0) parts.push(`في الخادم بلا مُصيِّر: ${onlyServer.join('، ')}`);
      if (onlyClient.length > 0) parts.push(`في الواجهة بلا مفردة: ${onlyClient.join('، ')}`);
      fail('home-sections', parts.join(' · '));
    } else {
      /* وقسمٌ واحدٌ بـ`hero: true` على الأكثر مُفعَّل — يفرضه الكاتب، وهنا يُعدّ المُعلَن. */
      const heroes = [
        ...(homePhp.match(/public const SECTIONS = \[([\s\S]*?)\n {4}\];/)?.[1] ?? '').matchAll(
          /'([a-z-]+)'\s*=>\s*\['label'\s*=>\s*'[^']*',\s*'hero'\s*=>\s*true\]/g,
        ),
      ].map((m) => m[1]);
      pass(
        'home-sections',
        `${serverSections.length} قسمًا متطابقًا · ${heroes.length} منها يحمل h1 (${heroes.join('، ')})`,
      );
    }
  }
}

/*
 * رمز الخروج يُمرَّر: حارسٌ يطبع × ويخرج صفرًا لا يحرس شيئًا — يراه
 * المشغّل فاشلًا ويراه البناء ناجحًا، وهو أسوأ من غيابه لأنّه يُطمئن.
 */
process.exit(report('guard-layout-parity', results));
