/**
 * ماسح حالات الفشل — ماذا يرى الزائر حين تُخفق الواجهة الخلفيّة.
 *
 * ─── الثغرة التي يسدّها ───
 *
 * `sweep:offline` يُجهض نداءات **المتصفّح** وحدها، ويقول ذلك صراحةً في
 * رأسه: صفحات Next تجلب على الخادم عبر `INTERNAL_API_URL`، فتلك النداءات
 * كانت تنجح دائمًا. أي أنّ ما يحكم أغلب ما يُرسم — الجلب الخادميّ — لم
 * يُقس تحت الفشل قطّ.
 *
 * ─── وكيف يُقاس بلا لمس بيئة المشغّل ───
 *
 * وسيطٌ يحقن العطل يعمل على منفذٍ خاصّ، ونسخةٌ من المتجر تُرفع وهي تشير
 * إليه بـ`INTERNAL_API_URL`. فلا تُوقَف خدمة، ولا تُغيَّر تهيئة، ويُعاد
 * التشغيل متى شئنا. والمتصفّح يُعطَّل بالتوازي عبر اعتراض Playwright.
 *
 * ─── الأنماط الأربعة ───
 *
 *  · `down`    — الوصلة تُرفض. انقطاعٌ تامّ.
 *  · `500`     — الخادم يردّ بخطأ. أسوأ من الانقطاع: الردّ صالحٌ شكلًا.
 *  · `garbage` — ‎200‎ بجسمٍ ليس JSON. يكشف من يثق بالشكل بلا فحص.
 *  · `timeout` — لا ردّ إطلاقًا. يكشف غياب مهلةٍ في الجلب.
 *
 *   npm run sweep:faults -- --mode=500
 *   npm run sweep:faults -- --mode=all
 */
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { chromium } from 'playwright';
import { STATIC_ROUTES, readArg } from './lib/storefront-routes.mjs';

const ALL_MODES = ['down', '500', 'garbage', 'timeout'];
const MODES = readArg('mode', 'all')
  .split(',')
  .flatMap((m) => (m === 'all' ? ALL_MODES : [m]));
const PROXY_PORT = Number(readArg('proxy-port', 3399));
const APP_PORT = Number(readArg('app-port', 3398));
const SERVER = readArg(
  'server',
  'apps/storefront/.next/standalone/apps/storefront/server.js',
);
const ROUTES = readArg('routes', STATIC_ROUTES.join(',')).split(',').filter(Boolean);
const GOTO_MS = Number(readArg('goto-ms', 20_000));

/** نصوصٌ تُعلن الغياب — من المتجر نفسه لا من خيالنا. */
const DECLARES = /تعذّر|غير متاح|لا توجد|لم نتمكّن|أعد المحاولة|فارغ|لا نتائج|غير مضبوط|لا يوجد|قريبًا/;

/*
 * ضجيج إطار العمل في وضع التطوير — منسوخ من `sweep:offline` لسببه نفسه:
 * قياس Next الزمنيّ يرمي عند إجهاض الجلب، وعلامته تبدأ بمسافةٍ صفريّة
 * العرض. وبناء الإنتاج لا يحمله أصلًا.
 */
const FRAMEWORK_NOISE = /Failed to execute 'measure' on 'Performance'/;

let mode = 'down';
const held = new Set();

const proxy = createServer((req, res) => {
  if (mode === 'timeout') {
    held.add(res);
    return; // لا ردّ، ولا إغلاق.
  }
  if (mode === '500') {
    res.writeHead(500, { 'content-type': 'application/json' });
    res.end('{"message":"upstream exploded"}');
    return;
  }
  if (mode === 'garbage') {
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end('<!doctype html><h1>502 Bad Gateway</h1>');
    return;
  }
  res.destroy(); // down
});
proxy.on('connection', (socket) => socket.on('error', () => undefined));
await new Promise((ok) => proxy.listen(PROXY_PORT, '127.0.0.1', ok));

