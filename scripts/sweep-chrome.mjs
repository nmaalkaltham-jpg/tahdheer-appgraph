/**
 * ماسح الحافّة السفلى — يثبت أنّ عقد `data-bottom-chrome` يصدق في المتصفّح.
 *
 * ─── لماذا ماسحٌ لا حارسٌ نصّيّ ───
 *
 * العطل الذي وُلد منه هذا الملفّ لم يكن يُرى في أيّ ملفّ: شريط الموافقة
 * الثابت يغطّي شريط الشراء الملتصق تغطيةً تامّة عند ‎390px‎. كلّ ملفٍّ على
 * حدة سليم، والتراكب لا يظهر إلّا حين تجتمع الطبقتان في صفحةٍ واحدة. فلا
 * يكشفه إلّا قياسٌ في متصفّحٍ حقيقيّ.
 *
 * ─── وما يُتحقَّق منه ───
 *
 *  1. لا طبقتان سفليّتان تتراكبان.
 *  2. كلّ طبقةٍ داخل الشاشة بتمامها (لا تُدفع خارج الحافّة بحجزٍ زائد).
 *  3. حجزُ نهاية المستند ≥ ما تقتطعه الطبقات الخارجة عن التدفّق.
 *  4. كلّ شريطٍ سفليٍّ في الصفحة يحمل السمة — شريطٌ بلا سمة لا يعرفه العقد.
 *
 * ويُشغَّل في حالتَي الموافقة معًا: «لم يقرّر» و«قرّر». والثانية هي الزائر
 * العائد — وهي الحالة التي سقط فيها الحجز كلّه في الدورة الخامسة عشرة.
 */
import { chromium } from 'playwright';
import { readArg, resolveRoutes, gotoChecked } from './lib/storefront-routes.mjs';
import { describeLoadFailure } from './lib/sweep-verdict.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const WIDTHS = readArg('widths', '320,390,768,1024,1440,2560').split(',').map(Number);

const probe = () => {
  const vh = innerHeight;
  const round = (n) => Math.round(n * 10) / 10;

  const declared = [];
  const undeclared = [];

  for (const el of document.querySelectorAll('*')) {
    const cs = getComputedStyle(el);
    const fixedish = cs.position === 'fixed' || cs.position === 'sticky';
    const role = el.getAttribute('data-bottom-chrome');
    if (!fixedish && role === null) continue;
    if (!el.checkVisibility()) continue;

    const r = el.getBoundingClientRect();
    if (r.height < 8 || r.width < 40) continue;

    const name = `${el.tagName.toLowerCase()}.${(el.className || '')
      .toString()
      .replace(/[a-z-]+-module__\w+__/g, '')
      .trim()
      .split(/\s+/)[0] || '—'}`;

    if (role !== null) {
      declared.push({ name, role, top: round(r.top), bottom: round(r.bottom), h: round(r.height) });
      continue;
    }
    /* شريطٌ غير معلن: يسكن النصف السفليّ ويمتدّ عرضًا — أي أنّه مرشّحٌ
       للتراكب مع المعلنَة. الترويسة الملتصقة في الأعلى ليست منه. */
    if (r.top > vh * 0.55 && r.width > innerWidth * 0.6 && r.bottom > vh - 24) {
      undeclared.push({ name, pos: cs.position, top: round(r.top), h: round(r.height) });
    }
  }

  const cs = getComputedStyle(document.documentElement);
  const num = (v) => parseFloat(v) || 0;

  return {
    vh,
    declared,
    undeclared,
    bodyPad: num(getComputedStyle(document.body).paddingBlockEnd),
    scrollPad: num(cs.scrollPaddingBlockEnd),
    base: num(cs.getPropertyValue('--chrome-bottom-base')),
    fixed: num(cs.getPropertyValue('--chrome-bottom-fixed')),
    sticky: num(cs.getPropertyValue('--chrome-bottom-sticky')),
  };
};

const routes = await resolveRoutes();
const browser = await chromium.launch();
const breaches = [];
let checks = 0;

/*
 * سياقٌ واحد لكلّ حالة موافقة، وصفحةٌ واحدة لكلّ مسار، ثمّ تغيير المقاس.
 * والبديل — سياقٌ جديد لكلّ قياس — كان يفرض ‎60‎ تحميلًا باردًا فيتجاوز
 * الماسحُ الدقائق الأربع على خادم التطوير.
 */
for (const consent of ['pending', 'decided']) {
  const ctx = await browser.newContext({ viewport: { width: WIDTHS[0], height: 844 } });
  if (consent === 'decided') {
    const host = new URL(BASE).hostname;
    await ctx.addCookies([{ name: 'tahdheer_consent', value: 'granted', domain: host, path: '/' }]);
  }
  const page = await ctx.newPage();

  for (const route of routes) {
    try {
      await gotoChecked(page, BASE, route, { waitUntil: 'networkidle', timeout: 45_000 });
    } catch (e) {
      breaches.push(`${route} ${consent} · ${describeLoadFailure(e)}`);
      continue;
    }

    /* الشريط العامّ يأتي بعد جلبٍ من الـAPI؛ وقياسٌ قبل وصوله يقيس صفحةً
       بلا شريط ويسمّيها سليمة. فيُنتظر صراحةً — ثمّ يُمضى إن لم يأتِ. */
    if (consent === 'pending') {
      await page
        .waitForSelector('[data-bottom-chrome="base"]', { timeout: 6000 })
        .catch(() => undefined);
    }

    for (const width of WIDTHS) {
      await page.setViewportSize({ width, height: 844 });
      await page.waitForTimeout(220);
      /* الملتصق لا يستقرّ في موضعه المثبَّت إلّا بعد تمرير — والحافّة
         القصوى تُخرجه منه، فالقياس في الوسط. */
      await page.evaluate(() => window.scrollTo(0, (document.documentElement.scrollHeight - innerHeight) * 0.5));
      await page.waitForTimeout(180);

      const m = await page.evaluate(probe);
      checks++;
      const where = `${route} @${width} ${consent}`;

      for (let i = 0; i < m.declared.length; i++) {
        const a = m.declared[i];
        if (a.bottom > m.vh + 1 || a.top < -1) {
          breaches.push(`${where} · «${a.name}» (${a.role}) خارج الشاشة: ${a.top}→${a.bottom} من ${m.vh}`);
        }
        for (let j = i + 1; j < m.declared.length; j++) {
          const b = m.declared[j];
          const over = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
          if (over > 1) {
            breaches.push(`${where} · تراكب ${Math.round(over)}px بين «${a.name}» (${a.role}) و«${b.name}» (${b.role})`);
          }
        }
      }

      const outflow = m.base + m.fixed;
      if (m.bodyPad + 1 < outflow) {
        breaches.push(`${where} · حشو نهاية المستند ${m.bodyPad}px دون ما تقتطعه الطبقات ${outflow}px`);
      }
      if (m.scrollPad + 1 < outflow + m.sticky) {
        breaches.push(`${where} · حشو التمرير ${m.scrollPad}px دون ${outflow + m.sticky}px`);
      }
      for (const u of m.undeclared) {
        breaches.push(`${where} · شريطٌ سفليٌّ بلا سمة: «${u.name}» ${u.pos} h=${u.h}`);
      }
    }
  }

  await ctx.close();
}

await browser.close();

console.log(`${routes.length} مسارًا · ${checks} قياسًا`);
if (breaches.length === 0) {
  console.log('بلا خرق');
} else {
  for (const b of breaches) console.log(`\u2717 ${b}`);
  console.log(`\n${breaches.length} خرقًا`);
  process.exit(1);
}
