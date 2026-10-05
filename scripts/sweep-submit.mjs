/**
 * ماسح مسار الإرسال — ما يراه الزائر بعد أن يضغط «ادفع».
 *
 * ─── الثغرة التي يسدّها ───
 *
 * `sweep:forms` يقيس النموذج قبل الإرسال: الأسماء، والمطلوب، وربط الأخطاء.
 * وما بعد الضغط لم يُقس قطّ — لا خطأُ خادمٍ ‎422‎، ولا تعذّرٌ ‎500‎، ولا حالُ
 * الانتظار. وهي الحالات التي يقع فيها الزائر وقد دفع انتباهه كلّه.
 *
 * ─── ولا يُنشأ طلبٌ واحد ───
 *
 * نداء `POST /api/v1/checkout/orders` يُعترض ويُردّ عليه من هنا. الـAPI
 * الحقيقيّ لا يُبلَغ أصلًا، فلا طلب ولا دفعة ولا رسالة.
 *
 * ─── الحالات الثلاث ───
 *
 *  · `422` — رسالة الخادم تظهر على حقلها، ويُعلن الحقل خاطئًا، والملخّص
 *    ينبّه (`role="alert"`) ويعدّها.
 *  · `500` — بطاقةُ تعذّرٍ صادقة، لا صمت ولا نجاحٌ مزعوم.
 *  · بطيء — الزرّ يُعطَّل، ونصّه يتغيّر، والضغط مرّتين لا يرسل طلبين.
 *
 *   npm run sweep:submit
 */
import { chromium } from 'playwright';
import { readArg, gotoChecked, isTemporary } from './lib/storefront-routes.mjs';
import { ADD_TO_CART_NAME, INFRA_STATUSES, sweepVerdict } from './lib/sweep-verdict.mjs';

const BASE = readArg('base', 'http://127.0.0.1:3000');
const PRODUCT = readArg('seed-product', '/products/dev-commerce-002');
const WIDTHS = readArg('widths', '390,1440').split(',').map(Number);
const ORDERS = '**/api/v1/checkout/orders';

/*
 * زرّ الدفع لا أوّل زرّ إرسالٍ في الصفحة.
 *
 * أوّل صياغةٍ استعملت `button[type=submit]` فأصابت زرّ البحث في الترويسة،
 * فانتقلت الصفحة إلى `/search` وأبلغ الماسح عن ثمانية أعطالٍ لا وجود لها.
 * والتحديد هنا داخل النموذج الذي يحمل حقل الاسم، والمرئيّ منه.
 */
const CTA = 'form:has(#field-name) button[type=submit]';

const ctaState = () => {
  const b = [...document.querySelectorAll('form:has(#field-name) button[type=submit]')].find((e) =>
    e.checkVisibility(),
  );
  return { disabled: b?.hasAttribute('disabled') === true, label: (b?.textContent ?? '').trim() };
};

/*
 * خطأُ الأداة يُعلَن خطأَ أداة — لا عطلًا في المنتج.
 *
 * في أوّل تشغيلٍ لهذا الماسح أصاب المحدّدُ زرَّ البحث في الترويسة فانتقلت
 * الصفحة، فأبلغ عن ثمانية أعطالٍ لا وجود لها. والبلاغ الكاذب أسوأ من
 * الصمت: يقود إلى «إصلاح» شيفرةٍ سليمة. فكلّ عنصرٍ يلمسه الماسح يُتحقّق
 * أنّه المقصود، وإن لم يكن توقّف الحالةُ بوصفها عطلَ قياسٍ لا عطلَ منتج.
 */
let instrumentErrors = 0;
/* عدّاداتُ ما قيس فعلًا — تُطبع كلّها، وصفرُها يُسقط الفحص (lib/sweep-verdict). */
let pagesScanned = 0;
let controlsDiscovered = 0;
let controlsExercised = 0;
let infraFailures = 0;
const infra = (message) => {
  infraFailures += 1;
  console.log(`  ⛔ عطلُ بنية · ${message}`);
};
const instrument = (message) => {
  instrumentErrors += 1;
  console.log(`  ⚠ عطلُ قياس · ${message}`);
};

