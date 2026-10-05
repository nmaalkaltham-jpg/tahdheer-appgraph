/**
 * ماسح النماذج — الحقل الذي يرفض الفراغ يجب أن يقول إنّه مطلوب.
 *
 * ─── العطل الذي وُلد منه ───
 *
 * نموذج الدفع يتحقّق من الاسم والجوّال والبريد والموافقة، ويرفض المتابعة
 * بدونها. ولم يكن أيٌّ منها يحمل `required` ولا `aria-required`. فقارئ
 * الشاشة يعلنها اختياريّةً حتّى يُخطئ صاحبها ويعود. والعلامة نصفُ ‎§3.3.1‎:
 * أن يُعرف الشرط قبل الخطأ لا بعده.
 *
 * ─── وكيف يُعرف «مطلوب» بلا معرفة قواعد التطبيق ───
 *
 * بالسلوك: يُركَّز على الحقل ويُترك فارغًا، فإن ظهر له خطأ فهو مطلوب. ولا
 * يُرسَل نموذج قطّ — لا طلب ولا رسالة ولا اشتراك. المسُّ هنا تركيزٌ وخروج.
 *
 * ─── وما يُقاس أيضًا ───
 *
 *  · اسمٌ متاح لكلّ حقل مرئيّ (لا `placeholder` وحده).
 *  · `aria-invalid="true"` يصحبه وصفٌ غير فارغ يشرح الخطأ.
 *  · حقول البيانات الشخصيّة تحمل `autocomplete` — ‎§1.3.5‎.
 *
 *   npm run sweep:forms
 */
import { chromium } from 'playwright';
import { readArg, resolveRoutes, gotoChecked } from './lib/storefront-routes.mjs';
import { sweepVerdict } from './lib/sweep-verdict.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const WIDTHS = readArg('widths', '390,1440').split(',').map(Number);
const SEED_PRODUCT = readArg('seed-product', '');
const SEED_CART = !process.argv.includes('--no-seed');

const audit = () => {
  /* حقول تجمع بيانات صاحبها — ‎§1.3.5‎ يطلب لها `autocomplete`. ويُعرَّف
     داخل الدالّة: ما يُنفَّذ في المتصفّح لا يرى نطاق Node. */
  const PERSONAL = /^(email|tel)$/;
  const name = (el) => `${el.tagName.toLowerCase()}[${el.type ?? ''}]#${el.id || '—'}`;
  const accessibleName = (el) => {
    if ((el.getAttribute('aria-label') ?? '').trim() !== '') return true;
    const lb = el.getAttribute('aria-labelledby');
    if (lb !== null && lb.trim().split(/\s+/).every((id) => document.getElementById(id) !== null)) return true;
    if (el.id !== '' && document.querySelector(`label[for="${CSS.escape(el.id)}"]`) !== null) return true;
    if (el.closest('label') !== null) return true;
    return false;
  };

  const out = [];
  const fields = [...document.querySelectorAll('input:not([type=hidden]), select, textarea')].filter(
    (el) => el.checkVisibility(),
  );

  for (const el of fields) {
    if (!accessibleName(el)) out.push(`حقلٌ بلا اسمٍ متاح · ${name(el)}`);

    if (PERSONAL.test(el.type ?? '') && (el.getAttribute('autocomplete') ?? '') === '') {
      out.push(`‎§1.3.5‎ بلا autocomplete · ${name(el)}`);
    }

    if (el.getAttribute('aria-invalid') === 'true') {
      const ids = (el.getAttribute('aria-errormessage') ?? el.getAttribute('aria-describedby') ?? '')
        .trim()
        .split(/\s+/)
        .filter(Boolean);
      const text = ids
        .map((id) => document.getElementById(id)?.textContent ?? '')
        .join(' ')
        .trim();
      if (text === '') out.push(`معلَنٌ خاطئًا بلا وصفٍ يشرح · ${name(el)}`);
    }
  }

  return { out: [...new Set(out)], count: fields.length };
};

/** يُركَّز على كلّ حقلٍ نصّيّ فارغ ثمّ يُترك — بلا إرسال. */
const blurAll = () => {
  const fields = [...document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), textarea')].filter(
    (el) => el.checkVisibility() && el.value === '',
  );
  for (const el of fields) {
    el.focus();
    el.blur();
  }
  return fields.length;
};

