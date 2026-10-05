/**
 * ماسح استغلال المساحة — سقّاطةٌ لا حكم ذوق.
 *
 * ─── ما وجدته القياسات قبل هذا الملفّ ───
 *
 * قيس عرضُ المحتوى إلى عرض الشاشة على ثماني صفحاتٍ عند ‎1440‎ و‎1920‎
 * و‎2560‎. النتيجة: الرئيسة والحساب ‎100%‎، والمتجر والمدوّنة ‎82–88%‎،
 * والسلّة والدفع وصفحة المنتج ‎44–47%‎ عند ‎2560‎.
 *
 * والأربعون بالمئة ليست هدرًا: رمز `--container-content` مكتوبٌ في
 * `tokens.css` بنيّته صراحةً — «نثرٌ وبطاقاتٌ معًا: صفحة منتج، سلّة، صفحةُ
 * شرحٍ فيها شبكة». وقاعدة البروتوكول أنّ **المساحة المهدورة بلا تبرير**
 * عطل؛ وهذه مبرَّرة ومكتوبة. فلا تُوسَّع على ذوق.
 *
 * وكذلك الفجوات الرأسيّة: أكبرها ‎172px‎ على الرئيسة، وهي حشوُ قسمٍ ينتهي
 * وقسمٍ يبدأ — إيقاعٌ مقصود لا فراغ ميت. والفجوة الكبرى على الصفحات
 * القصيرة (‎199–228px‎) هي `min-block-size: 60vb` على `main` تمنع التذييل
 * أن يصعد — نمطٌ مقصود كذلك.
 *
 * ─── فما الذي يحرسه هذا الملفّ إذن ───
 *
 * أن تبقى هذه الأرقام حيث هي. صفحةٌ تسقط من `--container-wide` إلى
 * `--container-content` بسهوٍ تفقد ثلث عرضها بلا أن يصيح شيء؛ وفجوةٌ تنمو
 * من ‎172‎ إلى ‎400‎ لا يراها أحد. فالقياس يُسجَّل مرّةً، ويُقارَن بعدها.
 *
 *   npm run sweep:space            # يقارن بالمسجَّل
 *   npm run sweep:space -- --record  # يُعيد التسجيل بعد تغييرٍ مقصود
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';
import { readArg, gotoChecked } from './lib/storefront-routes.mjs';
import { sweepVerdict, describeLoadFailure } from './lib/sweep-verdict.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const WIDTHS = readArg('widths', '1440,2560').split(',').map(Number);
const RECORD = process.argv.includes('--record');
const LEDGER = new URL('./space-baseline.json', import.meta.url);

/** هوامش التسامح: ضيقٌ في العرض، وسعةٌ في الفجوة. */
const RATIO_DROP = 4;
const GAP_GROWTH = 40;

const ROUTES = readArg(
  'routes',
  '/,/shop,/cart,/checkout,/blog,/help,/about,/how-it-works,/bundles,/payment-methods',
)
  .split(',')
  .filter(Boolean);

const measure = () => {
  const vw = document.documentElement.clientWidth;
  const main = document.querySelector('main');
  if (main === null) return null;

  /*
   * العرض يُقاس على **الحاوية** لا على أعرض محتوًى.
   *
   * أوّل صياغةٍ قاست أعرض عنصرٍ يحمل نصًّا، وهو يتبع البيانات: صفحةٌ بلا
   * منتجات تعطي رقمًا آخر. والمقصود قرارُ التخطيط لا حظُّ المحتوى — وهو
   * العنصر المتوسّط (`margin-inline: auto`) الأعرض داخل `main`.
   */
  let widest = 0;
  const spans = [];
  for (const el of main.querySelectorAll('*')) {
    if (!el.checkVisibility()) continue;
    const cs = getComputedStyle(el);
    const b = el.getBoundingClientRect();
    if (cs.marginInlineStart === cs.marginInlineEnd && el.parentElement === main && b.width > widest) {
      widest = b.width;
    }
    const hasText = [...el.childNodes].some(
      (n) => n.nodeType === 3 && (n.textContent ?? '').trim().length > 0,
    );
    const isMedia = ['IMG', 'SVG', 'VIDEO', 'CANVAS', 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA'].includes(
      el.tagName,
    );
    if (!hasText && !isMedia) continue;
    if (b.height < 1) continue;
    spans.push([b.top + window.scrollY, b.bottom + window.scrollY]);
  }
  if (widest === 0) widest = main.getBoundingClientRect().width;

  /* الفجوات الداخليّة وحدها — الذيل تحت `min-block-size` مقصود. */
  spans.sort((a, b) => a[0] - b[0]);
  let gap = 0;
  let cursor = 0;
  let started = false;
  for (const [top, bottom] of spans) {
    if (started && top - cursor > gap) gap = top - cursor;
    if (bottom > cursor) cursor = bottom;
    started = true;
  }

  return { ratio: Math.round((widest / vw) * 100), gap: Math.round(gap) };
};

