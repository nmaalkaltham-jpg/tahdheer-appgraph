#!/usr/bin/env node
/**
 * مسحُ حالة العطل — ماذا يرى الزائر حين ينقطع الخادم.
 *
 * ─── ما يُقاس ───
 *
 * كلّ نداءٍ من المتصفّح إلى الـAPI يُجهَض، ثمّ تُفحص كلّ صفحة: هل تنهار؟
 * هل تُظهر فراغًا صامتًا؟ أم تُعلن العطل وتمنح مخرجًا؟
 *
 * والمعيار الثالث هو المهمّ: رسالةٌ صادقة بلا زرّ إعادة محاولة تترك الزائر
 * في طريقٍ مسدود، وصفحةٌ فارغة بلا رسالة تجعله يظنّ أنّ سلّته فرغت.
 *
 * ─── وحدُّ هذا الماسح، مذكورًا صراحةً ───
 *
 * الإجهاض يقع في **المتصفّح** فقط. وصفحات Next تجلب بياناتها على الخادم
 * (`INTERNAL_API_URL` من عملية Node لا من المتصفّح)، فتلك النداءات تنجح
 * هنا. أي أنّ ما يُقاس هو انقطاعُ الطرف العميل — السلّة والموافقة والقياس
 * — لا انقطاعُ الواجهة الخلفيّة كلّها. ومحاكاةُ الثاني تحتاج إيقاف الخدمة
 * نفسها، وهو تغييرٌ في بيئة المشغّل لا يقرّره ماسح.
 *
 *   npm run dev
 *   npm run sweep:offline
 */

import { chromium } from 'playwright';
import { resolveRoutes, readArg } from './lib/storefront-routes.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const API = readArg('api', 'http://127.0.0.1:58080');
const ROUTES = await resolveRoutes();

/** نصوصٌ تُعلن الغياب — من المتجر نفسه لا من خيالنا. */
const DECLARES = /تعذّر|غير متاح|لا توجد|لم نتمكّن|أعد المحاولة|فارغ|لا نتائج|غير مضبوط|لا يوجد/;

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

/*
 * أخطاء إطار العمل في وضع التطوير ليست أخطاء المتجر.
 *
 * عند إجهاض الجلب يحسب قياسُ Next الزمنيّ مدّةً سالبة فيرمي
 * `Failed to execute 'measure' on 'Performance'`. وعلامتُه تبدأ بمسافةٍ
 * صفريّة العرض — وهي اصطلاح Next لإخفاء علاماته عن الخطّ الزمنيّ للمستخدم،
 * فتُميّزها بلا لبس. وهذه الأداة تُنزَع في بناء الإنتاج أصلًا.
 *
 * وقد قيس ذلك: صفر أخطاء والـAPI متاح، وخطأ واحد عند إجهاضه. فنسبتُه إلى
 * المتجر ادّعاءُ عطلٍ لا وجود له خارج خادم التطوير.
 */
const FRAMEWORK_NOISE = /Failed to execute 'measure' on 'Performance'/;

const thrown = [];
page.on('pageerror', (e) => {
  const text = String(e);
  if (FRAMEWORK_NOISE.test(text) && /['"]​/.test(text)) return;
  thrown.push(text.slice(0, 90));
});
await page.route(`**${new URL(API).host}/**`, (r) => r.abort());

let failures = 0;

for (const route of ROUTES) {
  thrown.length = 0;
  let status = 0;

  try {
    const res = await page.goto(BASE + route, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    status = res ? res.status() : 0;
  } catch {
    console.log(`  × ${route.padEnd(34)} لم تُحمَّل إطلاقًا`);
    failures += 1;
    continue;
  }

  await page.waitForTimeout(1200);

  const state = await page.evaluate((pattern) => {
    const main = document.querySelector('main');
    const text = (main?.innerText || '').trim();
    return {
      chars: text.length,
      declares: new RegExp(pattern).test(text),
      /* المخرج شرطُ الصدق: رسالةٌ بلا فعلٍ تترك الزائر في طريقٍ مسدود. */
      actions: [...(main?.querySelectorAll('a,button') ?? [])]
        .map((e) => e.textContent.trim()).filter(Boolean).length,
    };
  }, DECLARES.source);

  const bad = [];
  if (status >= 500) bad.push(`HTTP ${status}`);
  if (thrown.length) bad.push(`استثناء: ${thrown[0]}`);
  /* صفحةٌ شبه فارغة لا تقول شيئًا ولا تعرض فعلًا = فراغٌ صامت. */
  if (state.chars < 60 && !state.declares) bad.push(`فراغٌ صامت (${state.chars} حرفًا)`);
  if (state.declares && state.actions === 0) bad.push('تُعلن العطل بلا مخرج');

  if (bad.length) {
    failures += bad.length;
    console.log(`  × ${route.padEnd(34)} ${bad.join(' · ')}`);
  } else {
    console.log(`  ✓ ${route.padEnd(34)} ${status} · ${state.chars} حرفًا${state.declares ? ` · يُعلن العطل · ${state.actions} فعلًا` : ''}`);
  }
}

await browser.close();
console.log(`\n  ${ROUTES.length} مسارًا · ${failures ? `${failures} خرقًا` : 'بلا خرق'}\n`);
process.exit(failures ? 1 : 0);
