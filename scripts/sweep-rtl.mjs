/**
 * ماسح الاتّجاه — ما يُرسم فعلًا في صفحةٍ عربيّة، لا ما هو مكتوبٌ في CSS.
 *
 * ─── الثغرة التي يسدّها ───
 *
 * الخصائص الفيزيائيّة مرفوضةٌ في نصّ CSS (`margin-left`، `left:`).
 * وهو فحصٌ ضروريّ لا يرى ثلاثة أصنافٍ تقع في المتصفّح وحده:
 *
 *  ١· رقمٌ أو لاتينيّةٌ بلا عزلٍ ثنائيّ الاتّجاه. المتجر يكتب الأسعار داخل
 *     `<bdi dir="ltr">` عمدًا؛ ورقمٌ يفلت من ذلك تعيد خوارزميّة Bidi ترتيب
 *     رموزه، فيقرأ الزائر سعرًا غير السعر.
 *  ٢· مسطح تمريرٍ أفقيّ يبدأ في الطرف الخطأ. في RTL يبدأ `scrollLeft` من
 *     الصفر عند حافّة البداية (اليمين)؛ وقيمةٌ غيرها عند التحميل تعني أنّ
 *     الشريط يفتح في منتصفه أو في آخره.
 *  ٣· إزاحةٌ أفقيّة ثابتة (`translateX`) لا تنعكس مع الاتّجاه — فتزيح في
 *     العربيّة إلى الجهة المعاكسة لما قُصد.
 *
 *   npm run sweep:rtl
 */
import { chromium } from 'playwright';
import { readArg, resolveRoutes, gotoChecked } from './lib/storefront-routes.mjs';
import { sweepVerdict, describeLoadFailure } from './lib/sweep-verdict.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const WIDTHS = readArg('widths', '390,1440').split(',').map(Number);

const probe = () => {
  const name = (el) =>
    `${el.tagName.toLowerCase()}.${(el.className || '').toString().replace(/[a-z-]+-module__\w+__/g, '').split(' ')[0]}`;
  const out = [];

  /* رقمٌ بصيغةٍ حسّاسة للترتيب: كسرٌ عشريّ، أو رقمٌ مع عملةٍ أو نسبة. */
  const NUMERIC = /[0-9]{1,3}[.,][0-9]{2}|[0-9]+\s*(ر\.س|SAR|%)/;
  for (const el of document.querySelectorAll('main *')) {
    if (el.children.length > 0) continue;
    /* الوسوم غير المرسومة ليست نصًّا يقرؤه أحد. */
    if (['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE'].includes(el.tagName)) continue;
    const text = (el.textContent ?? '').trim();
    if (!NUMERIC.test(text)) continue;
    const cs = getComputedStyle(el);
    const isolated =
      el.closest('bdi') !== null || cs.unicodeBidi.includes('isolate') || cs.direction === 'ltr';
    if (!isolated) out.push(`رقمٌ بلا عزل · ${name(el)} «${text.slice(0, 26)}»`);
  }

  for (const el of document.querySelectorAll('*')) {
    const cs = getComputedStyle(el);
    if (cs.overflowX !== 'auto' && cs.overflowX !== 'scroll') continue;
    if (el.scrollWidth <= el.clientWidth + 1) continue;
    if (Math.abs(el.scrollLeft) > 2) {
      out.push(`مسطحٌ يفتح في غير بدايته · ${name(el)} scrollLeft=${Math.round(el.scrollLeft)}`);
    }
  }

  for (const el of document.querySelectorAll('main *')) {
    const t = getComputedStyle(el).transform;
    if (t === 'none' || !t.startsWith('matrix')) continue;
    const parts = t.slice(t.indexOf('(') + 1, -1).split(',').map(Number);
    const tx = parts.length === 6 ? parts[4] : parts[12];
    if (Math.abs(tx) > 1) out.push(`إزاحةٌ أفقيّة ثابتة · ${name(el)} tx=${Math.round(tx)}`);
  }

  return { dir: document.documentElement.dir, breaches: [...new Set(out)].slice(0, 6) };
};

const routes = await resolveRoutes();
/*
 * تعذّر التحميل عطلُ قياسٍ لا نتيجة.
 *
 * كان الاستثناء يُبتلع و`continue` يمضي، فلو سقط الخادم لأبلغ الماسح
 * «‎36‎ مسارًا · بلا خرق» وهو لم يقس واحدًا. والخضرةُ على لا شيء أخطر من
 * الحمرة على شيء — فتُعدّ الإخفاقات وتُعلن، ويسقط التشغيل بها.
 */
const browser = await chromium.launch();
let breaches = 0;
let instrumentErrors = 0;
let checks = 0;

for (const width of WIDTHS) {
  console.log(`\n  ── ${width}px`);
  const ctx = await browser.newContext({ viewport: { width, height: 900 } });
  const page = await ctx.newPage();

  for (const route of routes) {
    try {
      await gotoChecked(page, BASE, route, { waitUntil: 'networkidle', timeout: 45_000 });
    } catch (e) {
      instrumentErrors += 1;
      console.log(`  ⚠ ${route} · ${describeLoadFailure(e)}`);
      continue;
    }
    await page.waitForTimeout(250);
    const m = await page.evaluate(probe);
    checks += 1;

    if (m.dir !== 'rtl') {
      breaches += 1;
      console.log(`  × ${route.padEnd(34)} اتّجاه المستند «${m.dir}» لا «rtl»`);
      continue;
    }
    if (m.breaches.length > 0) {
      breaches += m.breaches.length;
      console.log(`  × ${route}`);
      for (const b of m.breaches) console.log(`      ${b}`);
    } else {
      console.log(`  ✓ ${route.padEnd(34)} rtl`);
    }
  }
  await ctx.close();
}

await browser.close();
console.log(
  `\n  ${routes.length} مسارًا · ${checks} قياسًا · ${breaches ? `${breaches} خرقًا` : 'بلا خرق'}` +
    `${instrumentErrors > 0 ? ` · ${instrumentErrors} عطلَ قياس` : ''}\n`,
);
/* صفرُ قياسٍ ليس نجاحًا (lib/sweep-verdict) — كان يخرج بـ0 حين لا يُقاس شيء. */
const verdict = sweepVerdict({ measured: checks, breaches, instrumentErrors });
if (verdict.code !== 0) console.log(`  × ${verdict.reason}\n`);
process.exit(verdict.code);
