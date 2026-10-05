#!/usr/bin/env node
/**
 * ماسح أهداف اللمس — هدفٌ فعّال لا يقلّ عن ‎44×44‎ لكلّ عنصرٍ تفاعليّ في `main`.
 *
 * ─── لماذا «فعّال» لا «مرسوم» ───
 *
 * زرّ السلّة في البطاقة ‎36px‎ مرئيًّا، وقلب المفضّلة ‎32px‎، وسطر الفتات ‎20px‎
 * — وكلّها تمدّ هدفها إلى ‎44px‎ بعنصرٍ زائف (`::before`) كي لا يتغيّر الرسم ولا
 * كثافة التخطيط. قياسُ `getBoundingClientRect` وحده يُبلغ عنها خطأً (وقد فعل:
 * ثلاثة «خروق» في شهادة التجارة كانت عطلَ أداة). فالقياس هنا بالنقر نفسه:
 * قرصٌ قطره ‎44‎ حول مركز العنصر، خمس نقاط، وكلّ نقطةٍ يجب أن تُصيب العنصر
 * (أو تسميته لمربّع الاختيار) عبر `elementFromPoint` — كما يُصيبه الإصبع.
 *
 * ─── ما يُستثنى ───
 *
 * رابطٌ داخل جملة (WCAG 2.5.8 «inline»): عنصرٌ `display:inline` في كتلةٍ نصُّها
 * أطول منه. وما عداه يُقاس.
 *
 *   npm run sweep:targets -- --routes=/cart,/checkout --no-discover [--min=44] [--min-fine=24] [--widths=320,390]
 */
import { chromium } from 'playwright';
import { readArg, resolveRoutes, gotoChecked, isTemporary } from './lib/storefront-routes.mjs';
import { ADD_TO_CART_NAME, sweepVerdict } from './lib/sweep-verdict.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const SEED = readArg('seed-product', '/products/dev-commerce-002');
/*
 * معيار المشروع: ‎44px‎ لكلّ هدفٍ يُلمس، في كلّ عرض (الكتالوج يصرّح بذلك
 * صراحةً: «اللمس يبقى ‎44px‎ في كلّ عرض»). والمؤشّر الدقيق وحده يُقاس بحدّ
 * WCAG 2.5.8 ‎24px‎ — ما يختاره الكتالوج (‎36px‎ لـ`pointer: fine`) والرئيسة
 * وصفحة المنتج (‎24px‎ موثَّقة). فتُقاس الأسطح كما يلقاها الإصبع والفأرة معًا.
 */
const MIN = Number(readArg('min', '44'));
const MIN_FINE = Number(readArg('min-fine', '24'));
const PASSES = readArg('widths', '')
  ? readArg('widths', '').split(',').map((w) => ({ width: Number(w), touch: true, min: MIN }))
  : [
      { width: 320, touch: true, min: MIN },
      { width: 390, touch: true, min: MIN },
      { width: 1280, touch: true, min: MIN },
      { width: 1280, touch: false, min: MIN_FINE },
    ];

/** يُنفَّذ في الصفحة: قائمة العناصر التفاعليّة ذات الهدف الأصغر من MIN. */
const probe = async (min) => {
  const half = min / 2 - 1;
  const vis = (el) => {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) === 0) return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };
  const label = (el) => (el.getAttribute('aria-label') || el.textContent || el.id || '').replace(/\s+/g, ' ').trim().slice(0, 40);
  const inSentence = (el) => {
    if (getComputedStyle(el).display !== 'inline') return false;
    const block = el.parentElement?.closest('p, li, dd, td, span, label');
    if (!block) return false;
    return (block.textContent ?? '').trim().length > (el.textContent ?? '').trim().length + 3;
  };
  const owner = (el) => (el.matches('input[type=checkbox], input[type=radio]') ? el.closest('label') ?? el : el);

  document.documentElement.style.scrollBehavior = 'auto';
  const scope = document.querySelector('main') ?? document.body;
  const els = [...scope.querySelectorAll('a[href], button, summary, [role=button], [role=radio], [role=tab], input[type=checkbox], input[type=radio], select')]
    .filter((el) => !el.closest('[inert], [aria-hidden=true]') && !el.disabled && vis(owner(el)) && !inSentence(el));

  const small = [];
  let measured = 0;
  let obscured = 0;
  for (const el of els) {
    const target = owner(el);
    /* فوريًّا: `scroll-behavior: smooth` كان يترك العنصر متحرّكًا لحظة القياس فتُخطئه النقاط كلّها. */
    target.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'instant' });
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(() => r())));
    const r = target.getBoundingClientRect();
    const cx = r.left + r.width / 2;
    const cy = r.top + r.height / 2;
    if (cy - half < 0 || cy + half > innerHeight || cx - half < 0 || cx + half > innerWidth) continue;
    /*
     * عنصرٌ لا يُصيبه مركزه نفسه ليس مرئيًّا للإصبع: محتوى مسطّحٍ مقصوص، أو
     * درجٌ مطويّ خارج الشاشة. يُعدّ «محجوبًا» ويُطبع، ولا يُقاس هدفه.
     */
    const self = document.elementFromPoint(cx, cy);
    if (!self || !(target.contains(self) || el.contains(self))) {
      obscured += 1;
      continue;
    }
    measured += 1;
    const misses = [];
    /*
     * خمس نقاطٍ على صليبٍ قطره MIN — قرصٌ لا مربّع: زرٌّ دائريّ قطره ‎44‎ هدفٌ
     * ‎44‎ (WCAG)، وزوايا المربّع خارج قرصه فلا تُحسب عليه. ولا يُقبل سلفٌ يحوي
     * العنصر إصابةً: الحاوية ليست الزرّ.
     */
    for (const [dx, dy] of [[0, 0], [-half, 0], [half, 0], [0, -half], [0, half]]) {
      {
        const hit = document.elementFromPoint(cx + dx, cy + dy);
        if (!hit || !(target.contains(hit) || el.contains(hit))) {
          const by = hit ? `${hit.tagName.toLowerCase()}.${String(hit.className).split(' ')[0].replace(/^.*__/, '')}` : 'خارج الصفحة';
          misses.push(`${dx > 0 ? '+' : dx < 0 ? '−' : '0'}${dy > 0 ? '+' : dy < 0 ? '−' : '0'}→${by}`);
        }
      }
    }
    if (misses.length) {
      small.push(`${el.tagName.toLowerCase()} «${label(el)}» ${Math.round(r.width)}×${Math.round(r.height)} · يفوته ${misses.join(' ')}`);
    }
  }
  return { measured, small, obscured };
};

