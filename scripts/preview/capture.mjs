#!/usr/bin/env node
/**
 * لقطات معاينة للمتجر — بلا واجهة وبلا سرقة تركيز.
 * Storefront preview screenshots — headless, never steals focus.
 *
 * يشغّل Chrome في وضع headless مقابل خادم Next يعمل مسبقًا، ويلتقط كل صفحة
 * بعرض سطح المكتب والجوال. لا يبدأ الخوادم ولا يوقفها — ذلك مسؤولية المشغّل،
 * حتى يبقى هذا السكربت آمنًا للتشغيل المتكرر.
 *
 * الوجهة مجلد ظاهر بالعربية عمدًا: أي اسم يبدأ بنقطة (مثل .preview) يخفيه
 * Finder، فلا يجد المالك اللقطات التي طُلبت منه أصلًا.
 *
 *   node scripts/preview/capture.mjs [--base http://127.0.0.1:3000] [--out "معاينة الصفحات/المتجر"]
 */
import { execFile } from 'node:child_process';
import { mkdir, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const run = promisify(execFile);
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const CHROME_CANDIDATES = [
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/Applications/Chromium.app/Contents/MacOS/Chromium',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
];

/** المقاسات من MIP §2.4: سطح المكتب 1280+ والجوال 390. */
const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 2600 },
  { name: 'mobile', width: 390, height: 2200 },
];

/** الصفحات المعتمدة في خريطة التحويل (docs/design/DESIGN-CONVERSION-MAP.md). */
const PAGES = [
  { slug: 'home', path: '/' },
  { slug: 'product', path: '/products/%D8%AA%D8%AD%D8%B6%D9%8A%D8%B1-%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6%D9%8A%D8%A7%D8%AA-%D8%B3%D8%A7%D8%AF%D8%B3-%D8%A7%D8%A8%D8%AA%D8%AF%D8%A7%D8%A6%D9%8A-%D8%A7%D9%84%D9%81%D8%B5%D9%84-%D8%A7%D9%84%D8%A3%D9%88%D9%84' },
  { slug: 'stage', path: '/stages/%D8%A7%D8%A8%D8%AA%D8%AF%D8%A7%D8%A6%D9%8A' },
  { slug: 'search', path: '/search?q=%D8%B1%D9%8A%D8%A7%D8%B6%D9%8A%D8%A7%D8%AA' },
  { slug: 'cart', path: '/cart' },
  { slug: 'checkout', path: '/checkout' },
  { slug: 'shop', path: '/shop' },
  { slug: 'offers', path: '/offers' },
  { slug: 'login', path: '/login' },
  { slug: 'account', path: '/account' },
  { slug: 'blog', path: '/blog' },
  { slug: 'about', path: '/about' },
  { slug: 'policies-refund', path: '/policies/refund' },
  { slug: 'payment-methods', path: '/payment-methods' },
  // The 404 design is only correct if the status really is 404.
  { slug: 'not-found', path: '/%D8%B5%D9%81%D8%AD%D8%A9-%D8%BA%D9%8A%D8%B1-%D9%85%D9%88%D8%AC%D9%88%D8%AF%D8%A9', expect: 404 },
];

function arg(flag, fallback) {
  const i = process.argv.indexOf(flag);
  return i !== -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

function findChrome() {
  const found = CHROME_CANDIDATES.find((p) => existsSync(p));
  if (!found) {
    // لا نخترع بديلًا صامتًا — نفشل بوضوح.
    throw new Error('لم يُعثر على Chrome/Chromium. ثبّته أو مرّر --chrome <path>.');
  }
  return arg('--chrome', found);
}

/** يتأكد أن الخادم يردّ قبل أي لقطة — بدل التقاط صفحة خطأ بصمت. */
async function waitForServer(base, tries = 30) {
  for (let i = 0; i < tries; i += 1) {
    try {
      const res = await fetch(base, { redirect: 'manual' });
      if (res.status < 500) return true;
    } catch {
      /* الخادم لم يقلع بعد */
    }
    await new Promise((r) => setTimeout(r, 1000));
  }
  return false;
}

async function main() {
  const base = arg('--base', 'http://127.0.0.1:3000').replace(/\/$/, '');
  const outDir = resolve(ROOT, arg('--out', 'معاينة الصفحات/المتجر'));
  const chrome = findChrome();

  if (!(await waitForServer(base))) {
    console.error(`× الخادم لا يستجيب على ${base} — شغّل المتجر أولًا.`);
    process.exit(1);
  }

  await rm(outDir, { recursive: true, force: true });
  await mkdir(outDir, { recursive: true });

  const captured = [];
  const failures = [];
  for (const page of PAGES) {
    for (const vp of VIEWPORTS) {
      const file = join(outDir, `${page.slug}-${vp.name}.png`);
      const url = `${base}${page.path}`;

      // Assert the page really renders BEFORE photographing it. Without this the
      // script happily screenshots a 404 and reports it as a success — which it
      // once did, and the failure was reported as a working page.
      const expected = page.expect ?? 200;
      let status = 0;
      try {
        status = (await fetch(url, { redirect: 'manual' })).status;
      } catch {
        status = 0;
      }
      if (status !== expected) {
        console.error(`× ${page.slug} · ${vp.name}: HTTP ${status} (توقّعنا ${expected}) — لم تُلتقط`);
        failures.push({ page: page.slug, viewport: vp.name, status });
        continue;
      }

      try {
        await run(chrome, [
          '--headless',
          '--disable-gpu',
          '--no-sandbox',
          '--hide-scrollbars',
          '--force-device-scale-factor=2',
          '--virtual-time-budget=6000', // امنح الخطوط والجلب وقتًا قبل اللقطة
          `--window-size=${vp.width},${vp.height}`,
          `--screenshot=${file}`,
          url,
        ], { timeout: 90_000 });
        captured.push({ page: page.slug, viewport: vp.name, file });
        console.log(`✓ ${page.slug} · ${vp.name}`);
      } catch (error) {
        // نُبلغ بالفشل ولا نبتلعه — لقطة ناقصة أسوأ من فشل معلن.
        console.error(`× ${page.slug} · ${vp.name}: ${error.message.split('\n')[0]}`);
      }
    }
  }

  console.log(`\n${captured.length}/${PAGES.length * VIEWPORTS.length} لقطة في ${outDir}`);
  if (failures.length > 0) {
    console.error(`${failures.length} صفحة لم تُلتقط — لا تُعتبر معاينة ناجحة.`);
    process.exit(1);
  }
  if (captured.length === 0) process.exit(1);
}

main().catch((error) => {
  console.error(error.message);
  process.exit(1);
});
