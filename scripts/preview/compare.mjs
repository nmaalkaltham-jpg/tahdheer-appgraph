#!/usr/bin/env node
/**
 * مقارنة بصرية: التصميم المرجعي ← التطبيق.
 * Visual comparison: canonical design artboard vs the running route.
 *
 * يُصيّر ملف التصميم (.dc.html) وصفحة التطبيق بنفس المقاس، ثم يبني صفحة
 * مقارنة جنبًا إلى جنب. الهدف من التوجيه: «غيّر التنفيذ لا المظهر المقصود».
 *
 * لا يدّعي مطابقة بكسلية: المرجع يحمل بيانات عيّنة والتطبيق يحمل بيانات حقيقية،
 * فالاختلاف في المحتوى متوقَّع ومقصود. المقارنة للبنية والمسافات والألوان.
 *
 * الوجهة مجلد ظاهر بالعربية عمدًا: أي اسم يبدأ بنقطة (مثل .preview) يخفيه
 * Finder، فلا يجد المالك اللقطات التي طُلبت منه أصلًا.
 *
 *   node scripts/preview/compare.mjs [--base http://127.0.0.1:3100] [--out "معاينة الصفحات/مقارنة التصميم بالتطبيق"]
 */
import { execFile } from 'node:child_process';
import { mkdir, rm, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const run = promisify(execFile);
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const DESIGNS = '/Users/hishamalkaltham/Desktop/متجر مستقل/تصميم المتجر المستقل ';

const CHROME = [
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
].find((p) => existsSync(p));

/**
 * كل زوج: ملف التصميم المعتمد ← مسار التطبيق المقابل.
 * Canonical pairs, taken from docs/design/DESIGN-CONVERSION-MAP.md.
 */
const PAIRS = [
  { name: 'home', design: 'TahHomeBody.dc.html', route: '/', width: 1440, height: 2600 },
  { name: 'home-mobile', design: 'TahHomeMobile.dc.html', route: '/', width: 390, height: 2400 },
  { name: 'category', design: 'Tahdheer - Category.dc.html', route: '/shop', width: 1440, height: 2400 },
  { name: 'search', design: 'Tahdheer - Search Results.dc.html', route: '/search?q=%D8%B1%D9%8A%D8%A7%D8%B6%D9%8A%D8%A7%D8%AA', width: 1440, height: 2000 },
  { name: 'cart', design: 'Tahdheer - Cart.dc.html', route: '/cart', width: 1440, height: 1800 },
  { name: 'checkout', design: 'Tahdheer - Checkout.dc.html', route: '/checkout', width: 1440, height: 2200 },
  { name: 'pdp', design: 'TahPdp.dc.html', route: '/products/dev-commerce-001', width: 1440, height: 2600 },
  { name: 'notfound', design: 'Tahdheer - 404.dc.html', route: '/%D9%84%D8%A7-%D8%B4%D9%8A%D8%A1', width: 1440, height: 1400 },
];

function arg(flag, fallback) {
  const i = process.argv.indexOf(flag);
  return i !== -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

async function shoot(url, file, width, height) {
  await run(CHROME, [
    '--headless', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
    '--virtual-time-budget=7000',
    `--window-size=${width},${height}`,
    `--screenshot=${file}`,
    url,
  ], { timeout: 90_000 });
}

async function main() {
  if (!CHROME) throw new Error('لم يُعثر على Chrome.');
  const base = arg('--base', 'http://127.0.0.1:3100').replace(/\/$/, '');
  const out = resolve(ROOT, arg('--out', 'معاينة الصفحات/مقارنة التصميم بالتطبيق'));

  await rm(out, { recursive: true, force: true });
  await mkdir(out, { recursive: true });

  const rows = [];
  for (const pair of PAIRS) {
    const designPath = join(DESIGNS, pair.design);
    if (!existsSync(designPath)) {
      console.error(`× ${pair.name}: ملف التصميم غير موجود — ${pair.design}`);
      continue;
    }

    // The route must really answer before it is worth photographing.
    let status = 0;
    try {
      status = (await fetch(base + pair.route, { redirect: 'manual' })).status;
    } catch { status = 0; }
    const expected = pair.name === 'notfound' ? 404 : 200;
    if (status !== expected) {
      console.error(`× ${pair.name}: التطبيق ردّ ${status} (توقّعنا ${expected})`);
      continue;
    }

    const refFile = join(out, `${pair.name}-design.png`);
    const appFile = join(out, `${pair.name}-app.png`);
    await shoot(`file://${designPath}`, refFile, pair.width, pair.height);
    await shoot(base + pair.route, appFile, pair.width, pair.height);

    rows.push(pair.name);
    console.log(`✓ ${pair.name} (${pair.width}px)`);
  }

  const html = `<!doctype html><html dir="rtl" lang="ar"><head><meta charset="utf-8">
<title>مقارنة التصميم بالتطبيق — التحاضير الحديثة</title>
<style>
  body{font-family:Tajawal,'SF Arabic',system-ui,sans-serif;background:#f7f9f6;color:#14261d;margin:0;padding:24px}
  h1{font-size:22px;margin:0 0 4px}
  p.note{color:#5f7167;margin:0 0 24px;line-height:1.9;max-inline-size:80ch}
  section{margin-block-end:40px;background:#fff;border:1px solid #e6e9e2;border-radius:14px;padding:16px}
  h2{font-size:16px;margin:0 0 12px}
  .pair{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  figure{margin:0}
  figcaption{font-size:12px;color:#5f7167;margin-block-end:6px}
  img{inline-size:100%;border:1px solid #e6e9e2;border-radius:10px;display:block}
</style></head><body>
<h1>مقارنة التصميم المرجعي بالتطبيق</h1>
<p class="note">يمين كل زوج: ملف التصميم المعتمد كما يُصيَّر مستقلًا. يسارُه: المسار المقابل في التطبيق يعمل ببيانات حقيقية.
اختلاف <strong>المحتوى</strong> متوقَّع ومقصود — المرجع يحمل أرقامًا وعيّنات، والتطبيق لا يعرض إلا ما تسنده بيانات حقيقية.
المقارنة هنا للبنية والترتيب والمسافات والألوان والطباعة.</p>
${rows.map((n) => `<section><h2>${n}</h2><div class="pair">
  <figure><figcaption>التطبيق</figcaption><img src="${n}-app.png" alt="التطبيق — ${n}"></figure>
  <figure><figcaption>التصميم المرجعي</figcaption><img src="${n}-design.png" alt="التصميم — ${n}"></figure>
</div></section>`).join('\n')}
</body></html>`;
  await writeFile(join(out, 'index.html'), html, 'utf8');

  console.log(`\n${rows.length}/${PAIRS.length} زوج في ${out}`);
  if (rows.length === 0) process.exit(1);
}

main().catch((e) => { console.error(e.message); process.exit(1); });