const fill = async (page) => {
  await page.fill('#field-name', 'هشام الكلثم');
  await page.fill('#field-phone', '512345678');
  await page.fill('#field-email', 'a@b.com');
  /*
   * مربّع الموافقة على السياسات بنصّه لا بترتيبه.
   *
   * كان `input[type=checkbox]` الأوّل — وهو يصيب المقصود بحكم ترتيب
   * الشيفرة وحده. ولو سبقه مربّع التسويق يومًا لبقي النموذج غير صالح،
   * فلا يُرسل، ولا تُقاس حالةٌ واحدة — ويمرّ الفحص أخضر بلا معنى.
   */
  const consent = page
    .locator('label:has(input[type=checkbox])', { hasText: /أوافق/ })
    .locator('input[type=checkbox]')
    .first();
  if ((await consent.count()) === 0) {
    instrument('لم يُعثر على مربّع الموافقة على السياسات');
    return false;
  }
  if (!(await consent.isChecked())) await consent.check();
  return true;
};

/**
 * `null` = خنقُ ‎429‎ رغم إعادة المحاولة. لا يُتخطّى بصمت: يُعدّ عطلَ بنية
 * (رمز خروج ‎2‎) — فالحالة لم تُقس، وقولُ «بلا خرق» عنها كذب.
 */
const ready = async (page) => {
  try {
    return await seed(page);
  } catch (e) {
    if (isTemporary(e)) {
      infra(`${e.message} — لم تُقس حالة`);
      return null;
    }
    /* مهلةٌ أو تنقّلٌ مقطوع: عطلُ قياسٍ مُعلَن لا انهيارٌ يُخفي ما قيس قبله. */
    instrument(`تعذّر التجهيز — ${String(e.message).split('\n')[0]}`);
    return undefined;
  }
};

/**
 * تجهيزُ حالةٍ واحدة، وكلُّ إخفاقٍ فيه مُعلَنٌ باسمه. كانت حالتا ‎500‎ والانتظار
 * تكتفيان بـ`continue` عند فشل البذر — فتسقطان من العدّ بلا أثر.
 */
const prepare = async (page) => {
  const seeded = await ready(page);
  if (seeded) return true;
  if (seeded === false) {
    if (apiAlive) instrument('تعذّر تجهيز الدفع والـAPI يعمل — لم تُقس حالة');
    else infra('لا خادم خلفيّ يُجيب — لم تُقس حالة');
  }
  return false;
};
const seed = async (page) => {
  /* `networkidle` لا يستقرّ على خادم تطويرٍ مقابسُ HMR فيه مفتوحة — يُنتظر السطح نفسه. */
  await gotoChecked(page, BASE, PRODUCT, { waitUntil: 'load', timeout: 90_000 });
  pagesScanned += 1;
  await page.getByRole('button', { name: ADD_TO_CART_NAME }).first().waitFor({ timeout: 30_000 }).catch(() => undefined);
  /*
   * زرّ الإضافة بدوره واسمه المتاح لا بنصٍّ حرفيّ: «أضف إلى السلة» في صفحة
   * المنتج و«أضف للسلة …» في البطاقة (ADD_TO_CART_NAME). نصٌّ واحد كان يقيس
   * صفر حالات ويخرج أخضر.
   */
  const adds = page.getByRole('button', { name: ADD_TO_CART_NAME });
  const visible = [];
  for (const b of await adds.all()) if (await b.isVisible()) visible.push(b);
  controlsDiscovered += visible.length;
  if (visible.length === 0) {
    instrument(`لا زرّ «أضف إلى السلة» مرئيّ في ${PRODUCT}`);
    return undefined;
  }
  const added = page.waitForResponse((r) => r.url().includes('/api/v1/cart/items') && r.request().method() === 'POST', { timeout: 15000 }).catch(() => null);
  await visible[0].click({ timeout: 8000 });
  controlsExercised += 1;
  const addRes = await added;
  if (addRes && INFRA_STATUSES.has(addRes.status())) {
    const err = new Error(`POST /cart/items ردّ ${addRes.status()}`);
    err.temporary = true;
    throw err;
  }
  await page.waitForTimeout(1200);
  await gotoChecked(page, BASE, '/checkout', { waitUntil: 'load', timeout: 90_000 });
  pagesScanned += 1;
  await page.locator('#field-name').waitFor({ timeout: 30_000 }).catch(() => undefined);
  /* النموذج موجودٌ = السلّة غير فارغة. وغيابه يعني أنّ البذر لم يقع، لا
     أنّ الدفع معطوب — فيُفرَّق بينهما عند البلاغ. */
  return (await page.locator('#field-name').count()) > 0;
};

