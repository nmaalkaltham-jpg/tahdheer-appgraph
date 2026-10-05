/**
 * ماسح أسلاك ARIA — المراجع التي تشير إلى لا شيء.
 *
 * ─── العطل الذي وُلد منه ───
 *
 * حقل البحث في الترويسة `role="combobox"` ويحمل `aria-controls` يشير إلى
 * قائمة الاقتراحات. والقائمة لم تكن تُصيَّر إلّا وهي مفتوحة — أي أنّ المرجع
 * كان معلّقًا في **كلّ** صفحةٍ من المتجر وهي مغلقة، وتلك حالتها الطبيعيّة.
 * ومرجعٌ لا يُحلّ لا يقول لقارئ الشاشة شيئًا: لا «قائمة» ولا عددها.
 *
 * ولا يراه أيّ فحصٍ نصّيّ: السمة مكتوبة، والمعرّف مكتوب، والعنصر غائب.
 *
 * ─── وما يُتحقَّق منه ───
 *
 *  ١· كلّ `aria-controls`/`aria-labelledby`/`aria-describedby`/
 *     `aria-owns`/`aria-activedescendant` يشير إلى عنصرٍ قائم.
 *  ٢· `aria-expanded` يطابق ظهور ما يضبطه فعلًا.
 *  ٣· لا معرّف مكرّر — معرّفان متشابهان يجعلان كلّ مرجعٍ إليهما قمارًا.
 *  ٤· كلّ `<label for>` يجد حقله.
 *
 *   npm run sweep:aria
 */
import { chromium } from 'playwright';
import { readArg, resolveRoutes, gotoChecked } from './lib/storefront-routes.mjs';
import { sweepVerdict, describeLoadFailure } from './lib/sweep-verdict.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const WIDTHS = readArg('widths', '390,1440').split(',').map(Number);

const IDREF_ATTRS = [
  'aria-controls',
  'aria-labelledby',
  'aria-describedby',
  'aria-owns',
  'aria-activedescendant',
  'aria-details',
  'aria-errormessage',
];

const probe = (attrs) => {
  const name = (el) =>
    `${el.tagName.toLowerCase()}.${(el.className || '').toString().replace(/[a-z-]+-module__\w+__/g, '').split(' ')[0]}`;
  const out = [];

  for (const attr of attrs) {
    for (const el of document.querySelectorAll(`[${attr}]`)) {
      const raw = el.getAttribute(attr);
      if (raw === null || raw.trim() === '') continue;
      for (const id of raw.trim().split(/\s+/)) {
        if (document.getElementById(id) === null) {
          out.push(`${attr} معلّق · ${name(el)} → #${id}`);
        }
      }
    }
  }

  for (const el of document.querySelectorAll('[aria-expanded]')) {
    const id = el.getAttribute('aria-controls');
    if (id === null) continue;
    const target = document.getElementById(id);
    if (target === null) continue;
    const expanded = el.getAttribute('aria-expanded') === 'true';
    if (target.checkVisibility() !== expanded) {
      out.push(
        `aria-expanded=${expanded} والهدف ${target.checkVisibility() ? 'ظاهر' : 'مخفيّ'} · ${name(el)}`,
      );
    }
  }

  const seen = new Set();
  for (const el of document.querySelectorAll('[id]')) {
    const id = el.id;
    if (id === '') continue;
    if (seen.has(id)) out.push(`معرّف مكرّر · #${id}`);
    seen.add(id);
  }

  for (const label of document.querySelectorAll('label[for]')) {
    const id = label.getAttribute('for');
    if (id !== null && document.getElementById(id) === null) {
      out.push(`label[for] معلّق · #${id}`);
    }
  }

  return [...new Set(out)].slice(0, 8);
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
    const bad = await page.evaluate(probe, IDREF_ATTRS);
    checks += 1;

    if (bad.length > 0) {
      breaches += bad.length;
      console.log(`  × ${route}`);
      for (const b of bad) console.log(`      ${b}`);
    } else {
      console.log(`  ✓ ${route.padEnd(34)} أسلاك سليمة`);
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
