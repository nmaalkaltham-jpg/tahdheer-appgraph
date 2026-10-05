/**
 * ماسح تقليل الحركة — ما يتحرّك فعلًا، لا ما هو مكتوبٌ في CSS.
 *
 * ─── الثغرة التي يسدّها ───
 *
 * فحصُ نصّ CSS يتحقّق أنّ لكلّ `@keyframes` كتلةَ
 * تعطيلٍ داخل `prefers-reduced-motion: reduce`، وأنّ ما يُخفى بانتظار حركة
 * يُكشف فيها. وهو فحصٌ نصّيّ لا يرى:
 *
 *  · نمطًا سطريًّا (`style={{ transition }}`) من مكوّن.
 *  · حركةً تبدؤها الشيفرة (Web Animations API).
 *  · قاعدةً في ملفٍّ آخر تُعيد تفعيل ما عُطِّل.
 *
 * والمتصفّح يعرف ما يتحرّك فعلًا: `document.getAnimations()` يردّ كلّ حركةٍ
 * قائمة — من CSS أو من الشيفرة.
 *
 * ─── وما لا يُعدّ خرقًا ───
 *
 * عقيدة المشروع (MIP §2.4) أنّ تقليل الحركة **يكشف ولا يجمّد فحسب**:
 * الحركة تقف، والإتاحة تبقى. فانتقالُ لونٍ أو حدٍّ ليس حركة، وقد كُتب ذلك
 * صراحةً في `ProductCard`: «البطاقة تُبقي حدّ التحويم وظلّه — تصميمٌ لا
 * حركة — وتفقد الارتفاع ‎2px‎ وحده». فالمقياس هنا الحركةُ القائمة، لا كلُّ
 * `transition-duration` مكتوب.
 *
 * ─── والوضع الطبيعيّ يُقاس أيضًا ───
 *
 * فحصٌ يمرّ لأنّ لا حركة في الحالتين لا يُثبت شيئًا. فيُقاس الوضعان معًا:
 * الطبيعيّ ليُعلم أنّ في الصفحة حركةً أصلًا، والمقلَّل ليُعلم أنّها وقفت.
 *
 *   npm run sweep:motion
 */
import { chromium } from 'playwright';
import { readArg, resolveRoutes, gotoChecked } from './lib/storefront-routes.mjs';
import { describeLoadFailure } from './lib/sweep-verdict.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const WIDTHS = readArg('widths', '390,1440').split(',').map(Number);
/** دون هذا الحدّ ليست حركةً يراها أحد (بالمللي ثانية). */
const FLOOR_MS = Number(readArg('floor', 20));

const probe = (floor) => {
  const running = [];
  for (const a of document.getAnimations()) {
    const timing = a.effect?.getTiming();
    const ms = typeof timing?.duration === 'number' ? timing.duration : 0;
    if (ms <= floor) continue;
    const target = a.effect && 'target' in a.effect ? a.effect.target : null;
    const name =
      ('animationName' in a && a.animationName) ||
      ('transitionProperty' in a && a.transitionProperty) ||
      '—';
    running.push({
      name: String(name),
      ms: Math.round(ms),
      state: a.playState,
      target: target
        ? `${target.tagName.toLowerCase()}.${(target.className || '').toString().replace(/[a-z-]+-module__\w+__/g, '').split(' ')[0]}`
        : '—',
    });
  }

  /* محتوًى مخفيٌّ بانتظار حركةٍ لن تأتي: العقيدة تقول يُكشف لا يُجمَّد. */
  const stranded = [];
  for (const el of document.querySelectorAll('main *')) {
    const cs = getComputedStyle(el);
    if ((el.textContent ?? '').trim().length === 0) continue;
    const invisible = parseFloat(cs.opacity) < 0.05;
    const collapsed = cs.maxBlockSize === '0px' || cs.maxHeight === '0px';
    if (!invisible && !collapsed) continue;
    const animated = cs.animationName !== 'none' || parseFloat(cs.transitionDuration) > 0;
    if (!animated) continue;
    stranded.push(
      `${el.tagName.toLowerCase()}.${(el.className || '').toString().replace(/[a-z-]+-module__\w+__/g, '').split(' ')[0]}`,
    );
    if (stranded.length > 4) break;
  }

  return { running, stranded };
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
let sawMotion = 0;

/*
 * سياقٌ واحد لكلّ (عرض × وضع)، وصفحةٌ تُعاد استعمالها.
 * والبديل — سياقٌ جديد لكلّ مسار — يفرض ‎144‎ تحميلةً باردة ويتجاوز الدقائق.
 */
for (const width of WIDTHS) {
  console.log(`\n  ── ${width}px`);
  const contexts = {};
  for (const mode of ['no-preference', 'reduce']) {
    const ctx = await browser.newContext({
      viewport: { width, height: 900 },
      reducedMotion: mode === 'reduce' ? 'reduce' : 'no-preference',
    });
    contexts[mode] = { ctx, page: await ctx.newPage() };
  }

  for (const route of routes) {
    const seen = {};
    for (const mode of ['no-preference', 'reduce']) {
      const { page } = contexts[mode];
      try {
        await gotoChecked(page, BASE, route, { waitUntil: 'networkidle', timeout: 45_000 });
      } catch (e) {
        instrumentErrors += 1;
        console.log(`  ⚠ ${route} · ${describeLoadFailure(e)}`);
        continue;
      }
      /* الحركة تبدأ عند الدخول، فيُقاس مرّتين: فورًا وبعد استقرار. */
      const early = await page.evaluate(probe, FLOOR_MS);
      await page.waitForTimeout(700);
      const late = await page.evaluate(probe, FLOOR_MS);
      seen[mode] = {
        running: [...early.running, ...late.running],
        stranded: [...new Set([...early.stranded, ...late.stranded])],
      };
    }

    const plain = seen['no-preference'] ?? { running: [], stranded: [] };
    const reduced = seen.reduce ?? { running: [], stranded: [] };
    if (plain.running.length > 0) sawMotion += 1;

    const bad = [];
    for (const a of reduced.running) bad.push(`حركة قائمة «${a.name}» ${a.ms}ms على ${a.target}`);
    for (const s of reduced.stranded) bad.push(`محتوًى مخفيٌّ بانتظار حركة: ${s}`);

    if (bad.length > 0) {
      breaches += bad.length;
      console.log(`  × ${route}`);
      for (const b of [...new Set(bad)]) console.log(`      ${b}`);
    } else {
      console.log(
        `  ✓ ${route.padEnd(34)} ${plain.running.length} حركة في الوضع الطبيعيّ · 0 عند التقليل`,
      );
    }
  }

  for (const mode of Object.keys(contexts)) await contexts[mode].ctx.close();
}

await browser.close();

console.log(`\n  ${routes.length} مسارًا · ${sawMotion} قياسًا رأى حركةً في الوضع الطبيعيّ`);
if (sawMotion === 0) {
  console.log('  ⚠ لم تُرصد حركةٌ في الوضع الطبيعيّ إطلاقًا — الفحص لا يُثبت شيئًا.');
}
console.log(
  `${breaches ? `  ${breaches} خرقًا` : '  بلا خرق'}` + `${instrumentErrors > 0 ? ` · ${instrumentErrors} عطلَ قياس` : ''}\n`,
);
process.exit(breaches > 0 || instrumentErrors > 0 ? 1 : 0);