/*
 * غيابُ الـAPI تخطٍّ صادق، وفشلُ البذر مع وجوده عطلُ قياس.
 *
 * والفرق ليس شكليًّا: في CI لا خادم خلفيّ فلا شيء يُقاس هنا وهذا متوقَّع؛
 * وعلى جهازٍ فيه خادمٌ يعمل، فشلُ البذر يعني أنّ الماسح لم يقس شيئًا وهو
 * يظنّ أنّه قاس. وقد وقع ذلك فعلًا: ثلاثة ماسحاتٍ متتابعة أنهكت الـAPI
 * (‎429‎)، فعاد هذا بصفر حالات — ولولا هذا الفحص لقال «بلا خرق».
 */
const apiAlive = await fetch(readArg('api', 'http://127.0.0.1:58080') + '/api/v1/products?perPage=1', {
  signal: AbortSignal.timeout(4000),
})
  .then((r) => r.ok)
  .catch(() => false);

const browser = await chromium.launch();
let breaches = 0;
let cases = 0;

for (const width of WIDTHS) {
  console.log(`\n  ── ${width}px`);

  /* ─── ‎422‎: خطأ تحقّقٍ من الخادم ─────────────────────────────────── */
  {
    const ctx = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    if (!(await prepare(page))) {
      await ctx.close();
      continue;
    }
    await ctx.route(ORDERS, (route) =>
      route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'تحقّق من الحقول', errors: { email: ['هذا البريد مرفوض من مزوّدنا'] } }),
      }),
    );
    if (!(await fill(page))) {
      await ctx.close();
      continue;
    }
    await page.locator(`${CTA}:visible`).first().click();
    controlsExercised += 1;
    await page.waitForTimeout(1200);
    cases += 1;

    const m = await page.evaluate(() => {
      const email = document.getElementById('field-email');
      const ids = (email?.getAttribute('aria-describedby') ?? '').trim().split(/\s+/).filter(Boolean);
      const described = ids.map((id) => document.getElementById(id)?.textContent ?? '').join(' ');
      /* التنبيه داخل نموذج الدفع لا أيّ تنبيهٍ في الصفحة. */
      const alert = document.querySelector('form:has(#field-name) [role=alert]');
      return {
        invalid: email?.getAttribute('aria-invalid'),
        described: described.trim(),
        alertText: (alert?.textContent ?? '').trim().slice(0, 60),
        stillOnForm: document.getElementById('field-email') !== null,
      };
    });

    const bad = [];
    if (!m.stillOnForm) bad.push('غادر النموذج بعد ‎422‎');
    if (m.invalid !== 'true') bad.push('الحقل لا يُعلن أنّه خاطئ بعد ردّ الخادم');
    if (!m.described.includes('مرفوض')) bad.push(`رسالة الخادم لا تصل الحقل — الوصف «${m.described.slice(0, 40)}»`);
    if (m.alertText === '') bad.push('لا ملخّص منبّه (role=alert) بعد ‎422‎');

    if (bad.length > 0) {
      breaches += bad.length;
      console.log('  × ‎422‎');
      for (const b of bad) console.log(`      ${b}`);
    } else {
      console.log(`  ✓ ‎422‎ · الرسالة على حقلها · ملخّصٌ ينبّه «${m.alertText.slice(0, 28)}…»`);
    }
    await ctx.close();
  }

  /* ─── ‎500‎: تعذّرٌ من الخادم ─────────────────────────────────────── */
  {
    const ctx = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    if (!(await prepare(page))) {
      await ctx.close();
      continue;
    }
    await ctx.route(ORDERS, (route) =>
      route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"boom"}' }),
    );
    if (!(await fill(page))) {
      await ctx.close();
      continue;
    }
    await page.locator(`${CTA}:visible`).first().click();
    controlsExercised += 1;
    await page.waitForTimeout(1200);
    cases += 1;

    const m = await page.evaluate(() => {
      const main = document.querySelector('main');
      const text = (main?.innerText ?? '').trim();
      return {
        declares: /تعذّر|أعد المحاولة|لم نتمكّن|فشل/.test(text),
        actions: [...(main?.querySelectorAll('a,button') ?? [])].filter(
          (e) => (e.textContent ?? '').trim() !== '' && !e.hasAttribute('disabled'),
        ).length,
        stillOnForm: document.getElementById('field-email') !== null,
      };
    });

    const bad = [];
    if (!m.stillOnForm) bad.push('غادر النموذج بعد ‎500‎');
    if (!m.declares) bad.push('لا يُعلن التعذّر بعد ‎500‎ — صمتٌ بعد ضغطة');
    if (m.actions === 0) bad.push('يُعلن التعذّر بلا مخرج');

    if (bad.length > 0) {
      breaches += bad.length;
      console.log('  × ‎500‎');
      for (const b of bad) console.log(`      ${b}`);
    } else {
      console.log(`  ✓ ‎500‎ · يُعلن التعذّر · ${m.actions} فعلًا متاحًا`);
    }
    await ctx.close();
  }

  /* ─── الانتظار: تعطيلٌ، ونصٌّ يتغيّر، وضغطتان بطلبٍ واحد ────────── */
  {
    const ctx = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    if (!(await prepare(page))) {
      await ctx.close();
      continue;
    }
    let posts = 0;
    await ctx.route(ORDERS, async (route) => {
      posts += 1;
      await new Promise((ok) => setTimeout(ok, 2500));
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"slow"}' });
    });
    if (!(await fill(page))) {
      await ctx.close();
      continue;
    }
    const before = (await page.evaluate(ctaState)).label;
    const cta = page.locator(`${CTA}:visible`).first();
    await cta.click();
    controlsExercised += 1;
    await page.waitForTimeout(500);
    const during = await page.evaluate(ctaState);
    /* ضغطةٌ ثانية أثناء الطيران — يجب ألّا تُنتج طلبًا ثانيًا. */
    await cta.click({ force: true, timeout: 3000 }).catch(() => undefined);
    await page.waitForTimeout(2600);
    cases += 1;

    const bad = [];
    if (!during.disabled) bad.push('الزرّ يبقى قابلًا للضغط أثناء الإرسال');
    if (during.label === before) bad.push(`نصّ الزرّ لا يتغيّر أثناء الإرسال («${before.slice(0, 22)}»)`);
    if (posts > 1) bad.push(`ضغطتان أنتجتا ${posts} طلبًا`);

    if (bad.length > 0) {
      breaches += bad.length;
      console.log('  × الانتظار');
      for (const b of bad) console.log(`      ${b}`);
    } else {
      console.log(`  ✓ الانتظار · معطَّل · «${during.label.slice(0, 20)}» · ${posts} طلب من ضغطتين`);
    }
    await ctx.close();
  }
}

await browser.close();
const verdict = sweepVerdict({ measured: cases, breaches, instrumentErrors, infraFailures });
console.log(
  `\n  صفحاتٌ مُسحت ${pagesScanned} · أزرارٌ اكتُشفت ${controlsDiscovered} · أزرارٌ ضُغطت ${controlsExercised}` +
    ` · حالاتٌ قيست ${cases} · خروق ${breaches} · أعطال قياس ${instrumentErrors} · أعطال بنية ${infraFailures}`,
);
console.log(`  ${verdict.code === 0 ? '✓' : '×'} ${verdict.reason}\n`);
process.exit(verdict.code);