const app = spawn('node', [SERVER], {
  env: {
    ...process.env,
    PORT: String(APP_PORT),
    HOSTNAME: '127.0.0.1',
    INTERNAL_API_URL: `http://127.0.0.1:${PROXY_PORT}`,
  },
  stdio: ['ignore', 'pipe', 'pipe'],
});
const appLog = [];
app.stdout.on('data', (d) => appLog.push(String(d)));
app.stderr.on('data', (d) => appLog.push(String(d)));

const BASE = `http://127.0.0.1:${APP_PORT}`;
let up = false;
for (let i = 0; i < 40 && !up; i++) {
  up = await fetch(BASE + '/nope-404', { signal: AbortSignal.timeout(2000) })
    .then(() => true)
    .catch(() => false);
  if (!up) await new Promise((ok) => setTimeout(ok, 500));
}
if (!up) {
  console.log(`تعذّر رفع المتجر من «${SERVER}» — هل بُني بعد؟\n${appLog.join('').slice(-400)}`);
  app.kill();
  proxy.close();
  process.exit(0);
}

const browser = await chromium.launch();
let breaches = 0;

for (const m of MODES) {
  mode = m;
  for (const res of held) res.destroy();
  held.clear();

  console.log(`\n  ── نمط «${m}»`);
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const thrown = [];
  page.on('pageerror', (e) => {
    const text = String(e);
    if (FRAMEWORK_NOISE.test(text)) return;
    thrown.push(text.slice(0, 100));
  });
  /* المتصفّح يُعطَّل بالتوازي: عطلٌ في طرفٍ واحد ليس عطل الواجهة الخلفيّة. */
  await ctx.route('**/api/v1/**', (r) => r.abort());

  for (const route of ROUTES) {
    thrown.length = 0;
    const t0 = Date.now();
    let status = 0;
    let hung = false;

    try {
      const res = await page.goto(BASE + route, { waitUntil: 'domcontentloaded', timeout: GOTO_MS });
      status = res ? res.status() : 0;
    } catch {
      hung = true;
    }
    const ms = Date.now() - t0;

    if (hung) {
      breaches += 1;
      console.log(`  × ${route.padEnd(30)} لم تردّ خلال ${GOTO_MS}ms — لا مهلة في الجلب الخادميّ`);
      continue;
    }

    await page.waitForTimeout(500);
    const state = await page.evaluate((pattern) => {
      const main = document.querySelector('main');
      const text = (main?.innerText ?? '').trim();
      return {
        chars: text.length,
        declares: new RegExp(pattern).test(text),
        actions: [...(main?.querySelectorAll('a,button') ?? [])].filter(
          (e) => (e.textContent ?? '').trim().length > 0,
        ).length,
        /* صفرٌ مصنوع: رقمٌ معروضٌ بلا بيانات خلفه هو ما تمنعه عقيدة الصدق. */
        zeros: (text.match(/\b0\b/g) ?? []).length,
      };
    }, DECLARES.source);

    const bad = [];
    if (status >= 500) bad.push(`HTTP ${status}`);
    if (thrown.length > 0) bad.push(`استثناء: ${thrown[0]}`);
    if (state.chars < 60 && !state.declares) bad.push(`فراغٌ صامت (${state.chars} حرفًا)`);
    if (state.declares && state.actions === 0) bad.push('يُعلن العطل بلا مخرج');

    if (bad.length > 0) {
      breaches += bad.length;
      console.log(`  × ${route.padEnd(30)} ${bad.join(' · ')}`);
    } else {
      console.log(
        `  ✓ ${route.padEnd(30)} ${status} · ${ms}ms · ${state.chars} حرفًا${state.declares ? ' · يُعلن' : ''} · ${state.actions} فعلًا`,
      );
    }
  }

  await ctx.close();
}

await browser.close();
for (const res of held) res.destroy();
app.kill();
proxy.close();

console.log(`\n  ${MODES.length} نمطًا × ${ROUTES.length} مسارًا · ${breaches ? `${breaches} خرقًا` : 'بلا خرق'}\n`);
process.exit(breaches ? 1 : 0);