const routes = await resolveRoutes();
const browser = await chromium.launch();
let measured = 0;
let breaches = 0;
let instrumentErrors = 0;
let infraFailures = 0;

for (const { width, touch, min } of PASSES) {
  console.log(`\n  ── ${width}px · ${touch ? 'لمس' : 'مؤشّر دقيق'} · الحدّ ${min}px`);
  const ctx = await browser.newContext({ viewport: { width, height: width < 700 ? 844 : 900 }, hasTouch: touch, isMobile: touch && width < 700 });
  await ctx.addCookies([{ name: 'tahdheer_consent', value: 'rejected', url: BASE }]);
  await ctx.addInitScript(() => {
    try {
      localStorage.setItem('tahdheer_wishlist', JSON.stringify([{ slug: 'dev-commerce-002', title: 'منتج تجريبي', priceMinor: 25000, currency: 'SAR' }]));
    } catch {
      /* التخزين محجوب — تُقاس الحالة الفارغة. */
    }
  });
  const page = await ctx.newPage();
  page.setDefaultNavigationTimeout(120000);

  /* سلّةٌ غير فارغة كي تُقاس سطورها وملخّصها ونموذج الدفع — لا حالتها الفارغة وحدها. */
  try {
    await gotoChecked(page, BASE, SEED, { waitUntil: 'load' });
    const add = page.getByRole('button', { name: ADD_TO_CART_NAME }).first();
    await add.waitFor({ timeout: 30000 });
    await add.click();
    await page.waitForTimeout(1500);
  } catch (e) {
    if (isTemporary(e)) infraFailures += 1;
    else instrumentErrors += 1;
    console.log(`  ⚠ تعذّر بذر السلّة — ${e.message.split('\n')[0]}`);
  }

  for (const route of routes) {
    try {
      await gotoChecked(page, BASE, route, { waitUntil: 'load' });
    } catch (e) {
      if (isTemporary(e)) {
        infraFailures += 1;
        console.log(`  ⛔ ${route.padEnd(34)} عطلُ بنية · ${e.message}`);
      } else {
        instrumentErrors += 1;
        console.log(`  ⚠ ${route.padEnd(34)} ${e.message}`);
      }
      continue;
    }
    await page.waitForTimeout(900);
    const r = await page.evaluate(probe, min);
    measured += r.measured;
    if (r.small.length) {
      breaches += r.small.length;
      console.log(`  × ${route.padEnd(34)} ${r.measured} هدفًا · ${r.small.length} أصغر من ${min}`);
      for (const s of r.small) console.log(`      ${s}`);
    } else {
      console.log(`  ✓ ${route.padEnd(34)} ${r.measured} هدفًا${r.obscured ? ` · ${r.obscured} محجوبًا لم يُقس` : ''}`);
    }
  }
  await ctx.close();
}

await browser.close();
const verdict = sweepVerdict({ measured, breaches, instrumentErrors, infraFailures });
console.log(`\n  ${routes.length} مسارًا × ${PASSES.length} تمريرات · ${measured} هدفًا قيس · ${breaches} دون حدّه`);
console.log(`  ${verdict.code === 0 ? '✓' : '×'} ${verdict.reason}\n`);
process.exit(verdict.code);