const requiredGap = () => {
  const name = (el) => `${el.tagName.toLowerCase()}[${el.type ?? ''}]#${el.id || '—'}`;
  const out = [];
  for (const el of document.querySelectorAll('input:not([type=hidden]), select, textarea')) {
    if (!el.checkVisibility()) continue;
    if (el.getAttribute('aria-invalid') !== 'true') continue;
    if ('value' in el && el.value !== '') continue;
    const marked = el.required || el.getAttribute('aria-required') === 'true';
    if (!marked) out.push(`فارغٌ ويُرفض ولا يُعلن أنّه مطلوب · ${name(el)}`);
  }
  return [...new Set(out)];
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
let fieldsSeen = 0;

for (const width of WIDTHS) {
  console.log(`\n  ── ${width}px`);
  const ctx = await browser.newContext({ viewport: { width, height: 900 } });
  const page = await ctx.newPage();

  if (SEED_CART) {
    /*
     * البذر ينجح أو يُعلَن فاشلًا — لا صمت بينهما.
     *
     * أوّل صياغةٍ التقطت أخطاء الانتقال وحدها، فمسارُ منتجٍ لا وجود له كان
     * يمرّ بلا استثناء: الزرّ غائب، والسلّة فارغة، وصفحةُ الدفع تُصيّر حالتها
     * الفارغة — فيمرّ الفحص أخضر على صفحةٍ لم يُقس فيها شيء. والخضرةُ على
     * لا شيء أسوأ من الحمرة على شيء.
     */
    const product = SEED_PRODUCT !== '' ? SEED_PRODUCT : routes.find((r) => r.startsWith('/products/'));
    if (product === undefined) {
      instrumentErrors += 1;
      console.log('  ⚠ عطلُ قياس · لا مسار منتجٍ للبذر — نموذج الدفع لا يُقاس');
    } else {
      let seeded = false;
      /*
       * دورةٌ كاملة تُعاد: افتح المنتج · انقر · تحقّق من ظهور النموذج.
       *
       * إعادةُ الانتقال وحدها لا تُصلح سلّةً لم تُبذر: إن وقع النقر قبل
       * ترطيب الصفحة فلا طلب يُرسَل أصلًا، فتبقى السلّة فارغة مهما أُعيد
       * فتحُ ‎/checkout‎. ورُصد التذبذب فعلًا بين تشغيلين على النسق نفسه.
       * فالمُعاد هو الدورة كاملةً، والنقر يسبقه انتظارُ زرٍّ مرئيٍّ مُمكَّن.
       *
       * والمُطابِق بالاسم المتاح لا بالنصّ الظاهر: عند ‎390px‎ يُصيَّر زرّ
       * الشريط اللاصق أيقونةً بلا نصّ واسمُه في `aria-label` وحده
       * (PurchaseBar.tsx). ولا `\b` في التعبير — حدود الكلمة في JS مبنيّة
       * على `[A-Za-z0-9_]` والحرف العربيّ ليس منها. و«سلة» لا «السلة»:
       * الصيغة القصيرة «أضف للسلة» تُدغم لام التعريف.
       */
      for (let attempt = 0; attempt < 3 && !seeded; attempt += 1) {
        try {
          await gotoChecked(page, BASE, product, { waitUntil: 'networkidle', timeout: 45_000 });
          const add = page
            .getByRole('button', { name: /^\s*أضف\s.*سلة\s*$/ })
            .locator('visible=true')
            .first();
          await add.waitFor({ state: 'visible', timeout: 15_000 });
          await page.waitForTimeout(400);
          /*
           * الانتقال إلى ‎/checkout‎ قبل وصول ردّ الإضافة يُجهض الطلب: على بناء
           * الإنتاج السريع تُفتح صفحة الدفع والسلّة فارغة (رُصد بمقارنة انتظار ‎0‎
           * و‎400‎ ms). فالبذر ينتظر ردّ ‎POST /cart/items‎ ويشترط نجاحه، كما في
           * ‎sweep-disclosure.mjs‎.
           */
          const [res] = await Promise.all([
            page.waitForResponse(
              (r) => r.url().includes('/api/v1/cart/items') && r.request().method() === 'POST',
              { timeout: 10_000 },
            ),
            add.click({ timeout: 8000 }),
          ]);
          if (!res.ok()) throw new Error(`POST /cart/items ${res.status()}`);
          await gotoChecked(page, BASE, '/checkout', { waitUntil: 'networkidle', timeout: 45_000 });
          await page.locator('#field-name').waitFor({ state: 'attached', timeout: 15_000 });
          seeded = true;
        } catch {
          seeded = false;
        }
      }
      if (!seeded) {
        instrumentErrors += 1;
        console.log(`  ⚠ عطلُ قياس · لم تُبذر السلّة من «${product}» — نموذج الدفع لا يُقاس`);
      }
    }
  }

  for (const route of routes) {
    try {
      await gotoChecked(page, BASE, route, { waitUntil: 'networkidle', timeout: 45_000 });
    } catch {
      instrumentErrors += 1;
      console.log(`  ⚠ عطلُ قياس · تعذّر تحميل ${route}`);
      continue;
    }
    await page.waitForTimeout(250);

    const first = await page.evaluate(audit);
    if (first.count === 0) {
      console.log(`  · ${route.padEnd(34)} لا حقل`);
      continue;
    }
    fieldsSeen += first.count;

    await page.evaluate(blurAll);
    await page.waitForTimeout(250);
    const second = await page.evaluate(audit);
    const gaps = await page.evaluate(requiredGap);

    const bad = [...new Set([...first.out, ...second.out, ...gaps])];
    if (bad.length > 0) {
      breaches += bad.length;
      console.log(`  × ${route}`);
      for (const b of bad) console.log(`      ${b}`);
    } else {
      console.log(`  ✓ ${route.padEnd(34)} ${first.count} حقلًا · قيست فارغةً`);
    }
  }
  await ctx.close();
}

await browser.close();
console.log(
  `\n  ${routes.length} مسارًا · ${fieldsSeen} حقلًا · ${breaches ? `${breaches} خرقًا` : 'بلا خرق'}` +
    `${instrumentErrors > 0 ? ` · ${instrumentErrors} عطلَ قياس` : ''}\n`,
);
/* عطلُ القياس يُسقط الفحص: نتيجةٌ خضراء على صفحاتٍ لم تُقس ليست نتيجة. */
/* صفرُ قياسٍ ليس نجاحًا (lib/sweep-verdict) — كان يخرج بـ0 حين لا يُقاس شيء. */
const verdict = sweepVerdict({ measured: fieldsSeen, breaches, instrumentErrors });
if (verdict.code !== 0) console.log(`  × ${verdict.reason}\n`);
process.exit(verdict.code);