const baseline = RECORD ? {} : JSON.parse(readFileSync(LEDGER, 'utf8'));
const browser = await chromium.launch();
let breaches = 0;
let instrumentErrors = 0;
let checks = 0;

for (const width of WIDTHS) {
  console.log(`\n  ── ${width}px`);
  const ctx = await browser.newContext({ viewport: { width, height: 1000 } });
  const page = await ctx.newPage();

  for (const route of ROUTES) {
    try {
      await gotoChecked(page, BASE, route, { waitUntil: 'networkidle', timeout: 45_000 });
    } catch (e) {
      instrumentErrors += 1;
      console.log(`  ⚠ ${route} · ${describeLoadFailure(e)}`);
      continue;
    }
    await page.waitForTimeout(300);
    const m = await page.evaluate(measure);
    if (m === null) {
      instrumentErrors += 1;
      console.log(`  ⚠ عطلُ قياس · لا <main> في ${route}`);
      continue;
    }
    checks += 1;
    const key = `${route}@${width}`;

    if (RECORD) {
      baseline[key] = m;
      console.log(`  · ${route.padEnd(22)} عرض ${String(m.ratio).padStart(3)}% · فجوة ${m.gap}px`);
      continue;
    }

    const was = baseline[key];
    if (was === undefined) {
      breaches += 1;
      console.log(`  × ${route.padEnd(22)} لا خطّ أساسٍ مسجَّل عند ${width} — سجِّل أو احذف المسار`);
      continue;
    }

    const bad = [];
    if (m.ratio < was.ratio - RATIO_DROP) {
      bad.push(`العرض ${m.ratio}% وكان ${was.ratio}% — ضاق بلا إعلان`);
    }
    if (m.gap > was.gap + GAP_GROWTH) {
      bad.push(`أكبر فجوة ${m.gap}px وكانت ${was.gap}px — اتّسع الفراغ`);
    }

    if (bad.length > 0) {
      breaches += bad.length;
      console.log(`  × ${route}`);
      for (const b of bad) console.log(`      ${b}`);
    } else {
      console.log(`  ✓ ${route.padEnd(22)} عرض ${String(m.ratio).padStart(3)}% · فجوة ${String(m.gap).padStart(3)}px`);
    }
  }
  await ctx.close();
}

await browser.close();

if (RECORD) {
  writeFileSync(LEDGER, `${JSON.stringify(baseline, null, 2)}\n`);
  console.log(`\n  سُجِّل ${Object.keys(baseline).length} قياسًا في space-baseline.json\n`);
  process.exit(0);
}

console.log(
  `\n  ${ROUTES.length} مسارًا · ${checks} قياسًا · ${breaches ? `${breaches} خرقًا` : 'بلا خرق'}` +
    `${instrumentErrors > 0 ? ` · ${instrumentErrors} عطلَ قياس` : ''}\n`,
);
/* صفرُ قياسٍ ليس نجاحًا (lib/sweep-verdict) — كان يخرج بـ0 حين لا يُقاس شيء. */
const verdict = sweepVerdict({ measured: checks, breaches, instrumentErrors });
if (verdict.code !== 0) console.log(`  × ${verdict.reason}\n`);
process.exit(verdict.code);
